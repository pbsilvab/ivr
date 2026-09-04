# AI Usage

Disclosure required by [docs/SDD.md](docs/SDD.md) §3.4, goal 15: which tools were used, on which
parts of the project, and in what way.

## Tools

**Claude Code** (Anthropic), used as a terminal-based development assistant throughout — from
turning the assignment brief into a design document, through implementation and tests, to
debugging against live Twilio.

## How the work was structured

The project was not generated in one pass. It ran in phases, each producing an artefact that
became the context for the next.

**1. Brief → SDD.** The challenge PDF was turned into [docs/SDD.md](docs/SDD.md), translating the
requirements into numbered goals, non-goals, a data model and acceptance criteria. This fixed the
scope before any code existed, and gave something concrete to measure against afterwards (see
[DELIVERABLES.md](DELIVERABLES.md)).

**2. Twilio documentation → narrowed scope.** The official TaskRouter documentation was distilled
into [docs/TaskRouter.md](docs/TaskRouter.md), keeping only the concepts the SDD actually needs —
Workspace, Activities, Workers, TaskQueue, Workflow, Tasks, Reservations — and dropping everything
else (Statistics, multi-reservation, LIFO, Flex). Cutting the surface area before implementing
avoided dragging in complexity the scope never asked for.

**3. Implementation plan.** With those two documents as context,
[docs/LaravelImplementation.md](docs/LaravelImplementation.md) laid out how the design maps onto
Laravel: which services and endpoints are needed, and why.

**4. Phased implementation.** Only then was the code written, in small sequential commits.

**5. Provisioning and dialer, as added value.** Automating the creation of the TaskRouter
resources and building a browser softphone were not among the brief's minimums. They were added so
the configuration is reproducible with one command instead of by hand in the Console, and so the
whole flow can be exercised without depending on real phones.

## Where

| Phase | PR | What it produced |
|---|---|---|
| TaskRouter provisioning | [#1](https://github.com/pbsilvab/ivr/pull/1) | `Agent`, settings store, `TwilioClientFactory`, `taskrouter:provision`, an `Http::fake()`-compatible HTTP client |
| Incoming call flow | [#2](https://github.com/pbsilvab/ivr/pull/2) | Models and migrations, signature validation, IVR, routing to TaskRouter, voicemail + SMS, timeout, availability toggle, idempotency, integration tests |
| Browser dialer | [#3](https://github.com/pbsilvab/ivr/pull/3) | Softphone at `/dialer`, agent console, and the TaskRouter contract fixes described below |
| Channel capacity | [#4](https://github.com/pbsilvab/ivr/pull/4) | Completing Tasks left in `wrapping` |
| Agent creation | [#5](https://github.com/pbsilvab/ivr/pull/5) | Creating agents from the console, geographic permissions, Caller ID verification |

Provisioning came first chronologically, but conceptually it is infrastructure around the flow the
brief describes rather than part of it.

## What had to be corrected

The most relevant part of this disclosure: **the generated code contained errors that only
surfaced by running it against live Twilio.** All of them are in the commit history.

- **Signature validation concatenated parameters in arrival order.** Twilio signs them sorted by
  key. Every new webhook returned 403. Replaced with the SDK's own validator.
- **The assignment callback returned TwiML.** TaskRouter expects JSON instructions (`dequeue`),
  not TwiML. The reservation was never accepted, it timed out, and Twilio demoted the Worker to
  `Offline` on its own — leaving an agent the UI showed as available that could receive nothing.
- **Two Tasks were created per call:** one over REST and another by the `<Enqueue>`. The local
  record pointed at the first, which held no call leg at all.
- **`taskAttributes` as an attribute of `<Enqueue>`** does not exist; attributes belong in the
  child `<Task>` noun. Twilio dropped it silently and the Task arrived without the `callSid`.
- **Tasks were never completed** when a call ended. They sat in `wrapping` holding the Worker's
  channel capacity, so the second call found nobody free.
- **Unguarded array reads** on webhook payloads turned every non-Task Workspace event into a 500.

One pattern runs through nearly all of them: **the tests passed.** They were written by the same
process that wrote the code, so they reproduced the same wrong assumptions — the signature was
checked against an equally mis-ordered computation, the assignment test asserted the very `<Dial>`
the code emitted — and the two confirmed each other. None of these was caught by reading the code
or running the suite; they came out of the Twilio debugger, of inspecting real Task and Worker
state through the API, and of placing actual calls. Fixing each one meant rewriting its tests
against the real contract rather than the one the code assumed.

## What was not delegated

- **Decisions about the Twilio account.** Enabling a country's geographic permissions, triggering
  Caller ID verification calls and changing account configuration were always left to a human,
  given their cost and fraud implications.
- **Functional verification.** That the flow works was confirmed with real calls and by checking
  state in Twilio, not by a green test suite.
- **Scope.** What was in and what was out — no editing or deleting agents, no automating number
  verification, no renaming classes outside the task at hand — was decided case by case.
