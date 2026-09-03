# TaskRouter Voice App

A simplified VoIP application in Laravel: routes incoming calls to an agent through **Twilio
TaskRouter** (never a direct dial) and, when no agent is available, records a voicemail and
notifies the agent by SMS.

## Requirements

- PHP >= 8.2 and Composer
- A Twilio account (trial works) with your own phone number with **Voice** capability
- [ngrok](https://ngrok.com) (or your own server with public HTTPS) to expose the app to Twilio

The database is SQLite (included in the repo, no external server required).

## Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

## Required environment variables / API keys

In `.env`:

| Variable | Where it comes from |
|---|---|
| `TWILIO_ACCOUNT_SID` | Twilio Console → main dashboard |
| `TWILIO_AUTH_TOKEN` | Twilio Console → main dashboard |
| `TWILIO_NUMBER` | Your purchased Twilio number, with Voice capability |
| `TWILIO_WORKSPACE_SID` | Filled in by the provisioning command (see below) |
| `TWILIO_WORKFLOW_SID` | Filled in by the provisioning command |
| `TWILIO_TASKQUEUE_SID` | Filled in by the provisioning command |
| `TWILIO_ACTIVITY_AVAILABLE_SID` | Filled in by the provisioning command |
| `TWILIO_ACTIVITY_UNAVAILABLE_SID` | Filled in by the provisioning command |

The first three are filled in by hand before starting the app. The last five (the TaskRouter SIDs)
don't need to be created or copied by hand in the Console: the automated onboarding generates them.

## Running the system locally

1. `php artisan serve` (defaults to `http://localhost:8000`).
2. In another terminal: `ngrok http 8000`.
3. Copy the public ngrok URL into `APP_URL` in `.env`.
4. Run the automated onboarding (see below) to create/update the TaskRouter resources with the
   correct callback URLs.
5. Point the Twilio number's voice webhook to `<APP_URL>/api/voice/incoming` (the only manual step
   in the Twilio Console; the phone number's REST API is configured separately).
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


