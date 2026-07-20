<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\AdminAuditLog;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConsentRecord;
use App\Models\DsarRequest;
use App\Models\Lead;
use App\Models\SequenceEnrollment;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Fulfils GDPR data-subject requests: export (Art. 15) as an encrypted JSON
 * bundle, and erasure (Art. 17) as a PII scrub that preserves referential rows
 * and the immutable event/audit chain (tombstone, not hard-delete).
 */
class DsarService
{
    public function fulfill(DsarRequest $req): DsarRequest
    {
        $req->update(['status' => 'processing']);

        try {
            $req->type === 'export' ? $this->export($req) : $this->erase($req);
            $req->update(['status' => 'completed', 'completed_at' => now()]);
        } catch (\Throwable $e) {
            $req->update(['status' => 'failed', 'notes' => $e->getMessage()]);
        }

        return $req->refresh();
    }

    private function export(DsarRequest $req): void
    {
        $json = json_encode($this->gather($req), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $path = "dsar/{$req->tenant_id}/{$req->id}.json.enc";
        Storage::disk('local')->put($path, Crypt::encryptString((string) $json));
        $req->artifact_path = $path;
        $req->save();

        AdminAuditLog::record(
            $req->tenant_id, (string) ($req->requested_by ?? 'system'), 'system',
            'dsar.export', 'dsar_request', $req->id,
            ['subject_type' => $req->subject_type, 'subject_id' => $req->subject_id],
        );
    }

    /** @return array<string,mixed> */
    private function gather(DsarRequest $req): array
    {
        $bundle = [
            'generated_at' => now()->toIso8601String(),
            'request' => $req->only(['id', 'type', 'subject_type', 'subject_id', 'subject_email']),
        ];

        if ($req->subject_type === 'lead' && $req->subject_id) {
            $lead = Lead::forTenant($req->tenant_id)->find($req->subject_id);
            $bundle['lead'] = $lead?->toArray();

            $convos = Conversation::forTenant($req->tenant_id)->where('lead_id', $req->subject_id)->get();
            $bundle['conversations'] = $convos->toArray();
            $bundle['messages'] = ConversationMessage::forTenant($req->tenant_id)
                ->whereIn('conversation_id', $convos->pluck('id'))->get()->toArray();
            $bundle['sequence_enrollments'] = SequenceEnrollment::forTenant($req->tenant_id)
                ->where('lead_id', $req->subject_id)->get()->toArray();

            foreach ([$lead?->email, $lead?->phone, $lead?->whatsapp_number] as $subj) {
                if ($subj) {
                    $bundle['consent'][$subj] = ConsentRecord::forTenant($req->tenant_id)->where('subject', $subj)->get()->toArray();
                }
            }
        } elseif ($req->subject_type === 'user' && $req->subject_id) {
            $user = User::where('tenant_id', $req->tenant_id)->find($req->subject_id);
            $bundle['user'] = $user?->makeHidden(['password', 'remember_token', 'totp_secret'])->toArray();
        }

        return $bundle;
    }

    private function erase(DsarRequest $req): void
    {
        DB::transaction(function () use ($req) {
            if ($req->subject_type === 'lead' && $req->subject_id) {
                $lead = Lead::forTenant($req->tenant_id)->lockForUpdate()->find($req->subject_id);
                if ($lead) {
                    // Scrub message bodies for this lead's conversations.
                    $convoIds = Conversation::forTenant($req->tenant_id)->where('lead_id', $lead->id)->pluck('id');
                    ConversationMessage::forTenant($req->tenant_id)->whereIn('conversation_id', $convoIds)
                        ->update(['body' => '[erased]']);

                    foreach ([$lead->email, $lead->phone, $lead->whatsapp_number] as $subj) {
                        if ($subj) {
                            ConsentRecord::log($req->tenant_id, $subj, 'email', 'revoked', 'erasure');
                        }
                    }

                    $lead->update([
                        'name' => '[erased]', 'email' => null, 'phone' => null,
                        'whatsapp_number' => null, 'consent' => ['erased_at' => now()->toIso8601String()],
                        'custom' => [],
                    ]);
                }
            } elseif ($req->subject_type === 'user' && $req->subject_id) {
                $user = User::where('tenant_id', $req->tenant_id)->lockForUpdate()->find($req->subject_id);
                if ($user) {
                    $user->forceFill([
                        'name' => '[erased]',
                        'email' => 'erased+'.$user->id.'@erased.invalid',
                        'preferences' => null,
                        'totp_secret' => null,
                        'totp_confirmed_at' => null,
                    ])->save();
                }
            }

            // The erasure itself is a recorded, chain-preserving event.
            AdminAuditLog::record(
                $req->tenant_id, (string) ($req->requested_by ?? 'system'), 'system',
                'dsar.erasure', 'dsar_request', $req->id,
                ['subject_type' => $req->subject_type, 'subject_id' => $req->subject_id],
            );
        });
    }
}
