import { Device } from '@twilio/voice-sdk';

const config = JSON.parse(document.getElementById('dialer-config').textContent);

const el = {
    destination: document.getElementById('destination'),
    keypad: document.getElementById('keypad'),
    keypadHint: document.getElementById('keypad-hint'),
    callButton: document.getElementById('call-button'),
    hangupButton: document.getElementById('hangup-button'),
    muteButton: document.getElementById('mute-button'),
    statusDot: document.getElementById('status-dot'),
    statusLabel: document.getElementById('status-label'),
    callTimer: document.getElementById('call-timer'),
    callSid: document.getElementById('call-sid'),
    errorMessage: document.getElementById('error-message'),
    log: document.getElementById('log'),
};

const STATES = {
    idle: { label: 'Idle', dot: 'bg-slate-300' },
    connecting: { label: 'Connecting…', dot: 'bg-amber-400 animate-pulse' },
    ringing: { label: 'Ringing…', dot: 'bg-amber-400 animate-pulse' },
    open: { label: 'In call', dot: 'bg-emerald-500' },
};

let device = null;
let activeCall = null;
let state = 'idle';
let timerId = null;
let startedAt = null;

// --- UI -------------------------------------------------------------------

function log(message, tone = 'neutral') {
    const colors = { neutral: 'text-slate-600', good: 'text-emerald-700', bad: 'text-rose-700' };
    const time = new Date().toLocaleTimeString();
    const item = document.createElement('li');
    item.className = colors[tone] ?? colors.neutral;
    item.textContent = `${time}  ${message}`;
    el.log.prepend(item);
}

function showError(message) {
    el.errorMessage.textContent = message;
    el.errorMessage.hidden = false;
    log(message, 'bad');
}

function clearError() {
    el.errorMessage.hidden = true;
}

function setState(next) {
    state = next;
    const { label, dot } = STATES[next];

    el.statusLabel.textContent = label;
    el.statusDot.className = `size-2.5 rounded-full ${dot}`;

    const inCall = next !== 'idle';
    el.callButton.hidden = inCall;
    el.hangupButton.hidden = !inCall;
    el.muteButton.hidden = next !== 'open';
    el.destination.disabled = inCall;
    el.keypadHint.textContent = inCall
        ? 'Keypad sends DTMF — 1 for an agent, 2 for voicemail'
        : 'Type the number to dial';

    if (next === 'open') {
        startTimer();
    }
    if (next === 'idle') {
        stopTimer();
        el.muteButton.textContent = 'Mute';
    }
}

function startTimer() {
    startedAt = Date.now();
    timerId = setInterval(() => {
        const seconds = Math.floor((Date.now() - startedAt) / 1000);
        const mm = String(Math.floor(seconds / 60)).padStart(2, '0');
        const ss = String(seconds % 60).padStart(2, '0');
        el.callTimer.textContent = `${mm}:${ss}`;
    }, 1000);
}

function stopTimer() {
    clearInterval(timerId);
    timerId = null;
    el.callTimer.textContent = '';
}

// --- Twilio ---------------------------------------------------------------

async function fetchToken() {
    const response = await fetch(config.tokenUrl, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: '{}',
    });

    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(body.error ?? `Token request failed (${response.status})`);
    }

    return body;
}

async function ensureDevice() {
    if (device) {
        return device;
    }

    const { token, identity } = await fetchToken();

    device = new Device(token, { codecPreferences: ['opus', 'pcmu'], logLevel: 'error' });

    device.on('error', (error) => showError(`Device error: ${error.message}`));

    // The token outlives a short demo call, but refresh anyway so a page left open keeps working.
    device.on('tokenWillExpire', async () => {
        try {
            const refreshed = await fetchToken();
            device.updateToken(refreshed.token);
            log('Access token refreshed');
        } catch (error) {
            showError(error.message);
        }
    });

    log(`Registered as client:${identity}`, 'good');

    return device;
}

function wireCall(call) {
    call.on('ringing', () => {
        setState('ringing');
        log('Ringing');
    });

    call.on('accept', () => {
        setState('open');
        const sid = call.parameters.CallSid;
        el.callSid.textContent = sid ?? '';
        log(`Answered — CallSid ${sid ?? 'unknown'}`, 'good');
    });

    call.on('disconnect', () => {
        log('Call ended');
        activeCall = null;
        setState('idle');
    });

    call.on('cancel', () => {
        log('Call cancelled');
        activeCall = null;
        setState('idle');
    });

    call.on('reject', () => {
        log('Call rejected', 'bad');
        activeCall = null;
        setState('idle');
    });

    call.on('error', (error) => showError(`Call error: ${error.message}`));
}

async function startCall() {
    clearError();

    const to = el.destination.value.trim();

    if (!/^\+[1-9]\d{7,14}$/.test(to)) {
        showError('Enter the destination in E.164 format, e.g. +15551234567.');
        return;
    }

    if (!window.isSecureContext) {
        showError('Microphone access needs HTTPS (or localhost). Open the page through your ngrok https URL.');
        return;
    }

    setState('connecting');

    try {
        // Ask up front so a denied microphone reports itself clearly instead of failing mid-connect.
        await navigator.mediaDevices.getUserMedia({ audio: true });

        const dev = await ensureDevice();

        log(`Dialing ${to}`);
        activeCall = await dev.connect({ params: { To: to } });
        wireCall(activeCall);
    } catch (error) {
        activeCall = null;
        setState('idle');
        showError(error.message ?? String(error));
    }
}

function hangUp() {
    if (activeCall) {
        activeCall.disconnect();
    } else {
        setState('idle');
    }
}

function toggleMute() {
    if (!activeCall) {
        return;
    }

    const muted = !activeCall.isMuted();
    activeCall.mute(muted);
    el.muteButton.textContent = muted ? 'Unmute' : 'Mute';
    log(muted ? 'Microphone muted' : 'Microphone unmuted');
}

function pressDigit(digit) {
    // Any live call, not just an accepted one: on a trial account Twilio plays a "press any key"
    // message before bridging, and with answerOnBridge the call is still `ringing` at that point.
    if (activeCall) {
        activeCall.sendDigits(digit);
        log(`Sent DTMF ${digit}`);
        return;
    }

    if (state === 'idle') {
        el.destination.value += digit;
    }
}

// --- Wiring ---------------------------------------------------------------

el.callButton.addEventListener('click', startCall);
el.hangupButton.addEventListener('click', hangUp);
el.muteButton.addEventListener('click', toggleMute);

el.keypad.addEventListener('click', (event) => {
    const button = event.target.closest('[data-digit]');
    if (button) {
        pressDigit(button.dataset.digit);
    }
});

document.addEventListener('keydown', (event) => {
    if (event.target === el.destination) {
        return;
    }
    if (/^[0-9*#]$/.test(event.key)) {
        pressDigit(event.key);
    }
});

if (!config.configured) {
    showError('Twilio dialer credentials are missing. Run `php artisan dialer:provision` first.');
    el.callButton.disabled = true;
}

setState('idle');
