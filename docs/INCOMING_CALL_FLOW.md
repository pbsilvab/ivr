# Incoming Call Flow - Complete System Guide

## Overview

This document explains the complete incoming call flow for the Twilio TaskRouter Voice App built with Laravel.

---

## Simple System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        CALLER                                   │
│                    (Phones into app)                            │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             │ (1) Incoming call via Twilio
                             ↓
                    ┌────────────────┐
                    │  Twilio Voice  │
                    │   (receives)   │
                    └────────┬───────┘
                             │
                             │ (2) HTTP POST: incoming callback
                             ↓
                    ┌────────────────────────────┐
                    │  Laravel App               │
                    │  VoiceController.incoming  │
                    │  - Creates Call record     │
                    │  - Returns IVR (Gather)    │
                    └────────┬───────────────────┘
                             │
        ┌────────────────────┴─────────────────────┐
        │                                          │
        │ (3) Caller presses digit                 │
        ↓                                          ↓
    Digit 1 (Agent)                           Digit 2 (Voicemail)
        │                                          │
        ↓                                          ↓
   ┌─────────────────┐                      ┌──────────────┐
   │ RouteCallTo     │                      │ Record       │
   │ AgentAction     │                      │ Voicemail    │
   │ - Creates Task  │                      │              │
   │   in Twilio     │                      └──────┬───────┘
   │ - Enqueues call │                             │
   └────────┬────────┘                             │
            │                              (4) Voicemail recorded
            │                                 HTTP POST: voicemail-record
            │                              (5) Save to DB + SMS to agents
            │                                 │
            │                                 ↓
            │                      ┌──────────────────┐
            │                      │ Call ends        │
            │                      │ (Hangup)         │
            │                      └──────────────────┘
            │
       (3a) Twilio routes to agent
            │
            ↓
   ┌──────────────────────────┐
   │ Twilio TaskRouter        │
   │ - Creates Task           │
   │ - Finds available worker │
   │ - Sends assignment       │
   └────────┬─────────────────┘
            │
            │ (3b) HTTP POST: assignment callback
            ↓
   ┌────────────────────────────────┐
   │ TaskAssignmentHandler          │
   │ - Agent accepts?               │
   │   ├─ YES: Update status        │
   │   │       Dial to agent number │
   │   └─ NO: Await reassignment    │
   └────────┬───────────────────────┘
            │
        ┌───┴───┐
        │       │
   (3c) ACCEPT  REJECT
        │       │
        ↓       ↓
    DIAL TO  WAIT FOR
    AGENT    NEXT AGENT
        │
        ├─ No agents accept?
        └─→ Task times out → Voicemail fallback
```

---

## Key Components

### 1. **VoiceController** (Incoming Voice)
Receives Twilio callbacks for voice events.

**Endpoints:**
- `POST /api/voice/incoming` → Caller dials in
- `POST /api/voice/gather-digits` → Caller presses digit on IVR
- `POST /api/voice/voicemail-record` → Voicemail recorded
- `POST /api/voice/no-agent-available` → Fallback when no agent accepts

---

### 2. **TaskRouterController** (Agent Routing)
Receives Twilio TaskRouter callbacks for assignment and events.

**Endpoints:**
- `POST /api/taskrouter/assignment` → Agent receives task offer (accepts/rejects)
- `POST /api/taskrouter/events` → Task events (completed, timeout, etc.)

---

### 3. **AgentAvailabilityController** (Agent Status)
Manages agent availability in TaskRouter.

**Endpoints:**
- `POST /api/agents/{id}/availability/toggle` → Switch available ↔ unavailable
- `POST /api/agents/{id}/availability/set` → Set specific status

---

## Call Flow Scenarios

### Scenario 1: Happy Path (Agent Accepts)

```
1. Caller dials in
   ↓
2. App receives "incoming" callback, creates Call record, returns IVR TwiML
   ↓
3. Caller presses "1" for agent
   ↓
4. App calls RouteCallToAgentAction:
   - Creates Twilio Task
   - Returns Enqueue TwiML (caller waits)
   ↓
5. Twilio TaskRouter assigns task to available agent
   ↓
6. Agent's phone rings with task offer
   ↓
7. Agent presses "1" to accept (via TaskRouter)
   ↓
8. App receives "assignment" callback with status="accepted"
   ↓
9. App returns Dial TwiML to agent's phone
   ↓
10. Agent connected to caller ✅
```

---

### Scenario 2: No Agent Available → Voicemail Fallback

```
1-4. Same as Scenario 1 (caller routes to agent)
   ↓
5. Twilio TaskRouter cannot find available agent
   ↓
6. Task times out (reaches wrapup status)
   ↓
7. App receives "events" callback with status="wrapup"
   ↓
8. App detects timeout (no reservation_sid) in TaskTimeoutHandler:
   - Updates Call.status = "agent_unavailable"
   - Updates TaskRecord.status = "timeout"
   ↓
9. Twilio redirects call to "no-agent-available" endpoint
   ↓
10. App returns Record TwiML
    ↓
11. Caller records message
    ↓
12. App receives "voicemail-record" callback
    ↓
13. VoicemailHandler:
    - Saves recording_url to Voicemail table
    - Sends SMS to all agents with voicemail summary
    ↓
14. Call ends ✅
```

---

### Scenario 3: Caller Chooses Voicemail Directly

```
1-2. Same as Scenario 1 (caller dials in)
   ↓
3. Caller presses "2" for voicemail
   ↓
4. App returns Record TwiML
   ↓
5. Caller records message
   ↓
6-14. Same as Scenario 2 from step 12 onward ✅
```

---

## Database Schema

### calls
```
id              | Call record ID
call_sid        | Twilio CallSid (unique)
from_number     | Caller's phone number
to_number       | (optional) Who call was routed to
status          | initiated, accepted, voicemail_recorded, agent_unavailable
agent_id        | Which agent (foreign key to agents table)
task_sid        | Twilio TaskSid (unique, for tracking)
outcome         | no_agent, voicemail_taken, completed
created_at      | Timestamp
```

### task_records
```
id              | TaskRecord ID
task_sid        | Twilio TaskSid (unique)
call_id         | Which call (foreign key)
workflow_sid    | Twilio WorkflowSid
status          | pending, accepted, rejected, timeout
reservation_sid | Twilio ReservationSid (if agent accepted)
created_at      | Timestamp
```

### voicemails
```
id              | Voicemail ID
call_id         | Which call (foreign key)
recording_sid   | Twilio RecordingSid
recording_url   | URL to recorded audio
sms_sid         | Twilio SmsSid (if SMS sent to agents)
created_at      | Timestamp
```

### agents
```
id              | Agent ID
name            | Agent name
phone_number    | Agent's phone (for dialing)
twilio_worker_sid | Twilio WorkerSid
status          | available, unavailable
```

---

## Webhook Payloads

### 1. Incoming Call Payload

**From Twilio to:** `POST /api/voice/incoming`

```json
{
  "CallSid": "CAxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "AccountSid": "ACxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "From": "+15551234567",
  "To": "+15559876543",
  "CallStatus": "ringing",
  "ApiVersion": "2010-04-01"
}
```

**App returns:** TwiML XML
```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Gather numDigits="1" action="https://app.example.com/api/voice/gather-digits" method="POST">
    <Say>Press 1 to reach an agent, or press 2 to leave a voicemail.</Say>
  </Gather>
  <Redirect>https://app.example.com/api/voice/incoming</Redirect>
</Response>
```

---

### 2. Gather Digits Payload

**From Twilio to:** `POST /api/voice/gather-digits`

```json
{
  "CallSid": "CAxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "AccountSid": "ACxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "From": "+15551234567",
  "To": "+15559876543",
  "Digits": "1",
  "CallStatus": "in-progress"
}
```

**App returns (if digit=1):** TwiML with Enqueue
```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Enqueue workflowSid="WWxxxxxxxxxxxxxxxxxxxxxxxxxx">
    <TaskAttributes>{"callSid":"CAxx...","from":"+15551234567"}</TaskAttributes>
  </Enqueue>
</Response>
```

---

### 3. TaskRouter Assignment Payload

**From Twilio to:** `POST /api/taskrouter/assignment`

```json
{
  "TaskSid": "WTxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "WorkspaceSid": "WSxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "WorkflowSid": "WWxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "WorkerName": "John Agent",
  "WorkerSid": "WKxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "QueueName": "incoming-calls",
  "QueueSid": "WQxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "ReservationSid": "WRxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "AssignmentStatus": "accepted",
  "CallSid": "CAxxxxxxxxxxxxxxxxxxxxxxxxxx"
}
```

**App updates:** Call + TaskRecord, returns Dial TwiML
```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Dial callerId="+15559876543">
    <Number>+15551234567</Number>
  </Dial>
</Response>
```

---

### 4. TaskRouter Events Payload

**From Twilio to:** `POST /api/taskrouter/events`

```json
{
  "EventType": "task.completed",
  "TaskSid": "WTxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "WorkspaceSid": "WSxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "WorkflowSid": "WWxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "TaskStatus": "wrapup",
  "TaskAttributes": "{\"callSid\":\"CAxx...\"}",
  "ReservationSid": null,
  "WorkerSid": "WKxxxxxxxxxxxxxxxxxxxxxxxxxx"
}
```

**App processes:** Detects timeout (no ReservationSid), marks Call as agent_unavailable

---

### 5. Voicemail Recording Payload

**From Twilio to:** `POST /api/voice/voicemail-record`

```json
{
  "CallSid": "CAxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "AccountSid": "ACxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "From": "+15551234567",
  "RecordingSid": "RExxxxxxxxxxxxxxxxxxxxxxxxxx",
  "RecordingUrl": "https://api.twilio.com/Accounts/ACxx.../Recordings/RExx...",
  "RecordingStatus": "completed",
  "RecordingDuration": "45"
}
```

**App does:**
- Saves recording_url to voicemail table
- Sends SMS to all agents with notification
- Returns Hangup TwiML

---

## Idempotency & Resilience

### How Duplicate Webhooks are Handled

**Problem:** Twilio retries failed webhooks. Same event could be delivered multiple times.

**Solution:** Use unique constraints on Twilio identifiers.

| Endpoint | Idempotency Key | Strategy |
|---|---|---|
| `/api/voice/incoming` | `CallSid` | `Call::firstOrCreate(['call_sid' => $callSid])` |
| `/api/voice/gather-digits` | `CallSid` + `Digits` | Skip Task creation if `call->task_sid` already exists |
| `/api/voice/voicemail-record` | `CallSid` | Check `voicemail->sms_sid` before sending SMS again |
| `/api/taskrouter/assignment` | `TaskSid` + `AssignmentStatus` | Check task state before updating (reject if already timeout/rejected) |
| `/api/taskrouter/events` | `TaskSid` + `TaskStatus` | Check task state before updating (only update if still pending) |

---

### How Out-of-Order Webhooks are Handled

**Problem:** Webhook A arrives before Webhook B, but Webhook B should have arrived first (network delays).

**Solution:** Check existing state before applying changes.

| Scenario | Detection | Behavior |
|---|---|---|
| Timeout event arrives, then assignment accepted arrives | `TaskRecord.status == 'timeout'` | Don't accept assignment; return "already handled" message |
| Assignment accepted arrives, then task completed arrives | `TaskRecord.reservation_sid` exists | Ignore completed event; task already handled |

---

## Testing & Verification

### Unit Tests (51 total passing)

```
✓ Incoming call creates Call record (idempotent)
✓ Gather digits routes to agent
✓ Gather digits routes to voicemail
✓ Invalid digit replays IVR
✓ Assignment accepted dials agent
✓ Assignment rejected awaits reassignment
✓ Task timeout marks agent unavailable
✓ Voicemail records and notifies agents
✓ Agent availability toggle updates Twilio
✓ Duplicate webhooks are idempotent
✓ Out-of-order events handled correctly
✓ Complete call flows work end-to-end
```

---

## Troubleshooting

### 1. "Localhost is not valid" - ngrok Setup

**Problem:** Twilio rejects `http://localhost` for callback URLs.

**Solution:**
```bash
# Terminal 1: Start ngrok
ngrok http 8000

# You'll see: Forwarding https://abc123.ngrok.io -> http://localhost:8000

# Terminal 2: Update .env
APP_URL=https://abc123.ngrok.io

# Terminal 3: Provision TaskRouter
php artisan taskrouter:provision
```

**Important:** Every time ngrok restarts, you get a new URL. Update `.env` and re-run provisioning.

---

### 2. "Invalid Twilio Signature" - Validation Errors

**Problem:** App rejects webhook with 403 Forbidden.

**Solution:**
1. Verify `TWILIO_AUTH_TOKEN` in `.env` matches your Twilio account
2. Verify `APP_URL` in `.env` matches the URL Twilio sends to (should be ngrok URL)
3. Check that middleware is applied:
   ```php
   // routes/api.php
   Route::middleware('twilio.signature')->group(function () {
       // all voice and taskrouter routes
   });
   ```

---

### 3. "Task Not Found" - Call Routing Issues

**Problem:** Assignment callback arrives but `TaskRecord` doesn't exist in DB.

**Solution:**
1. Check that `/api/voice/gather-digits` (digit=1) completed before agent assignment
2. Verify Task was created in Twilio:
   ```bash
   # Check recent tasks in TaskRouter dashboard
   twilio api taskrouter v1 workspaces fetch --workspace-sid WWxxx
   ```
3. Check app logs for RouteCallToAgentAction errors

---

### 4. "Agents Not Receiving SMS" - Voicemail Notification Issue

**Problem:** Voicemail recorded but agents don't get SMS.

**Solution:**
1. Verify agents have `phone_number` in database:
   ```bash
   php artisan tinker
   > App\Models\Agent::where('phone_number', null)->count()
   # Should be 0
   ```
2. Verify `TWILIO_NUMBER` in `.env` is a valid SMS-enabled number
3. Check logs for SMS send errors
4. Verify idempotency: if voicemail was processed twice, SMS only sent on first (saved in `voicemail.sms_sid`)

---

### 5. "Agent Doesn't Receive Call" - Assignment Issues

**Problem:** Agent accepts task but doesn't get dialed.

**Solution:**
1. Verify agent's `phone_number` is correct (can be mobile/PSTN)
2. Check assignment response contains `<Dial>`:
   ```bash
   # Enable detailed logging in VoiceController
   Log::info('Assignment callback', ['payload' => $payload, 'response' => $response->asXML()]);
   ```
3. Verify TaskRouter workflow routes to correct queue and agents are in queue
4. Verify agent's `twilio_worker_sid` matches TaskRouter Worker SID

---

### 6. Running Tests Locally

```bash
# All tests
php artisan test

# Specific test class
php artisan test tests/Feature/VoiceControllerTest.php

# With coverage
php artisan test --coverage

# Watch mode (requires phpunit-watcher)
phpunit-watcher watch
```

---

## Next Steps

### Deployment Checklist

- [ ] Set production `APP_URL` (real domain, not ngrok)
- [ ] Set `LOG_CHANNEL=stack` for persistent logs
- [ ] Configure database (MySQL/Postgres in production, not SQLite)
- [ ] Set up Twilio credentials as env secrets (not in .env)
- [ ] Run database migrations: `php artisan migrate --force`
- [ ] Test at least one end-to-end call with real agents
- [ ] Monitor agent SMS delivery (check Twilio logs)
- [ ] Set up error alerting (Sentry, LogRocket, etc.)

### Potential Enhancements

1. **Call Recording:** Add `<Record>` to Dial TwiML to record agent-caller conversations
2. **Multiple Queues:** Different routing based on phone number or IVR digit selection
3. **Call Analytics:** Track average wait time, acceptance rate, voicemail volume
4. **CRM Integration:** Send voicemail transcription + recording link to ticketing system
5. **Agent Dashboard:** Real-time view of assigned tasks and availability status

---

## Questions?

Refer to:
- [SDD.md](SDD.md) - Business requirements
- [TaskRouter.md](TaskRouter.md) - TaskRouter provisioning spec
- [LaravelImplementation.md](LaravelImplementation.md) - Architecture design
