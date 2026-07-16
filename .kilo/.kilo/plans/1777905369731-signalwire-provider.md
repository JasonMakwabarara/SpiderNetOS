# Voice AI as a Service — Implementation Plan

## Thesis

SpiderNetOS Voice AI is a managed voice intelligence layer that runs on **any** telephony provider (Twilio, SignalWire, Vonage, Telnyx). Tenants bring their own carrier; SpiderNetOS provides the AI brain. This is higher margin and defensible than building a CPaaS competitor.

## Architecture

```
Caller ──PSTN──> [SignalWire/Twilio/Vonage]
                        │
                        │ Webhook (TwiML/LaML/NCCO)
                        ▼
              SpiderNetOS VoiceController
                        │
              ┌─────────┼─────────┐
              ▼         ▼         ▼
         Tenant     Agent      Cost
         Router     Planner    Governor
              │         │         │
              ▼         ▼         ▼
    ┌─────────────────────────────────┐
    │   Inference Plane (FastAPI)     │
    │  ┌─────────┐  ┌──────────────┐  │
    │  │ STT      │  │ LLM Stream   │  │
    │  │ Deepgram │  │ qwen3/gpt-5  │  │
    │  │ Whisper  │  │ o4-mini      │  │
    │  └─────────┘  └──────────────┘  │
    │  ┌──────────────────────────┐   │
    │  │ TTS Stream               │   │
    │  │ ElevenLabs / Piper       │   │
    │  └──────────────────────────┘   │
    │  ┌──────────────────────────┐   │
    │  │ Barge-in / VAD Pipeline  │   │
    │  └──────────────────────────┘   │
    └─────────────────────────────────┘
                        │
                        ▼
              WebSocket Media Stream
                        │
                        ▼
              [SignalWire/Twilio] ──PSTN──> Caller
```

## Implementation Steps

### Phase 1: Multi-Provider Abstraction (current)
1. Add SignalWire to `config/telephony.php`
2. Update `TelephonyService` to route API calls per provider
3. Update `VoiceController` validation to accept `signalwire`
4. Add `.env` variables for SignalWire
5. SignalWire LaML is TwiML-compatible — zero TwiML changes needed

### Phase 2: Provider Interface
1. Extract `TelephonyProvider` interface
2. Implement `TwilioProvider`, `SignalWireProvider`, `VonageProvider`
3. Provider factory pattern in `TelephonyService`
4. Each provider handles: number provisioning, call initiation, webhook format

### Phase 3: Voice AI SaaS Product
1. Tenant self-service portal for voice AI setup
2. Provider-agnostic onboarding wizard
3. Usage dashboard with per-minute cost analytics
4. Voice agent templates (receptionist, sales, support, scheduling)
5. API for third-party developers to build custom voice agents

## File Changes

### Modified:
- `backend/config/telephony.php` — add signalwire provider
- `backend/app/Services/TelephonyService.php` — multi-provider routing
- `backend/app/Http/Controllers/VoiceController.php` — validation update
- `.env.example` — add signalwire env vars

### New:
- `backend/app/Services/Telephony/SignalWireProvider.php`
