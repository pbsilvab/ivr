# Browser Dialer (softphone)

A small softphone at `/dialer` that plays the role of an **external customer**. It places a real
call to the app's Twilio number, so the whole incoming flow — IVR, TaskRouter, agent assignment,
voicemail fallback — can be exercised end to end without picking up a physical phone.

## How the call travels

```
Browser (@twilio/voice-sdk)
  │  device.connect({ To: +1TWILIO_NUMBER })
  ▼
Twilio  ──POST──►  /api/dialer/outbound        (voice URL of the TwiML Application)
  │                └─► <Dial callerId="…" answerOnBridge="true"><Number>+1TWILIO…</Number></Dial>
  ▼
PSTN leg dials the Twilio number
  │
  ▼
Twilio  ──POST──►  /api/voice/incoming          (the number's own webhook — unchanged)
                   └─► <Gather> "Press 1 … press 2 …"
                          │
                     keypad sends DTMF from the browser
                          │
                          ├── 1 ─► /api/voice/gather-digits ─► TaskRouter Task ─► agent
                          └── 2 ─► voicemail recording
```

Because the leg really leaves and re-enters Twilio, nothing in the existing incoming flow had to
change: `/api/voice/incoming` sees an ordinary inbound call. It also means the call is billed as
two legs (outbound PSTN + inbound), so keep test calls short.

## Setup

```bash
# 1. Point APP_URL at your public (ngrok) URL, then:
php artisan dialer:provision
```

The command creates — or reuses, and refreshes the voice URL of — a TwiML Application named
`Browser Dialer`, and offers to create the API Key pair that Access Tokens must be signed with.
Copy what it prints into `.env`:

```
TWILIO_TWIML_APP_SID=AP…
TWILIO_API_KEY_SID=SK…
TWILIO_API_KEY_SECRET=…
```

The API Key secret is shown **once** — Twilio never returns it again.

```bash
# 2. Build the front end
npm install
npm run build      # or `npm run dev` while working on it

# 3. Open the page through the https ngrok URL (getUserMedia needs a secure context)
https://<your-ngrok-host>/dialer
```

Re-run `php artisan dialer:provision` whenever the ngrok URL changes — it only updates the voice
URL of the existing application.

## Using it

| Control | Behaviour |
|---|---|
| **Call** | Dials the number in the field (pre-filled with `TWILIO_NUMBER`) |
| **Keypad** | Types into the field while idle; sends DTMF once the call is up |
| **1** during the call | IVR routes to an agent through TaskRouter |
| **2** during the call | IVR goes straight to voicemail |
| **Mute** / **Hang up** | Standard softphone controls |

The right-hand panel logs every SDK event with timestamps and shows the `CallSid`, which is the
same SID stored on the `calls` row — handy when cross-checking against the database or the Twilio
console.

To see the *no agent available* branch, leave every agent `unavailable` and press 1: the Task
times out after 20s and the call falls back to voicemail.

## Configuration

| Variable | Default | Purpose |
|---|---|---|
| `TWILIO_TWIML_APP_SID` | — | Application the Voice grant is pinned to |
| `TWILIO_API_KEY_SID` / `TWILIO_API_KEY_SECRET` | — | Signs the Access Token |
| `TWILIO_DIALER_CALLER_ID` | `TWILIO_NUMBER` | Caller ID the simulated customer shows |
| `TWILIO_DIALER_ALLOWED_NUMBERS` | `TWILIO_NUMBER` | Comma-separated destinations the dialer may call |
| `TWILIO_DIALER_TOKEN_TTL` | `3600` | Access Token lifetime in seconds |

## Why the allowlist

`/api/dialer/token` is unauthenticated — the demo has no login — so anyone who can reach the page
can mint a token. Two things keep that from becoming a way to make calls on your account:

- The Voice grant is **outgoing-only** and pinned to this one TwiML Application, so a token can do
  nothing but hit `/api/dialer/outbound`.
- `/api/dialer/outbound` refuses any destination outside `TWILIO_DIALER_ALLOWED_NUMBERS`, which
  defaults to just your own Twilio number, and the token route is rate-limited to 20 requests per
  minute per IP.

Before exposing this anywhere long-lived, put the page and the token route behind auth.
