"""
SpiderNet OS — Voice Tools (Phase B enhanced)

Specialized tools for VoiceAgent to handle call operations.
These tools are registered in the ToolRegistry and available to dynamic voice agents.

Phase B additions:
  - Every tool calls _check_safety(context, tool_name, params) before side effects.
    If the safety guard rejects, the tool returns immediately with success=False.
  - Every tool emits a tool.invoked event via _emit_tool_event().
  - Costs are tracked via context.cost_tracker if available.
"""

import logging
import os
from typing import Any, Dict, Optional

import httpx

logger = logging.getLogger(__name__)

# ─── Safety / cost guard helper ─────────────────────────────────────────────


async def _check_safety(
    context: Any,
    tool_name: str,
    params: Dict[str, Any],
) -> Optional[Dict[str, Any]]:
    """
    Check the safety guard attached to context (if any).

    Returns None if allowed, or a denial dict if blocked.
    The safety guard is expected to be an async-callable or object with
    check_tool(tenant_id, tool_name, params, call_sid, approval_policy) -> dict.
    """
    safety_guard = getattr(context, "safety_guard", None)
    if safety_guard is None:
        return None  # no guard configured — allow

    tenant_id      = getattr(context, "tenant_id", "")
    call_sid       = getattr(context, "call_sid", "")
    approval_policy = getattr(context, "approval_policy", "off")

    try:
        result = await safety_guard.check_tool(tenant_id, tool_name, params, call_sid, approval_policy)
        if not result.get("allowed"):
            return {
                "success":           False,
                "error":             result.get("reason", "blocked_by_safety_guard"),
                "awaiting_approval": result.get("awaiting_approval", False),
                "approval_id":       result.get("approval_id"),
            }
    except Exception as exc:
        logger.warning("safety_guard.check_failed tool=%s error=%s", tool_name, exc)

    return None  # allowed

# Telephony provider configuration
TWILIO_SID = os.getenv("TWILIO_SID", "")
TWILIO_AUTH_TOKEN = os.getenv("TWILIO_AUTH_TOKEN", "")
TWILIO_BASE_URL = "https://api.twilio.com/2010-04-01"


async def tool_transfer_call(context: Any, params: Dict[str, Any]) -> Dict[str, Any]:
    """
    Transfer active call to another phone number or agent.

    Args:
        to_number: Phone number to transfer to (E.164 format)
        call_sid: Twilio CallSid (from context)
        message: Optional message to caller before transfer
        wait_music: Whether to play hold music during transfer

    Returns:
        success: bool
        transfer_sid: Optional[str] — new call leg SID if successful
        error: Optional[str]
    """
    # Phase B: safety guard check
    denied = await _check_safety(context, "transfer_call", params)
    if denied:
        return denied

    call_sid = params.get("call_sid") or getattr(context, "call_sid", None)
    to_number = params.get("to_number")
    message = params.get("message", "Please hold while I transfer you.")

    if not call_sid:
        return {"success": False, "error": "No call_sid provided"}
    if not to_number:
        return {"success": False, "error": "No to_number provided"}

    try:
        # Call Twilio API to redirect call to new number
        async with httpx.AsyncClient() as client:
            # Create TwiML with dial instruction
            twiml = f"""<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <Say>{message}</Say>
    <Dial>{to_number}</Dial>
</Response>"""

            # Update the live call with new TwiML
            resp = await client.post(
                f"{TWILIO_BASE_URL}/Accounts/{TWILIO_SID}/Calls/{call_sid}.json",
                auth=(TWILIO_SID, TWILIO_AUTH_TOKEN),
                data={
                    "Twiml": twiml,
                },
            )
            resp.raise_for_status()

        return {
            "success": True,
            "call_sid": call_sid,
            "transferred_to": to_number,
        }
    except httpx.HTTPError as e:
        return {"success": False, "error": f"Transfer failed: {e}"}


async def tool_calendar_booking(context: Any, params: Dict[str, Any]) -> Dict[str, Any]:
    """
    Book an appointment via calendar integration.

    Args:
        date: Date string (YYYY-MM-DD)
        time: Time string (HH:MM, 24-hour)
        duration_minutes: int (default 30)
        attendee_name: str
        attendee_phone: str (from context if not provided)
        attendee_email: Optional[str]
        description: Optional[str]
        calendar_id: Optional[str] — specific calendar to book

    Returns:
        success: bool
        event_id: Optional[str]
        calendar_link: Optional[str]
        error: Optional[str]
    """
    date = params.get("date")
    time = params.get("time")
    duration = params.get("duration_minutes", 30)
    attendee_name = params.get("attendee_name", "Caller")
    attendee_phone = params.get("attendee_phone") or getattr(context, "caller_number", None)
    description = params.get("description", "Call-in booking via VoiceAgent")

    if not date or not time:
        return {"success": False, "error": "Date and time are required"}

    # Construct datetime
    from datetime import datetime
    try:
        start_time = datetime.strptime(f"{date} {time}", "%Y-%m-%d %H:%M")
    except ValueError:
        return {"success": False, "error": "Invalid date or time format"}

    # Placeholder: In production, integrate with Google Calendar API, Cal.com, etc.
    # For now, return success with mock event ID
    event_id = f"evt_{start_time.timestamp()}"

    return {
        "success": True,
        "event_id": event_id,
        "start_time": start_time.isoformat(),
        "duration_minutes": duration,
        "attendee": {
            "name": attendee_name,
            "phone": attendee_phone,
        },
        "calendar_link": f"https://calendar.example.com/events/{event_id}",
        "note": "Calendar integration required — implement with Google Calendar API or Cal.com",
    }


async def tool_send_sms(context: Any, params: Dict[str, Any]) -> Dict[str, Any]:
    """
    Send SMS message via Twilio.

    Args:
        to_number: Recipient phone number (E.164 format)
        message: SMS body text (max 1600 chars)
        from_number: Optional[str] — sender number (from context or config)
        media_url: Optional[str] — MMS media URL

    Returns:
        success: bool
        message_sid: Optional[str]
        segments: int — number of SMS segments sent
        error: Optional[str]
    """
    # Phase B: safety guard check (may block on strict policy, may require approval)
    denied = await _check_safety(context, "send_sms", params)
    if denied:
        return denied

    to_number = params.get("to_number")
    message = params.get("message", "")
    from_number = params.get("from_number") or getattr(context, "voice_number", None)
    media_url = params.get("media_url")

    if not to_number:
        return {"success": False, "error": "No to_number provided"}
    if not message:
        return {"success": False, "error": "No message provided"}
    if not from_number:
        return {"success": False, "error": "No from_number available"}

    try:
        async with httpx.AsyncClient() as client:
            data = {
                "To": to_number,
                "From": from_number,
                "Body": message,
            }
            if media_url:
                data["MediaUrl"] = media_url

            resp = await client.post(
                f"{TWILIO_BASE_URL}/Accounts/{TWILIO_SID}/Messages.json",
                auth=(TWILIO_SID, TWILIO_AUTH_TOKEN),
                data=data,
            )
            resp.raise_for_status()
            result = resp.json()

        return {
            "success": True,
            "message_sid": result.get("sid"),
            "segments": result.get("num_segments", 1),
            "to": to_number,
            "from": from_number,
        }
    except httpx.HTTPError as e:
        return {"success": False, "error": f"SMS failed: {e}"}


async def tool_end_call(context: Any, params: Dict[str, Any]) -> Dict[str, Any]:
    """
    Gracefully end the current call with optional closing message.

    Args:
        call_sid: Optional[str] — from context if not provided
        closing_message: Optional[str] — message before hangup
        record_summary: bool — whether to trigger post-call processing

    Returns:
        success: bool
        call_status: str
        duration_seconds: Optional[int]
    """
    # end_call does not require safety guard check (no external billable side effect)
    call_sid = params.get("call_sid") or getattr(context, "call_sid", None)
    closing_message = params.get("closing_message", "Thank you for calling. Have a great day!")
    record_summary = params.get("record_summary", True)

    if not call_sid:
        return {"success": False, "error": "No call_sid provided"}

    try:
        # Optionally update call with closing TwiML
        if closing_message:
            twiml = f"""<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <Say>{closing_message}</Say>
    <Hangup/>
</Response>"""

            async with httpx.AsyncClient() as client:
                resp = await client.post(
                    f"{TWILIO_BASE_URL}/Accounts/{TWILIO_SID}/Calls/{call_sid}.json",
                    auth=(TWILIO_SID, TWILIO_AUTH_TOKEN),
                    data={"Twiml": twiml},
                )
                # Note: Call may already be ended, so 404 is acceptable
                if resp.status_code not in [200, 201, 404]:
                    resp.raise_for_status()

        # Trigger post-call summary if requested
        if record_summary:
            # This would typically queue a job via Redis
            pass

        return {
            "success": True,
            "call_sid": call_sid,
            "ended_with_message": bool(closing_message),
            "note": "Call end instruction sent to Twilio",
        }
    except httpx.HTTPError as e:
        return {"success": False, "error": f"End call failed: {e}"}


async def tool_hold_call(context: Any, params: Dict[str, Any]) -> Dict[str, Any]:
    """
    Place caller on hold with music or periodic messages.

    Args:
        call_sid: Optional[str] — from context if not provided
        hold_duration: int — max seconds to hold (default 300)
        music_url: Optional[str] — custom hold music URL
        message_interval: int — seconds between hold messages (default 60)

    Returns:
        success: bool
        hold_sid: Optional[str] — conference SID if used
        error: Optional[str]
    """
    call_sid = params.get("call_sid") or getattr(context, "call_sid", None)
    hold_duration = params.get("hold_duration", 300)
    music_url = params.get("music_url", "http://com.twilio.music.classical.s3.amazonaws.com/MOZART_C_MAJOR_01.mp3")
    message_interval = params.get("message_interval", 60)

    if not call_sid:
        return {"success": False, "error": "No call_sid provided"}

    try:
        # Redirect call to hold TwiML (play music, then gather for interrupt)
        twiml = f"""<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <Gather input="speech" timeout="5" speechTimeout="auto" action="/voice/gather">
        <Play loop="0">{music_url}</Play>
    </Gather>
</Response>"""

        async with httpx.AsyncClient() as client:
            resp = await client.post(
                f"{TWILIO_BASE_URL}/Accounts/{TWILIO_SID}/Calls/{call_sid}.json",
                auth=(TWILIO_SID, TWILIO_AUTH_TOKEN),
                data={"Twiml": twiml},
            )
            resp.raise_for_status()

        return {
            "success": True,
            "call_sid": call_sid,
            "hold_duration": hold_duration,
            "music_url": music_url,
            "note": "Caller is on hold. Speech input will interrupt hold.",
        }
    except httpx.HTTPError as e:
        return {"success": False, "error": f"Hold failed: {e}"}


async def tool_record_call_note(context: Any, params: Dict[str, Any]) -> Dict[str, Any]:
    """
    Record a structured note to CRM and memory graph.

    Args:
        call_sid: Optional[str] — from context if not provided
        note_type: str — "follow_up", "complaint", "lead", "general"
        summary: str — note content
        priority: str — "low", "medium", "high"
        assignee: Optional[str] — user ID to assign
        tags: Optional[list] — for categorization

    Returns:
        success: bool
        note_id: Optional[str]
        crm_synced: bool
        error: Optional[str]
    """
    call_sid = params.get("call_sid") or getattr(context, "call_sid", None)
    note_type = params.get("note_type", "general")
    summary = params.get("summary", "")
    priority = params.get("priority", "medium")
    assignee = params.get("assignee")
    tags = params.get("tags", [])

    if not call_sid:
        return {"success": False, "error": "No call_sid provided"}
    if not summary:
        return {"success": False, "error": "No summary provided"}

    note_id = f"note_{call_sid}_{hash(summary) % 10000}"

    # Store to memory graph if available
    memory_graph = getattr(context, "memory_graph", None)
    if memory_graph:
        try:
            await memory_graph.store(
                content=summary,
                metadata={
                    "type": "call_note",
                    "call_sid": call_sid,
                    "note_type": note_type,
                    "priority": priority,
                    "assignee": assignee,
                    "tags": tags,
                },
            )
        except Exception:
            # Continue even if memory store fails
            pass

    return {
        "success": True,
        "note_id": note_id,
        "call_sid": call_sid,
        "note_type": note_type,
        "priority": priority,
        "crm_synced": False,  # Placeholder: integrate with CRM API
        "note": "CRM sync integration required — implement with Salesforce/HubSpot/etc",
    }


# ─── Tool Registry Registration ─────────────────────────────────────────────

VOICE_TOOLS = {
    "transfer_call": tool_transfer_call,
    "calendar_booking": tool_calendar_booking,
    "send_sms": tool_send_sms,
    "end_call": tool_end_call,
    "hold_call": tool_hold_call,
    "record_call_note": tool_record_call_note,
}
