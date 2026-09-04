# Twilio TaskRouter — Implementation guide (PHP / Laravel)

**Purpose:** keep only what is needed to implement, in PHP/Laravel, the goals defined in
[SDD.md](SDD.md). It covers the minimum concepts, a step-by-step configuration, and a
checklist of what must end up configured for the flow to work end to end.

---

## 1. Minimum concepts (only what is used)

| Concept | What it is | Where it is used in this project |
|---|---|---|
| **Workspace** | Container for everything else. Multi-tasking (not legacy). | A single one for the whole project. |
| **Activity** | The Worker's state; determines whether they are eligible for Tasks (`available: true/false`). | At least `Available` (true) and `Unavailable` (false). This is what the agent toggle represents. |
| **Worker** | The agent. Has `attributes` (JSON) and a current Activity. | One Worker per agent. `attributes.contact_uri` is where to call (their real number). |
| **Task Channel** | Separates types of work and defines concurrent capacity per Worker. | The `voice` channel (or `default`), capacity 1 per agent. |
| **TaskQueue** | A queue with an eligibility criterion (`TargetWorkers`) over Workers. | One "Everyone" queue with `TargetWorkers = 1==1` (any available Worker). |
| **Workflow** | Routing rules (`task_routing` JSON) plus the assignment callback URL. | One Workflow with `default_filter` pointing at the TaskQueue, and a `timeout` falling back to voicemail. |
| **Task** | The unit of work (the incoming call). | Created through `<Enqueue workflowSid="...">` in the call's TwiML. |
| **Reservation** | Links Task and Worker when there is a match. Fires the *Assignment Callback* to Laravel. | Laravel replies with a `dequeue` instruction to bridge the call. |

Everything else in the official documentation (Statistics, multi-reservation, LIFO, Flex,
etc.) is **not needed** for this scope.

## 2. Prerequisites

- [ ] A Twilio account (trial is fine) with a rented phone number that has **Voice**
      capability.
- [ ] PHP >= 8.1 and Laravel installed (existing project or `laravel new`).
- [ ] Composer package: `composer require twilio/sdk`.
- [ ] `ngrok` (or your own server with public HTTPS) to expose Laravel to Twilio.
- [ ] A configured database (`.env` with `DB_*`).

## 3. Step by step — configuration in Twilio

This can be done through the Console or through the API/CLI. Order matters: each resource
depends on the previous one.

### 3.1 Create the Workspace
- Console → TaskRouter → Workspaces → Create (choose the empty template, not "FIFO" or
  "Flex").
- Save the `WorkspaceSid` (`WS...`).
- Set `EventCallbackUrl` to `https://<ngrok>/api/taskrouter/events` (optional but
  recommended, point 8 of the SDD).

### 3.2 Create the Activities
The Workspace already ships with `Offline`, `Available` and `Unavailable` by default. For
this project it is enough to **reuse them**:
- `Available` → `available: true` → the "available" state.
- `Unavailable` → `available: false` → the "unavailable" state.
- Save both SIDs (`WA...`) — they are needed for the agent toggle.
- (Optional) create `Wrapping-up` (`available: false`) to use as `post_work_activity_sid`
  after a call ends.

### 3.3 Create the voice Task Channel
- If the `default` channel is used, nothing needs creating. To model it explicitly:
  - Create → `FriendlyName: Voice`, `UniqueName: voice`.
- Save the `TaskChannelSid` (`TC...`) only if a custom one was created.

### 3.4 Create the TaskQueue
- Create → `FriendlyName: Everyone`, `TargetWorkers: 1==1` (any Worker matches).
- `AssignmentActivitySid` = the Activity assigned to the Worker when they **accept** (leave
  the default or use a "Busy" one).
- `ReservationActivitySid` = the Activity while it is "ringing" (leave the default
  "Reserved").
- Save the `TaskQueueSid` (`WQ...`).

### 3.5 Create the Workflow
- Create → `FriendlyName: Voice Workflow`.
- `AssignmentCallbackUrl` = `https://<ngrok>/api/taskrouter/assignment`.
- `FallbackAssignmentCallbackUrl` = `https://<ngrok>/api/taskrouter/assignment-fallback`
  (optional).
- Configuration (`task_routing`):
  ```json
  {
    "task_routing": {
      "filters": [
        {
          "filter_friendly_name": "Voice calls",
          "expression": "1==1",
          "targets": [
            { "queue": "WQ...", "timeout": 20 }
          ]
        }
      ],
      "default_filter": { "queue": "WQ..." }
    }
  }
  ```
  The `timeout: 20` on the target is what prevents an indefinite wait (goal 3.1.5 of the
  SDD): if nobody accepts within 20s the Task falls out of the Workflow and is cancelled,
  and **Laravel must handle that event (`task.canceled`) to redirect the call to
  voicemail** — TaskRouter only controls the Task, not the call, which stays in `<Enqueue>`
  until it is given an instruction.
- Save the `WorkflowSid` (`WW...`).

### 3.6 Create one Worker per agent
- Create → `FriendlyName: Agent 1`.
- `Attributes`:
  ```json
  { "contact_uri": "+1XXXXXXXXXX" }
  ```
  (or `"client:agent1"` when using Twilio Client / the Voice SDK instead of a real phone).
- Initial `ActivitySid` = `Unavailable` (starts unavailable).
- Save the `WorkerSid` (`WK...`) and associate it with the local `Agent` record.

### 3.7 Configure the phone number
- Console → Phone Numbers → the purchased number → *Voice Configuration*:
  - "A call comes in" → Webhook → `https://<ngrok>/api/voice/incoming` → `HTTP POST`.
  - "Call status changes" → `https://<ngrok>/api/voice/status` (optional, for status
    callbacks).

### 3.8 Recommended alternative: provisioning through the API

Everything in 3.1–3.6 is a standard REST call (`POST /Workspaces`,
`POST /Workspaces/{Ws}/Activities`, `POST /Workspaces/{Ws}/TaskQueues`,
`POST /Workspaces/{Ws}/Workflows`, `POST /Workspaces/{Ws}/Workers`). Rather than creating
them by hand in the Console, the plan is to have a **provisioning job/command** that creates
(or reuses) them automatically. This answers the SDD requirement for "instructions to
reproduce the TaskRouter configuration" (section 10). Here is what that job must satisfy:

- **Idempotent:** running it repeatedly must not duplicate resources. Before creating each
  resource it must check whether an equivalent one already exists (for example by a fixed,
  known `FriendlyName`) and reuse it.
- **Creation order:** Workspace → Activities (`Available`/`Unavailable`) → TaskQueue →
  Workflow → one Worker for each local `Agent` that does not yet have a
  `twilio_worker_sid`.
- **Inputs:** Twilio credentials, the app's public URL (for the Workspace and Workflow
  callback URLs), and the list of local `Agent`s still missing a Worker.
- **Outputs:** the SIDs of each resource created or reused (Workspace, Activities,
  TaskQueue, Workflow), and the `twilio_worker_sid` persisted on each `Agent`.
- **Where the SIDs live:** not in `.env` at runtime — in the app's own storage (a settings
  table or a record associated with the `Agent`), so the rest of the system reads them from
  there.
- **Re-runnable when the public URL changes:** if the ngrok URL changes, the job must be
  able to run again to update the Workspace/Workflow callback URLs (the phone number's
  webhook is updated separately, see 3.7).
- **(Optional) teardown counterpart:** a cleanup job, to avoid accumulating test Workspaces
  in test/CI environments.
- **When it runs:** once during initial setup, and again whenever a new agent is added or
  the public URL changes.

## 4. Functional spec on the Laravel side

This is what the application must satisfy — no code yet, just the contract of each piece.

### 4.1 Configuration and credentials
- Twilio credentials (Account SID, Auth Token, purchased number) and the TaskRouter SIDs
  (Workspace, Workflow, TaskQueue, Activities) must live outside the source code
  (environment variables, or the storage used by the provisioning job — see 3.8). Never
  hardcoded or committed (security goal of the SDD, section 9).

### 4.2 Twilio signature validation
- Every endpoint invoked by Twilio (voice, TaskRouter, recording) must validate the
  `X-Twilio-Signature` header against the URL and payload before processing anything. A
  request with an invalid signature is rejected with no side effects.

### 4.3 Entities to persist (per the SDD data model, section 6)
- `Agent`: name, contact number, `twilio_worker_sid`, local state
  (`available`/`unavailable`).
- `Call`: `call_sid`, incoming number, status, associated agent (nullable), `task_sid`,
  outcome (`routed` / `voicemail` / `abandoned`).
- `Task` (local mirror): `task_sid`, associated call, `workflow_sid`, status,
  `reservation_sid`.
- `Voicemail`: associated call, `recording_sid`, `recording_url`, `sms_sid` of the SMS that
  was sent.

### 4.4 Required endpoints (contract, not implementation)

| Endpoint | Triggered by | Must do |
|---|---|---|
| Voice: incoming call | Twilio Voice, the call's first webhook | Offer the caller the two IVR options (talk to an agent / leave a voicemail). |
| Voice: IVR result | The response to the previous `<Gather>` | If an agent is chosen: create the `Task` in the voice Workflow and leave the call waiting; record/update the local `Call` as `queued`. If voicemail is chosen (or the input is invalid): start recording. |
| Voice: recording finished | `<Record>` callback when recording ends | Persist the `Voicemail`, send the agent an SMS with the recording link, mark `Call.outcome = voicemail`. |
| TaskRouter: assignment callback | TaskRouter, when it reserves a Worker for the Task | Decide how to join the call to the Worker (bridging) and which Activity the Worker lands in afterwards; update the local `Task`/`Call` to `assigned`. |
| TaskRouter: Workspace events | TaskRouter, on every state change (`task.created`, `task.canceled`, `reservation.accepted`, `reservation.timeout`, `task.completed`, `worker.activity.update`) | Keep local state in sync; in particular, react to a `task.canceled` from a timeout by redirecting the waiting call to the voicemail flow (the "do not wait indefinitely" requirement). |
| Agents: availability toggle | An agent action in the UI/app (not a Twilio webhook) | Update the Worker's Activity in TaskRouter (`Available` <-> `Unavailable`) and reflect the change in the local agent record. |

### 4.5 Webhook idempotency
- Before applying the effect of any webhook, check whether that event has already been
  processed (deduplicating by `CallSid` / `TaskSid` / `ReservationSid` plus event type), to
  tolerate Twilio retries and out-of-order delivery (goal 3.2.10 of the SDD).

### 4.6 The "caller must not wait indefinitely" requirement
- The Workflow must have a `timeout` configured on its target (step 3.5), so that if nobody
  accepts the Task within that window, TaskRouter cancels it.
- The app must listen for that cancellation event (`task.canceled` from a timeout) and, in
  response, redirect the call that is still waiting into the voicemail flow — using the
  Voice API's ability to update an in-progress call with a new TwiML document.

### 4.7 Testing (spec, no implementation yet)
- Unit: Activity/Agent state transitions, idempotency rules, IVR decisions.
- Feature/integration: simulate real Twilio payloads (fixtures) against each endpoint in
  table 4.4, including duplicate and out-of-order deliveries.

## 5. Final configuration checklist (for the flow to work end to end)

**Twilio Console / API** (by hand in the Console, or through the provisioning job in 3.8)
- [ ] Workspace created (multi-tasking), `EventCallbackUrl` pointing at
      `/api/taskrouter/events`.
- [ ] Activities `Available` (true) and `Unavailable` (false) — SIDs saved.
- [ ] TaskQueue "Everyone" with `TargetWorkers=1==1` — SID saved.
- [ ] Workflow with `default_filter` pointing at the TaskQueue, a `timeout` on the target,
      and `AssignmentCallbackUrl` set to `/api/taskrouter/assignment` — SID saved.
- [ ] One Worker per agent, with the correct `attributes.contact_uri` and an initial
      `Unavailable` Activity — SID saved and linked to the local `Agent` record.
- [ ] Phone number purchased, voice webhook pointing at `/api/voice/incoming` (`HTTP POST`).
- [ ] ngrok running and the public URL updated in the Console (the free plan's URL changes
      on every restart, so every webhook has to be updated again if that happens; with the
      provisioning job it is enough to re-run it with the new public URL to update the
      Workflow/Workspace callback URLs, but the **phone number's** webhook is updated
      separately, through the Incoming Phone Numbers REST API or by hand in the Console).

**Laravel `.env`**
- [ ] `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_NUMBER`
- [ ] `TWILIO_WORKSPACE_SID`, `TWILIO_WORKFLOW_SID`, `TWILIO_TASKQUEUE_SID`
- [ ] `TWILIO_ACTIVITY_AVAILABLE_SID`, `TWILIO_ACTIVITY_UNAVAILABLE_SID`
- [ ] (when using the provisioning command) those last five may live in a settings
      table/cache instead of `.env`, with the command as the only writer.

**Code**
- [ ] Twilio signature validation middleware active on all `/api/voice/*` and
      `/api/taskrouter/*` routes.
- [ ] Migrations and models (`agents`, `calls`, `task_records`, `voicemails`) run.
- [ ] The availability toggle endpoint updates both Twilio (Worker Activity) and the local
      database.
- [ ] The assignment endpoint replies with `dequeue` plus a `post_work_activity_sid` to
      return the agent to "unavailable/wrap-up" after the call.
- [ ] `task.canceled` (timeout) handling redirects the waiting call to voicemail.
- [ ] The recording callback triggers the SMS with the voicemail link.
- [ ] Idempotency implemented (deduplication by `CallSid`/`TaskSid`/`ReservationSid`).
- [ ] Tests covering: availability toggle, Task creation from `<Enqueue>`, assignment
      (`dequeue`), timeout → voicemail, recording → SMS, duplicate webhook.

**Manual validation**
- [ ] Call the number with an agent `Available` → the call connects.
- [ ] Call with the agent `Unavailable` (or no agents) → it falls back to voicemail after
      the configured timeout, and the agent receives the SMS with the link.
- [ ] Changing an agent's availability during an active call does not affect the call in
      progress, but does affect subsequent ones.

## 6. References (only what is used in this guide)

- [Workspace Resource](https://www.twilio.com/docs/taskrouter/api/workspace)
- [Activity Resource](https://www.twilio.com/docs/taskrouter/api/activity)
- [Worker Resource](https://www.twilio.com/docs/taskrouter/api/worker)
- [Task Queue Resource](https://www.twilio.com/docs/taskrouter/api/task-queue)
- [Workflows Overview](https://www.twilio.com/docs/taskrouter/workflow-configuration)
- [Queueing Twilio calls with TaskRouter (TwiML `<Enqueue>`)](https://www.twilio.com/docs/taskrouter/twiml-queue-calls)
- [Handling Assignment Callbacks](https://www.twilio.com/docs/taskrouter/handle-assignment-callbacks)
- [PHP Quickstart](https://www.twilio.com/docs/taskrouter/quickstart/php)
- [Twilio PHP SDK](https://github.com/twilio/twilio-php)
