<?php

namespace App\Http\Controllers;

use App\Models\ConsentRecord;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Lead;
use App\Models\MessagingNumber;
use App\Models\SequenceEnrollment;
use App\Services\EventStore;
use App\Services\Messaging\OwnerNumberAllowlist;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Inbound WhatsApp webhook (Twilio Messages API). Guarded by the same
 * voice.verify_twilio middleware used for voice webhooks — the Twilio
 * signature scheme (HMAC-SHA1 of URL + sorted POST params) is identical for
 * any Twilio product, not voice-specific.
 */
class WhatsAppController extends Controller
{
    private const STOP_KEYWORDS = ['stop', 'unsubscribe', 'cancel', 'opt out', 'optout'];

    public function inbound(Request $request, EventStore $eventStore, OwnerNumberAllowlist $owners): Response
    {
        $from = $this->stripWhatsappPrefix((string) $request->input('From', ''));
        $to = $this->stripWhatsappPrefix((string) $request->input('To', ''));
        $body = trim((string) $request->input('Body', ''));
        $messageSid = (string) $request->input('MessageSid', '');

        $number = MessagingNumber::where('phone_number', $to)
            ->where('channel', 'whatsapp')
            ->where('is_active', true)
            ->first();

        if (! $number || $from === '') {
            // Unknown destination number — nothing we can route; ack quietly.
            return response('', 200)->header('Content-Type', 'text/xml');
        }

        $tenantId = $number->tenant_id;

        // The owner (or a team member) texting their own number is not a
        // prospect: never create a Lead for them. Route the message to the
        // owner channel instead (Atlas / the daily brief pick it up from the
        // event log); opt-out keywords are meaningless here.
        if ($owners->isOwnerNumber($tenantId, $from)) {
            $eventStore->append($tenantId, 'tenant', $tenantId, 'owner.message.received', [
                'channel' => 'whatsapp',
                'from' => $from,
                'to' => $to,
                'body' => $body,
                'provider_message_id' => $messageSid,
            ]);

            return response('', 200)->header('Content-Type', 'text/xml');
        }

        $lead = Lead::forTenant($tenantId)->where('whatsapp_number', $from)->first();
        if (! $lead) {
            $lead = Lead::create([
                'tenant_id' => $tenantId,
                'whatsapp_number' => $from,
                'source' => 'whatsapp_inbound',
                'stage' => 'captured',
                'score' => 20,
            ]);
            $eventStore->append($tenantId, 'lead', $lead->id, 'lead.imported', ['lead_id' => $lead->id, 'source' => 'whatsapp_inbound']);
        }

        if ($this->isOptOut($body)) {
            $lead->update(['consent' => array_merge($lead->consent ?? [], ['whatsapp_opt_in' => false, 'opted_out_at' => now()->toIso8601String()])]);
            SequenceEnrollment::forTenant($tenantId)->where('lead_id', $lead->id)->where('status', 'active')->update(['status' => 'cancelled']);
            // Audit-trail the opt-out for compliance evidence.
            if ($from) {
                ConsentRecord::log($tenantId, $from, 'whatsapp', 'stopped', 'inbound_stop', ['lead_id' => $lead->id]);
            }

            return response('', 200)->header('Content-Type', 'text/xml');
        }

        $conversation = Conversation::forTenant($tenantId)->where('lead_id', $lead->id)->where('channel', 'whatsapp')->first()
            ?? Conversation::create(['tenant_id' => $tenantId, 'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open']);

        ConversationMessage::create([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'body' => $body,
            'provider_message_id' => $messageSid,
            'status' => 'delivered',
        ]);

        $conversation->update(['last_message_at' => now(), 'status' => 'pending']);

        // Pause any nurture sequence — the lead replied, so scripted follow-ups
        // should stop until a human (or the crm agent) responds.
        SequenceEnrollment::forTenant($tenantId)->where('lead_id', $lead->id)->where('status', 'active')->update(['status' => 'paused']);

        if (in_array($lead->stage, ['captured', 'qualified', 'recycled'], true)) {
            $lead->update(['stage' => 'engaged', 'last_contacted_at' => now()]);
        }

        $eventStore->append($tenantId, 'conversation', $conversation->id, 'conversation.message.received', [
            'lead_id' => $lead->id, 'channel' => 'whatsapp',
        ]);

        // crm agent dispatch (draft-for-approval unless automation_level is
        // autonomous) happens via the standard Redis agent:dispatch path,
        // triggered by the conversation.message.received event above — not
        // synchronously in this webhook, to keep the Twilio response fast.

        return response('', 200)->header('Content-Type', 'text/xml');
    }

    public function status(Request $request): Response
    {
        $messageSid = (string) $request->input('MessageSid', '');
        $status = (string) $request->input('MessageStatus', '');

        if ($messageSid !== '' && $status !== '') {
            ConversationMessage::where('provider_message_id', $messageSid)->update(['status' => $status]);
        }

        return response('', 200);
    }

    private function stripWhatsappPrefix(string $value): string
    {
        return str_starts_with($value, 'whatsapp:') ? substr($value, 9) : $value;
    }

    private function isOptOut(string $body): bool
    {
        $lower = strtolower(trim($body));

        return in_array($lower, self::STOP_KEYWORDS, true);
    }
}
