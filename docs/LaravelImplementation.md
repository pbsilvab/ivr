# Laravel implementation plan

**Purpose:** describe how the solution would be structured in Laravel — layers, packages,
files and their responsibilities — to meet the goals in [SDD.md](SDD.md) using the concepts
in [TaskRouter.md](TaskRouter.md). This is an architecture plan, not code.

---

## 0. Guiding principle: do not over-design

The SDD is explicit in its **Non-goals** (section 4): no multi-tenancy, no complex skills,
no production infrastructure. This is an assessment with a single flow — one call, a
two-option IVR, one Workflow, a handful of agents. This plan matches that scope: standard
Laravel conventions where they suffice, and an extra layer only where it solves a concrete
problem for this project (testing business rules without HTTP, avoiding double-processing a
webhook). No layers are added "just in case" (full Domain-Driven Design, an event bus,
mandatory queues) that add nothing to the SDD goals and do add surface area to explain in
the review.

## 1. Stack and packages

- **Laravel** (current LTS) + PHP >= 8.1.
- **`twilio/sdk`** (Composer): Twilio REST client (Voice, Messaging, TaskRouter).
- **Database:** SQLite is enough at this scope (less local setup); MySQL/Postgres if
  preferred.
- **Queues:** `QUEUE_CONNECTION=sync` by default — for the volume of one call at a time,
  real queue infrastructure is unnecessary. The two outbound Twilio calls that can fail
  (SMS and call redirection) are protected with Laravel's `retry()` helper inside the
  Action itself, not with a dedicated queue system. If asynchronous processing needs
  demonstrating, that is a one-line change (`QUEUE_CONNECTION=database` + `dispatch()`),
  not a reason to design Jobs from day one.
- **`laravel/pint`** or similar for code style (good practice per SDD section 3.3).
- **Testing:** PHPUnit or Pest, with `Http::fake()` to simulate outbound calls to the Twilio
  API, and JSON fixtures to simulate inbound Twilio payloads.
- **ngrok** purely as a local development tool (not part of the app).

## 2. Proposed folder structure

```
app/
  Models/
    Agent.php
    Call.php
    TaskRecord.php
    Voicemail.php
  Actions/
    Calls/RouteCallToAgentAction.php               # creates the Task in TaskRouter
    Calls/StartVoicemailAction.php                 # builds the <Record> TwiML
    Calls/CompleteVoicemailAction.php              # persists the voicemail and sends the SMS
    Calls/RedirectWaitingCallToVoicemailAction.php # the "do not wait indefinitely" goal
    Agents/ToggleAgentAvailabilityAction.php
  Http/
    Controllers/
      VoiceController.php        # incoming, gather, recording
      TaskRouterController.php   # assignment, events
      AgentAvailabilityController.php
    Middleware/
      ValidateTwilioSignature.php
  Console/
    Commands/
      ProvisionTaskRouterCommand.php   # spec in TaskRouter.md section 3.8
  Services/
    TwilioClientFactory.php      # thin wrapper over twilio/sdk + config
config/
  services.php        # Twilio credentials + TaskRouter SIDs
database/
  migrations/          # agents, calls, task_records, voicemails
routes/
  api.php              # all webhook routes + the availability toggle
tests/
  Feature/Voice/...
  Feature/TaskRouter/...
  Unit/Actions/...
  Fixtures/twilio/*.json   # sample payloads (voice, assignment, events, recording)
```

Models in `app/Models` (standard Laravel convention) and business logic in `app/Actions`,
grouped by feature. That is the minimum separation that allows testing the rules (which
Worker a call routes to, when it falls back to voicemail, idempotency) without a real HTTP
server or a real Twilio, without introducing a `Domain` folder parallel to what Laravel
already provides.

## 3. Configuration

- `config/services.php`: a `twilio` section with `sid`, `token`, `number`, and the
  TaskRouter SIDs (`workspace_sid`, `workflow_sid`, `task_queue_sid`,
  `activity_available_sid`, `activity_unavailable_sid`) read from `.env` (or, when using the
  provisioning job, from the storage that job updates).
- `.env.example` documented with all of the above, plus `APP_URL` (which must be the public
  ngrok URL in development, because the callback URLs registered in Twilio are built from
  it).
- `TwilioClientFactory` centralises building the `twilio/sdk` client from that config, so it
  is not instantiated repeatedly in every Controller/Action.

## 4. Models and migrations

Those from the SDD data model (section 6), with no extra supporting tables:

- `agents`: name, contact number, `twilio_worker_sid`, `status`.
- `calls`: `call_sid` (**unique**), incoming number, `status`, `agent_id` (nullable),
  `task_sid`, `outcome`.
- `task_records`: `task_sid` (**unique**), `call_id`, `workflow_sid`, `status`,
  `reservation_sid`.
- `voicemails`: `call_id`, `recording_sid`, `recording_url`, `sms_sid`.

**Idempotency without a separate table:** since every Twilio event carries a stable
identifier (`CallSid`, `TaskSid`, `ReservationSid`) that already maps one-to-one to a row in
`calls`/`task_records`, a `unique` constraint plus `firstOrCreate`/`updateOrCreate` on those
columns is enough to avoid duplicating or reprocessing the same event (goal 3.2.10 of the
SDD). A generic `processed_webhooks` table would only be justified if the same event could
affect multiple unrelated entities, which is not the case here.

## 5. Application layers

### 5.1 Controllers (thin, no business logic)
Three controllers grouped by area rather than one per endpoint — fewer files, the same
separation of concerns, because each method is still a single thing:
- `VoiceController`: `incoming`, `gather`, `recording`.
- `TaskRouterController`: `assignment`, `events`.
- `AgentAvailabilityController`: `toggle`.

Each method only translates the Twilio request into an Action call, and returns TwiML or
JSON.

### 5.2 Middleware
`ValidateTwilioSignature`: applied to the Twilio route group (voice + TaskRouter), **not** to
the availability toggle endpoint (that one is protected by the app's normal auth).

### 5.3 Actions (one responsibility each, testable without HTTP)
They encapsulate the SDD rules (sections 7 and 8): create the Task in TaskRouter, build the
assignment instruction, process the recording result **and send the SMS in the same Action**
(a single side effect with a single consumer does not need a domain event plus a listener),
redirect a waiting call to voicemail, change an agent's availability. Outbound calls to the
Twilio API inside these Actions use `retry()` to tolerate transient failures.

### 5.4 Console
`ProvisionTaskRouterCommand`: implements the provisioning spec described in
`TaskRouter.md` section 3.8 (idempotent; creates or reuses Workspace → Activities →
TaskQueue → Workflow → Workers).

## 6. Routes

Grouped under `routes/api.php`:
- A `voice.*` group with the Twilio signature middleware: incoming, gather, recording.
- A `taskrouter.*` group with the Twilio signature middleware: assignment, events.
- An `agents.availability` route with the app's normal auth middleware (not Twilio).

Name every route (`->name(...)`), because the provisioning job needs to generate absolute
URLs from them to register as callback URLs in Twilio.

## 7. Testing

- **Unit:** pure Actions (no Laravel HTTP) — IVR branching rules, assignment instruction
  construction, idempotency, availability transitions.
- **Feature:** one test per endpoint (the six methods across the three controllers), using
  JSON fixtures with real Twilio payloads (including duplicates and out-of-order deliveries),
  and `Http::fake()` for outbound Twilio API calls, so the tests do not depend on a real
  Twilio account.
- **Signature fixture:** for `ValidateTwilioSignature` tests, generate valid and invalid test
  signatures with the same algorithm the SDK uses.

## 8. Local development flow

1. Start the app (`serve` or Sail/Docker). No queue worker is needed with `sync`.
2. Start `ngrok http <port>` and update `APP_URL` with that URL.
3. Run the provisioning command to create/update the TaskRouter resources with the correct
   callback URLs.
4. Point the purchased number's voice webhook at the ngrok URL (the one thing the
   provisioning command does not cover automatically, as documented in `TaskRouter.md`).
5. Test by calling the number, with at least one agent `Available` and another
   `Unavailable`.

## 9. Suggested implementation order

1. Migrations and models.
2. `TwilioClientFactory` and config.
3. `ProvisionTaskRouterCommand` (to get real SIDs as early as possible and be able to test
   against real Twilio).
4. Signature validation middleware.
5. Incoming call flow → IVR → Task creation (no agent yet; verify the Task is created).
6. Assignment callback → bridging (test with a real available Worker).
7. Voicemail: recording → persistence → SMS.
8. Timeout → redirect to voicemail (the most delicate case; test explicitly with no
   available agents).
9. Availability toggle end to end.
10. Idempotency plus duplicate/out-of-order tests.
11. Final documentation (`README`, `AI_USAGE.md`) — already covered as a process goal in the
    SDD.

## 10. What was simplified relative to the first version of this plan

| Removed | Why |
|---|---|
| A `Domain/*` folder with Models and Actions nested by subdomain | Duplicated Laravel's standard convention (`app/Models`) with no real benefit at this scale. |
| Internal `Events` + `Listeners` (`CallSentToVoicemail`, etc.) | A single side effect (sending an SMS) with a single consumer does not justify an event bus; the Action does it directly. |
| Mandatory queued `Jobs` + `QUEUE_CONNECTION=database/redis` | With one call at a time, `sync` + `retry()` gives the same resilience without requiring a running worker. |
| A generic `processed_webhooks` table | Twilio's identifiers already map one-to-one to existing rows; a `unique` column plus `firstOrCreate` is enough. |
| `Support/AssignmentInstructionBuilder` and `Support/WorkflowEventDispatcher` | With around five event types, a `match` inside the relevant Action is simpler than a dedicated dispatcher class. |
| Six controllers (one per endpoint) | Grouped into three by area (Voice, TaskRouter, Agents) — the same per-method isolation, fewer files to navigate. |

None of this is final: if a second consumer of the same effect appears during real
implementation, or several concurrent calls need handling, those are incremental changes on
top of this base — there is no need to anticipate them now.

---

## 11. Current implementation

This plan was implemented in phases, delivering:

- Full test coverage of the flows, including duplicate webhooks and out-of-order events.
- A clean architecture: thin Controllers → Services → Models.
- Complete documentation ([INCOMING_CALL_FLOW.md](INCOMING_CALL_FLOW.md),
  [IMPLEMENTATION_NOTES.md](IMPLEMENTATION_NOTES.md)).

### Files created (per the plan)

**Models** (as in section 4): `Call`, `TaskRecord`, `Voicemail`, `Agent`.

**Controllers** (as in section 5.1 — three, not six):
- `app/Http/Controllers/VoiceController.php` (incoming, gather, recording)
- `app/Http/Controllers/TaskRouterController.php` (assignment, events)
- `app/Http/Controllers/AgentAvailabilityController.php` (toggle, console, agent creation)

**Services/Actions** (as in section 5.3):
- `app/Services/RouteCallToAgentAction.php`
- `app/Services/TaskAssignmentHandler.php`
- `app/Services/TaskTimeoutHandler.php`
- `app/Services/VoicemailHandler.php`
- `app/Services/AgentAvailabilityHandler.php`

**Middleware** (as in section 5.2): `app/Http/Middleware/ValidateTwilioSignature.php`.

**Database** (as in section 4): migrations for `calls`, `task_records`, `voicemails`.

### Differences from the plan

| Item | Plan | Implementation | Reason |
|---|---|---|---|
| `Actions/` folder | Proposed | Became `Services/` | Laravel service-layer convention |
| `TwilioClientFactory` | Mentioned | Bound in `AppServiceProvider` | Simpler, same result |
| Idempotency | Basic (unique + `firstOrCreate`) | Extended with state checks for out-of-order delivery | Retries and reordering are real, not hypothetical |
| Tests | ~30 expected | Considerably more | Each Twilio contract bug found required pinning its real behaviour |

### Key findings

1. **Simple idempotency is not enough.** `unique` + `firstOrCreate` came first. Then webhooks
   turned out to arrive **out of order** (an assignment after a timeout), which needed
   explicit state checks.

2. **Passing tests are not evidence of a correct contract.** Several Twilio integration bugs
   had green tests, because the tests were written against the same assumption as the code.
   They surfaced only against real Twilio traffic — see [AI_USAGE.md](../AI_USAGE.md).

3. **Twilio signatures are mandatory.** Without `ValidateTwilioSignature`, any client could
   forge webhooks. Implemented early; it is not optional.

4. **ngrok is required in development.** Twilio rejects localhost, so webhooks cannot be
   tested locally without a public tunnel.

### How to read the implemented code

1. **Entry point:** [routes/api.php](../routes/api.php) — defines every endpoint.
2. **Happy path:** `VoiceController@incoming` → `gatherDigits` (digit 1) →
   `RouteCallToAgentAction` → assignment callback → `dequeue`.
3. **Fallback path:** `TaskTimeoutHandler` → `VoicemailHandler` → SMS notification.
4. **Idempotency:** look for `firstOrCreate`, `updateOrCreate` and state checks in the
   Services.
5. **Tests:** see [tests/Feature/IdempotencyTest.php](../tests/Feature/IdempotencyTest.php)
   and [tests/Feature/CallFlowIntegrationTest.php](../tests/Feature/CallFlowIntegrationTest.php).

### Next steps (not implemented, additive)

- Call recording (add `<Record>` to the Dial TwiML)
- Multiple queues (add options to the IVR)
- Voicemail transcription (integrate the Twilio Media API)
- Agent dashboard (WebSockets for real-time updates)
- CRM webhooks (notify external systems)

All are additive and require no changes to the base architecture.

### Documentation references

- [INCOMING_CALL_FLOW.md](INCOMING_CALL_FLOW.md) — the full system, diagrams, troubleshooting
- [IMPLEMENTATION_NOTES.md](IMPLEMENTATION_NOTES.md) — what was built, key decisions, how to extend
- [DIALER.md](DIALER.md) — the browser softphone
- [../README.md](../README.md) — quick start and overview
