<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\PartnerProspect;
use App\Services\EventStore;
use App\Services\FeatureFlag;
use App\Services\Messaging\MessageDispatchService;
use App\Services\Messaging\TenantMailerFactory;
use App\Services\Outreach\Bot\OutreachReplyService;
use App\Services\Outreach\Import\ProspectImportService;
use App\Services\Outreach\OutreachSender;
use App\Services\Outreach\OutreachSettings;
use App\Services\Outreach\ProspectStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Partner outreach (affiliate recruitment) for the sales-crm pack: prospect
 * list + import, contact entry, the operator DM queue, tenant settings and a
 * manual tick. Everything is tenant-scoped through the resolved tenant.
 */
class PartnerProspectController extends Controller
{
    public function __construct(
        private readonly ProspectImportService $importer,
        private readonly ProspectStateMachine $lifecycle,
        private readonly OutreachSettings $settings,
        private readonly OutreachSender $sender,
        private readonly TenantMailerFactory $mailers,
        private readonly EventStore $events,
        private readonly MessageDispatchService $dispatch,
        private readonly OutreachReplyService $replies,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $query = PartnerProspect::forTenant($tenant->id)->with('lead:id,name,email,stage');

        if ($request->filled('status')) {
            $query->whereIn('status', array_filter(explode(',', (string) $request->query('status'))));
        }
        if ($request->filled('platform')) {
            $query->where('platform', (string) $request->query('platform'));
        }
        if ($request->has('has_email')) {
            $has = $request->boolean('has_email');
            $query->whereHas('lead', fn ($q) => $has ? $q->whereNotNull('email') : $q->whereNull('email'));
        }
        if ($request->boolean('needs_human')) {
            $query->whereNotNull('needs_human_at');
        }
        if ($request->filled('q')) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $request->query('q')).'%';
            $query->where(fn ($w) => $w->where('display_name', 'like', $term)->orWhere('handle', 'like', $term)->orWhere('profile_url', 'like', $term));
        }

        $page = $query->orderByDesc('created_at')->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return response()->json(['data' => $page, 'summary' => $this->summary((string) $tenant->id)]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $prospect = PartnerProspect::forTenant($tenant->id)->with('lead')->findOrFail($id);
        $conversations = Conversation::forTenant($tenant->id)->where('lead_id', $prospect->lead_id)->with('messages')->get();

        return response()->json(['data' => $prospect, 'conversations' => $conversations]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $prospect = PartnerProspect::forTenant($tenant->id)->with('lead')->findOrFail($id);

        $validated = $request->validate([
            'email' => 'sometimes|nullable|email|max:255',
            'display_name' => 'sometimes|nullable|string|max:190',
            'notes' => 'sometimes|nullable|string|max:5000',
        ]);

        if (array_key_exists('email', $validated) && filled($validated['email'])) {
            $result = $this->lifecycle->acceptEmail($prospect, (string) $validated['email'], 'operator');
            if (! $result['ok']) {
                return response()->json(['message' => 'Email rejected: '.$result['reason'], 'reason' => $result['reason']], 422);
            }
        }

        $prospect->fill(array_intersect_key($validated, array_flip(['display_name', 'notes'])))->save();

        return response()->json(['data' => $prospect->refresh()->load('lead')]);
    }

    public function import(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'file' => 'required|file|max:5120|mimes:csv,txt',
            'source' => 'sometimes|in:csv,finder,manual',
            'dry_run' => 'sometimes|boolean',
        ]);

        $report = $this->importer->importCsv(
            (string) $tenant->id,
            $validated['file']->getRealPath(),
            (bool) ($validated['dry_run'] ?? false),
            (string) ($validated['source'] ?? 'csv'),
        );

        return response()->json(['data' => $report->toArray(), 'summary' => $this->summary((string) $tenant->id)]);
    }

    /** Operator-assisted DMs waiting to be copied into the social app. */
    public function dmQueue(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $messages = ConversationMessage::forTenant($tenant->id)
            ->where('status', 'awaiting_operator')
            ->whereHas('conversation', fn ($q) => $q->where('channel', MessageDispatchService::MANUAL_DM))
            ->with('conversation.lead')
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        $prospects = PartnerProspect::forTenant($tenant->id)
            ->whereIn('lead_id', $messages->pluck('conversation.lead_id')->filter()->unique()->all())
            ->get()->keyBy('lead_id');

        $items = $messages->map(function (ConversationMessage $m) use ($prospects) {
            $prospect = $prospects->get($m->conversation?->lead_id);

            return [
                'message_id' => $m->id,
                'body' => $m->body,
                'template_key' => $m->template_key,
                'created_at' => $m->created_at?->toIso8601String(),
                'prospect' => $prospect ? [
                    'id' => $prospect->id, 'display_name' => $prospect->display_name, 'handle' => $prospect->handle,
                    'platform' => $prospect->platform, 'profile_url' => $prospect->profile_url, 'status' => $prospect->status,
                ] : null,
            ];
        })->values();

        return response()->json(['data' => $items]);
    }

    /** The human sent the DM in the app: record it and advance the prospect. */
    public function markDmSent(Request $request, string $messageId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $message = ConversationMessage::forTenant($tenant->id)->with('conversation')->findOrFail($messageId);

        if ($message->status !== 'awaiting_operator') {
            return response()->json(['message' => 'This draft is no longer awaiting the operator.'], 409);
        }

        $message->update(['status' => 'sent', 'sent_at' => now(), 'sent_by' => (string) $request->user()->id]);
        $message->conversation?->update(['last_message_at' => now()]);

        $prospect = PartnerProspect::forTenant($tenant->id)->where('lead_id', $message->conversation?->lead_id)->first();
        if ($prospect) {
            $this->lifecycle->transition($prospect, PartnerProspect::STATUS_DM_SENT, ['dm_sent_at' => now(), 'last_sent_at' => now()], [
                PartnerProspect::STATUS_DM_DRAFTED, PartnerProspect::STATUS_NEEDS_EMAIL, PartnerProspect::STATUS_DM_SENT,
            ]);
        }

        $this->events->append((string) $tenant->id, 'conversation', (string) $message->conversation_id, 'conversation.message.sent', [
            'lead_id' => $message->conversation?->lead_id, 'channel' => MessageDispatchService::MANUAL_DM, 'message_id' => $message->id, 'sent_by' => 'operator',
        ]);

        return response()->json(['data' => $message->refresh(), 'prospect' => $prospect?->refresh()]);
    }

    /** The creator answered in the app; the operator pastes the reply here. */
    public function dmReply(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $prospect = PartnerProspect::forTenant($tenant->id)->with('lead')->findOrFail($id);
        $validated = $request->validate(['body' => 'required|string|max:5000']);

        $conversation = Conversation::firstOrCreate(
            ['tenant_id' => $tenant->id, 'lead_id' => $prospect->lead_id, 'channel' => MessageDispatchService::MANUAL_DM],
            ['status' => 'open'],
        );

        $message = ConversationMessage::create([
            'tenant_id' => $tenant->id, 'conversation_id' => $conversation->id, 'direction' => 'in',
            'body' => $validated['body'], 'status' => 'received', 'classification' => 'reply', 'sent_by' => 'operator_paste',
        ]);
        $conversation->update(['status' => 'pending', 'last_message_at' => now()]);

        $this->lifecycle->markReplied($prospect);

        // bridge=laravel_outreach keeps this away from the Python CRM agent; the
        // recruiter loop (later PR) picks it up from here.
        $this->events->append((string) $tenant->id, 'conversation', (string) $conversation->id, 'conversation.message.received', [
            'lead_id' => $prospect->lead_id, 'channel' => MessageDispatchService::MANUAL_DM, 'message_id' => $message->id,
            'prospect_id' => $prospect->id, 'bridge' => 'laravel_outreach',
        ]);

        return response()->json(['data' => $message, 'prospect' => $prospect->refresh()], 201);
    }

    /**
     * A human reply in the prospect's thread: email goes out through the
     * tenant mailbox with threading headers; manual_dm becomes a queue draft.
     */
    public function reply(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $prospect = PartnerProspect::forTenant($tenant->id)->with('lead')->findOrFail($id);
        $validated = $request->validate([
            'body' => 'required|string|max:10000',
            'channel' => 'sometimes|in:email,manual_dm',
            'subject' => 'sometimes|nullable|string|max:255',
        ]);
        $channel = (string) ($validated['channel'] ?? 'email');
        $sentBy = (string) $request->user()->id;

        if ($channel === MessageDispatchService::MANUAL_DM) {
            $lead = $prospect->lead;
            if ($lead === null) {
                return response()->json(['message' => 'Prospect has no lead.'], 422);
            }
            $result = $this->dispatch->send($lead, MessageDispatchService::MANUAL_DM, $validated['body'], null, null, $sentBy);
        } else {
            $result = $this->sender->sendOperatorReply($tenant, $prospect, $validated['body'], $validated['subject'] ?? null, $sentBy);
        }

        if (! $result['success']) {
            return response()->json(['message' => (string) ($result['error'] ?? 'Reply failed.')], 422);
        }

        return response()->json(['data' => $result['message'] ?? null, 'prospect' => $prospect->refresh()], 201);
    }

    /** Edit a recruiter-bot draft before approving it; the approval card follows. */
    public function updateDraft(Request $request, string $messageId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $draft = ConversationMessage::forTenant($tenant->id)->findOrFail($messageId);
        if ($draft->status !== 'draft') {
            return response()->json(['message' => 'Only pending drafts can be edited.'], 409);
        }
        $validated = $request->validate(['body' => 'required|string|max:10000']);

        $draft->update([
            'body' => $validated['body'],
            'draft_meta' => ((array) $draft->draft_meta) + ['edited_by' => (string) $request->user()->id, 'edited_at' => now()->toIso8601String()],
        ]);

        $approval = DB::table('approvals')->where('tenant_id', $tenant->id)->where('resource_type', 'outreach_reply')
            ->where('resource_id', $draft->id)->where('status', 'pending')->first();
        if ($approval !== null) {
            $context = json_decode((string) $approval->context, true) ?: [];
            $context['draft_body'] = $validated['body'];
            DB::table('approvals')->where('id', $approval->id)->update(['context' => json_encode($context), 'updated_at' => now()]);
        }

        return response()->json(['data' => $draft->refresh()]);
    }

    /** A human handled the escalation: let the bot resume on this thread. */
    public function handBack(Request $request, string $id): JsonResponse
    {
        $prospect = PartnerProspect::forTenant($request->attributes->get('tenant')->id)->findOrFail($id);
        if (! $this->replies->handBack($prospect)) {
            return response()->json(['message' => 'Prospect is not waiting on a human.'], 409);
        }

        return response()->json(['data' => $prospect->refresh()]);
    }

    public function pause(Request $request, string $id): JsonResponse
    {
        $prospect = PartnerProspect::forTenant($request->attributes->get('tenant')->id)->findOrFail($id);
        $prospect->forceFill(['bot_paused_at' => now(), 'next_send_at' => null])->save();

        return response()->json(['data' => $prospect]);
    }

    public function resume(Request $request, string $id): JsonResponse
    {
        $prospect = PartnerProspect::forTenant($request->attributes->get('tenant')->id)->findOrFail($id);
        $prospect->forceFill([
            'bot_paused_at' => null,
            'next_send_at' => in_array($prospect->status, PartnerProspect::SENDABLE, true) ? now() : $prospect->next_send_at,
        ])->save();

        return response()->json(['data' => $prospect]);
    }

    public function retire(Request $request, string $id): JsonResponse
    {
        $prospect = PartnerProspect::forTenant($request->attributes->get('tenant')->id)->findOrFail($id);
        if ($prospect->isTerminal()) {
            return response()->json(['message' => 'Prospect is already closed.'], 409);
        }
        $this->lifecycle->transition($prospect, PartnerProspect::STATUS_RETIRED, ['next_send_at' => null]);

        return response()->json(['data' => $prospect->refresh()]);
    }

    public function settings(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $tenantId = (string) $tenant->id;

        return response()->json([
            'data' => $this->settings->for($tenant),
            'mailbox_connected' => $this->mailers->hasSmtp($this->mailers->credentialsFor($tenantId)),
            'flags' => [
                'sending' => FeatureFlag::on('outreach.sending', $tenantId),
                'inbound_poll' => FeatureFlag::on('outreach.inbound_poll', $tenantId),
                'bot_replies' => FeatureFlag::on('outreach.bot_replies', $tenantId),
            ],
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'program' => 'sometimes|array',
            'program.brand' => 'sometimes|string|max:80',
            'program.commission_pct' => 'sometimes|integer|min:0|max:100',
            'program.months' => 'sometimes|integer|min:1|max:60',
            'program.cookie_days' => 'sometimes|integer|min:1|max:365',
            'program.min_payout_usd' => 'sometimes|integer|min:0|max:10000',
            'program.hold_days' => 'sometimes|integer|min:0|max:180',
            'program.terms_url' => 'sometimes|nullable|url|max:500',
            'program.join_url' => 'sometimes|nullable|url|max:500',
            'program.portal_name' => 'sometimes|string|max:60',
            'program.operator_legal_name' => 'sometimes|string|max:120',
            'program.postal_address' => 'sometimes|nullable|string|max:300',
            'program.exclusions' => 'sometimes|array|max:20',
            'program.exclusions.*' => 'string|max:80',
            'sending' => 'sometimes|array',
            'sending.per_run_cap' => 'sometimes|integer|min:1|max:100',
            'sending.min_gap_seconds' => 'sometimes|integer|min:0|max:3600',
            'sending.hourly_burst_cap' => 'sometimes|integer|min:1|max:500',
            'sending.retire_after_days' => 'sometimes|integer|min:1|max:90',
            'sending.timezone' => 'sometimes|timezone',
            'sending.quiet_hours' => 'sometimes|array',
            'sending.quiet_hours.start' => 'sometimes|date_format:H:i',
            'sending.quiet_hours.end' => 'sometimes|date_format:H:i',
            'sending.warmup' => 'sometimes|array|max:10',
            'sending.warmup.*.from_day' => 'required|integer|min:1|max:365',
            'sending.warmup.*.cap' => 'required|integer|min:1|max:500',
            'sequence' => 'sometimes|array|min:1|max:6',
            'sequence.*.template' => 'required|string|max:64',
            'sequence.*.wait_days' => 'required|integer|min:0|max:60',
            'replies' => 'sometimes|array',
            'replies.mode' => 'sometimes|in:approve,auto',
            'replies.per_thread_daily_cap' => 'sometimes|integer|min:1|max:20',
            'replies.tenant_daily_cap' => 'sometimes|integer|min:1|max:1000',
        ]);

        // Only these sections are operator-editable; mailbox/started_at are system-owned.
        $patch = array_intersect_key($validated, array_flip(['program', 'sending', 'sequence', 'replies']));
        if (isset($patch['sequence'])) {
            $steps = [];
            foreach (array_values($patch['sequence']) as $i => $step) {
                $steps[] = ['step' => $i + 1] + $step;
            }
            $patch['sequence'] = $steps;
        }
        if (isset($patch['sending']['started_at'])) {
            unset($patch['sending']['started_at']);
        }

        return response()->json(['data' => $this->settings->update($tenant, $patch)]);
    }

    /** Manual tick from the cockpit (dry run by default). */
    public function run(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $dryRun = $request->boolean('dry_run', true);

        return response()->json(['data' => $this->sender->runForTenant($tenant, $dryRun, $request->boolean('force'))]);
    }

    /** @return array<string, mixed> */
    private function summary(string $tenantId): array
    {
        $byStatus = PartnerProspect::forTenant($tenantId)
            ->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->all();

        return [
            'total' => array_sum($byStatus),
            'by_status' => $byStatus,
            'with_email' => PartnerProspect::forTenant($tenantId)->whereHas('lead', fn ($q) => $q->whereNotNull('email'))->count(),
            'dm_queue' => ConversationMessage::forTenant($tenantId)->where('status', 'awaiting_operator')->count(),
            'needs_human' => PartnerProspect::forTenant($tenantId)->whereNotNull('needs_human_at')->count(),
        ];
    }
}
