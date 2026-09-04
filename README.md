# TaskRouter Voice App - Laravel Implementation

A production-ready incoming call flow built with Laravel + Twilio TaskRouter.

## Quick Start

### 1. Install Dependencies
```bash
composer install
php artisan migrate
```

### 2. Set Environment Variables
```bash
cp .env.example .env
# Edit .env with your Twilio credentials
```

### 3. Run Locally with ngrok
```bash
# Terminal 1: Start Laravel
php artisan serve

# Terminal 2: Start ngrok tunnel
ngrok http 8000
# Copy the ngrok URL and update APP_URL in .env

# Terminal 3: Provision TaskRouter
php artisan taskrouter:provision
```

### 4. Test
```bash
php artisan test
```

### 5. Browser dialer (optional)
```bash
php artisan dialer:provision   # copy the printed SIDs into .env
npm install && npm run build
# then open https://<your-ngrok-host>/dialer
```

---

## What This Does

When someone calls your Twilio number:

1. **IVR Prompt** → "Press 1 for agent, press 2 for voicemail"
2. **Press 1** → Routes through TaskRouter to available agents
3. **Agent Accepts** → Connected to caller
4. **No Agent Available** → Recorded as voicemail, SMS sent to agents
5. **Press 2** → Direct voicemail recording

---

## Documentation

- [☎️ Browser Dialer](docs/DIALER.md) - Softphone that calls in as an external customer
- [📖 Complete System Guide](docs/INCOMING_CALL_FLOW.md) - Diagrams, flows, payloads, troubleshooting
- [🏗️ Architecture Design](docs/LaravelImplementation.md) - Decision rationale
- [📝 Implementation Notes](docs/IMPLEMENTATION_NOTES.md) - What was built, how to use it
- [🔧 TaskRouter Setup](docs/TaskRouter.md) - Provisioning spec
- [📋 Requirements](docs/SDD.md) - Business requirements

---

## Key Features

✅ **IVR Routing** - Press 1 for agent, 2 for voicemail  
✅ **Agent Assignment** - Twilio TaskRouter routes to available agents  
✅ **Voicemail Fallback** - Auto-records when no agent accepts  
✅ **SMS Notifications** - Agents notified of voicemail  
✅ **Browser Dialer** - Softphone at `/dialer` that calls in as an external customer  
✅ **Idempotent** - Handles duplicate webhooks gracefully  
✅ **Out-of-Order Safe** - Protects against delayed webhook delivery  
✅ **51 Tests** - Full test coverage, 200+ assertions  

---

## API Endpoints

### Voice Webhooks (from Twilio to your app)
```
POST /api/voice/incoming              # Incoming call
POST /api/voice/gather-digits         # IVR digit input
POST /api/voice/voicemail-record      # Voicemail recorded
POST /api/voice/no-agent-available    # Fallback to voicemail
```

### TaskRouter Webhooks (from Twilio TaskRouter to your app)
```
POST /api/taskrouter/assignment       # Agent accepts/rejects
POST /api/taskrouter/events           # Task timeout/completion
```

### Agent Management (from your app)
```
POST /api/agents/{id}/availability/toggle   # Switch available ↔ unavailable
POST /api/agents/{id}/availability/set      # Set specific status
```

### Browser Dialer
```
GET  /dialer                          # Softphone UI
POST /api/dialer/token                # Mint a Voice Access Token (rate-limited)
POST /api/dialer/outbound             # TwiML App voice URL — bridges browser → Twilio number
```

---

## Database

Three main tables:

| Table | Purpose |
|-------|---------|
| `calls` | Tracks incoming calls (call_sid, from_number, status, outcome) |
| `task_records` | Tracks Twilio Tasks (task_sid, status, reservation_sid) |
| `voicemails` | Stores voicemail recordings (recording_url, sms_sid) |

---

## Testing

```bash
# All tests
php artisan test

# Specific test file
php artisan test tests/Feature/CallFlowIntegrationTest.php

# With verbose output
php artisan test --verbose

# With coverage
php artisan test --coverage
```

**Test Coverage:**
- 51 total tests
- 200+ assertions
- 100% of happy-path flows
- Duplicate webhook handling
- Out-of-order event handling

---

## Troubleshooting

**"Invalid Twilio Signature" (403)?**
→ Check `APP_URL` in `.env` matches your ngrok URL

**"Task Not Found"?**
→ Verify incoming call → gather-digits → agent routing sequence

**"Agents not receiving SMS"?**
→ Check agents have `phone_number` in database

**See [INCOMING_CALL_FLOW.md](docs/INCOMING_CALL_FLOW.md) for detailed troubleshooting guide.**

---

## Deployment

### Production Checklist

- [ ] Update `APP_URL` to your production domain (HTTPS required)
- [ ] Configure production database (MySQL/PostgreSQL)
- [ ] Set Twilio credentials as environment secrets (not in .env)
- [ ] Run migrations: `php artisan migrate --force`
- [ ] Test one end-to-end call with real agents
- [ ] Set up error logging (Sentry, etc.)
- [ ] Monitor Twilio webhook logs

### Environment Variables for Production

```
TWILIO_ACCOUNT_SID=your_account_sid
TWILIO_AUTH_TOKEN=your_auth_token
TWILIO_PHONE_NUMBER=+1234567890

TWILIO_WORKSPACE_SID=your_workspace_sid
TWILIO_WORKFLOW_SID=your_workflow_sid
TWILIO_TASK_QUEUE_SID=your_queue_sid
TWILIO_ACTIVITY_AVAILABLE=activity_available_sid
TWILIO_ACTIVITY_UNAVAILABLE=activity_unavailable_sid

APP_URL=https://yourdomain.com
APP_DEBUG=false
LOG_CHANNEL=stack
```

---

## Tech Stack

- **Framework:** Laravel 12.12
- **Language:** PHP 8.2
- **Database:** SQLite (dev) / MySQL/PostgreSQL (prod)
- **Third-party:** Twilio SDK v8.12
- **Testing:** PHPUnit + Pest
- **Code Quality:** Pint (Laravel's style fixer)

---

## Key Files

```
app/
  Http/
    Controllers/
      VoiceController.php              # Incoming calls & IVR
      TaskRouterController.php         # Agent assignment
      AgentAvailabilityController.php  # Agent status
    Middleware/
      ValidateTwilioSignature.php      # Verify webhook authenticity
  Services/
    RouteCallToAgentAction.php         # Create TaskRouter Task
    TaskAssignmentHandler.php          # Handle assignment callback
    TaskTimeoutHandler.php             # Detect task timeout
    VoicemailHandler.php               # Record & notify voicemail
    AgentAvailabilityHandler.php       # Toggle agent status
  Models/
    Call.php
    TaskRecord.php
    Voicemail.php

routes/
  api.php                              # All webhook routes

tests/
  Feature/
    VoiceControllerTest.php            # Voice endpoints (9 tests)
    TaskRouterControllerTest.php       # TaskRouter callbacks (6 tests)
    IdempotencyTest.php                # Duplicate handling (10 tests)
    CallFlowIntegrationTest.php        # End-to-end flows (6 tests)
    [+ 4 more test files]

docs/
  INCOMING_CALL_FLOW.md               # Complete system guide ✨
  IMPLEMENTATION_NOTES.md             # Implementation summary
  LaravelImplementation.md            # Architecture decisions
  TaskRouter.md                       # Provisioning spec
  SDD.md                              # Business requirements
```

---

## How Idempotency Works

Twilio retries failed webhooks. Same webhook could arrive twice.

**Solution:** Use unique constraints on Twilio IDs:

```php
// Webhook arrives twice → only one Call record created
$call = Call::firstOrCreate(
    ['call_sid' => $callSid],
    ['from_number' => $from]
);
```

Same approach for Task creation, voicemail SMS, and event processing.

**Result:** System is safe to production. Webhook retries = zero risk of duplication.

---

## How Out-of-Order Events are Handled

Network delays might cause events to arrive out of order:
- Assignment accepted arrives **after** task timeout event
- Task completed arrives **after** agent accepted

**Solution:** Check existing state before applying changes:

```php
if ($taskRecord->status === 'timeout') {
    // Don't accept assignment for already-timed-out task
    return "This call has already been handled.";
}
```

---

## Next Steps

### Want to extend this?

1. **Add call recording** → Add `<Record>` to Dial TwiML
2. **Multiple queues** → Add IVR digit selection for different departments
3. **Voicemail transcription** → Call Twilio Media API after recording
4. **Agent dashboard** → Add real-time WebSocket updates
5. **CRM integration** → Send voicemail notifications to external system

All are drop-in additions. No architecture changes needed.

---

## Questions?

- **How does it work?** → Read [INCOMING_CALL_FLOW.md](docs/INCOMING_CALL_FLOW.md)
- **Why this design?** → Read [LaravelImplementation.md](docs/LaravelImplementation.md)
- **How to use?** → Read [IMPLEMENTATION_NOTES.md](docs/IMPLEMENTATION_NOTES.md)
- **Troubleshooting?** → See troubleshooting section in [INCOMING_CALL_FLOW.md](docs/INCOMING_CALL_FLOW.md)

---

## License

Internal assessment project.
6. Test by calling the number, with at least one agent in `Available` state.

## Automated onboarding (TaskRouter provisioning)

> Not implemented yet.

```bash
php artisan taskrouter:provision
```

Creates (or reuses, if they already exist) the TaskRouter resources in order: Workspace →
Activities (`Available`/`Unavailable`) → TaskQueue → Workflow → one Worker per local `Agent` that
doesn't yet have a `twilio_worker_sid`, using `APP_URL` to register the callback URLs. It's
idempotent (running it again won't duplicate resources) and safe to re-run whenever the public
ngrok URL changes. The resulting SIDs are saved automatically; nothing needs to be copied by hand.

## Tests

```bash
php artisan test
```


