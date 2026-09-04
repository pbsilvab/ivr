const errorMessage = document.getElementById('error-message');

function showError(message) {
    errorMessage.textContent = message;
    errorMessage.hidden = false;
}

function clearError() {
    errorMessage.hidden = true;
}

/**
 * Repaint one row from the status the server reports, so the switch can never drift from what
 * TaskRouter actually thinks the Worker's activity is.
 */
function render(row, status) {
    const available = status === 'available';

    const pill = row.querySelector('[data-status]');
    pill.textContent = available ? 'Available' : 'Unavailable';
    pill.className = `rounded-full px-2.5 py-1 text-xs font-medium ${
        available ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'
    }`;

    const toggle = row.querySelector('[data-toggle]');
    toggle.setAttribute('aria-pressed', available ? 'true' : 'false');
    toggle.className = `relative h-6 w-11 shrink-0 rounded-full transition disabled:cursor-not-allowed disabled:opacity-40 ${
        available ? 'bg-emerald-600' : 'bg-slate-300'
    }`;

    row.querySelector('[data-knob]').className =
        `absolute top-0.5 size-5 rounded-full bg-white shadow transition-all ${
            available ? 'left-[1.375rem]' : 'left-0.5'
        }`;
}

async function toggle(row) {
    const id = row.dataset.agent;
    const button = row.querySelector('[data-toggle]');

    clearError();
    button.disabled = true;

    try {
        const response = await fetch(`/api/agents/${id}/availability/toggle`, {
            method: 'POST',
            headers: { Accept: 'application/json' },
        });

        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(body.error ?? `Toggle failed (${response.status})`);
        }

        render(row, body.status);
    } catch (error) {
        showError(error.message ?? String(error));
    } finally {
        button.disabled = false;
    }
}

/**
 * Ask Twilio to verify the agent's number as a Caller ID. This rings the number immediately and
 * the person answering has to key in the code, so warn before triggering it and keep the code on
 * screen afterwards — Twilio never shows it again.
 */
async function verifyNumber(row) {
    const id = row.dataset.agent;
    const button = row.querySelector('[data-verify]');
    const output = row.querySelector('[data-verify-code]');

    const phone = row.querySelector('.font-mono').textContent.trim();

    if (!window.confirm(`Twilio will call ${phone} now. Whoever answers has to key in the code shown here. Continue?`)) {
        return;
    }

    clearError();
    button.disabled = true;
    button.textContent = 'Calling…';

    try {
        const response = await fetch(`/api/agents/${id}/verify-number`, {
            method: 'POST',
            headers: { Accept: 'application/json' },
        });

        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
            const reason = body.error ?? `Verification failed (${response.status})`;

            throw new Error(body.hint ? `${reason} — ${body.hint}` : reason);
        }

        output.innerHTML = `Twilio is calling ${phone}. Enter this code on the keypad: <strong class="font-mono text-sm">${body.code}</strong>`;
        output.hidden = false;
        button.remove();
    } catch (error) {
        showError(error.message ?? String(error));
        button.disabled = false;
        button.textContent = 'Verify';
    }
}

document.getElementById('agents').addEventListener('click', (event) => {
    const toggleButton = event.target.closest('[data-toggle]');

    if (toggleButton && !toggleButton.disabled) {
        toggle(toggleButton.closest('[data-agent]'));
        return;
    }

    const verifyButton = event.target.closest('[data-verify]');

    if (verifyButton && !verifyButton.disabled) {
        verifyNumber(verifyButton.closest('[data-agent]'));
    }
});

// --- Adding an agent ------------------------------------------------------

const form = document.getElementById('new-agent');
const nameInput = document.getElementById('agent-name');
const phoneInput = document.getElementById('agent-phone');
const submitButton = document.getElementById('agent-submit');
const numberStatus = document.getElementById('number-status');

const E164 = /^\+[1-9]\d{7,14}$/;

let readiness = null;

function statusLine(text, tone) {
    const tones = {
        good: 'text-emerald-800',
        warn: 'text-amber-800',
        bad: 'text-rose-800',
        muted: 'text-slate-600',
    };

    return `<p class="${tones[tone] ?? tones.muted}">${text}</p>`;
}

/**
 * Show what Twilio thinks of the number. Two account-level settings decide whether a call to it
 * can be placed at all, and neither is visible from the app — surfacing them here beats finding
 * out from a call that silently fell through to voicemail.
 */
function renderReadiness(check) {
    const parts = [];
    let tone = 'bg-slate-50 ring-1 ring-slate-200';

    if (!check.valid) {
        numberStatus.className = 'mt-3 rounded-lg p-3 text-sm bg-rose-50 ring-1 ring-rose-200';
        numberStatus.innerHTML = statusLine('Twilio does not recognise this number.', 'bad');
        numberStatus.hidden = false;
        return;
    }

    const country = check.countryName ?? check.countryCode;

    if (check.callingEnabled === false) {
        tone = 'bg-amber-50 ring-1 ring-amber-200';
        parts.push(statusLine(`Calls to ${country} are <strong>not authorised</strong> on this account.`, 'warn'));
        parts.push(`
            <label class="mt-2 flex items-start gap-2 text-amber-900">
                <input id="enable-country" type="checkbox" class="mt-0.5">
                <span>Enable calling to ${country} — this changes Voice permissions for the whole
                account, not just this agent.</span>
            </label>
        `);
    } else if (check.callingEnabled === true) {
        parts.push(statusLine(`Calls to ${country} are authorised.`, 'good'));
    } else {
        parts.push(statusLine(`Could not read calling permissions for ${country}.`, 'muted'));
    }

    if (check.verifiedCallerId === false) {
        tone = 'bg-amber-50 ring-1 ring-amber-200';
        parts.push(statusLine('Not a Verified Caller ID — on a trial account calls to it will fail.', 'warn'));
    }

    numberStatus.className = `mt-3 space-y-1 rounded-lg p-3 text-sm ${tone}`;
    numberStatus.innerHTML = parts.join('');
    numberStatus.hidden = false;
}

async function checkNumber() {
    const phoneNumber = phoneInput.value.trim();

    readiness = null;
    numberStatus.hidden = true;

    if (!E164.test(phoneNumber)) {
        return;
    }

    try {
        const response = await fetch('/api/agents/number-check', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ phone_number: phoneNumber }),
        });

        if (!response.ok) {
            return;
        }

        readiness = await response.json();
        renderReadiness(readiness);
    } catch {
        // A failed check is not a reason to block the form; the agent can still be created.
    }
}

function buildRow(agent, verified) {
    const article = document.createElement('article');
    article.dataset.agent = agent.id;
    article.className = 'flex items-center gap-4 border-b border-slate-100 p-4 last:border-b-0';

    const provisioned = Boolean(agent.twilio_worker_sid);

    article.innerHTML = `
        <div class="min-w-0 flex-1">
            <p class="truncate font-medium"></p>
            <p class="truncate font-mono text-xs text-slate-500"></p>
            ${provisioned ? '' : '<p class="mt-1 text-xs text-amber-700">No Twilio Worker — run <code class="rounded bg-amber-50 px-1">php artisan taskrouter:provision</code></p>'}
            ${verified === false ? '<p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-amber-700"><span>Number not verified — calls will fail with 21219 on a trial account.</span><button type="button" data-verify class="rounded bg-amber-100 px-2 py-0.5 font-medium text-amber-900 transition hover:bg-amber-200">Verify</button></p>' : ''}
            <p data-verify-code hidden class="mt-1 text-xs text-slate-700"></p>
        </div>
        <span data-status class="rounded-full px-2.5 py-1 text-xs font-medium bg-slate-100 text-slate-600">Unavailable</span>
        <button type="button" data-toggle ${provisioned ? '' : 'disabled'} aria-pressed="false"
                class="relative h-6 w-11 shrink-0 rounded-full transition disabled:cursor-not-allowed disabled:opacity-40 bg-slate-300">
            <span data-knob class="absolute top-0.5 size-5 rounded-full bg-white shadow transition-all left-0.5"></span>
        </button>
    `;

    // textContent, not innerHTML: the name is user input.
    const [nameEl, phoneEl] = article.querySelectorAll('div > p:nth-child(-n+2)');
    nameEl.textContent = agent.name;
    phoneEl.textContent = agent.phone_number;

    return article;
}

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearError();

    const phoneNumber = phoneInput.value.trim();

    if (!E164.test(phoneNumber)) {
        showError('Enter the number in E.164 format, e.g. +15551234567.');
        return;
    }

    submitButton.disabled = true;

    try {
        const response = await fetch('/api/agents', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name: nameInput.value.trim(),
                phone_number: phoneNumber,
                enable_country: document.getElementById('enable-country')?.checked ?? false,
            }),
        });

        const body = await response.json().catch(() => ({}));

        const verified = readiness?.verifiedCallerId ?? null;

        if (!response.ok) {
            // A 502 still carries the agent: the row exists, only its Worker is missing.
            if (body.agent) {
                document.getElementById('agents').append(buildRow(body.agent, verified));
                document.getElementById('agents-empty')?.remove();
            }

            throw new Error(body.error ?? Object.values(body.errors ?? {}).flat().join(' ') ?? `Failed (${response.status})`);
        }

        document.getElementById('agents').append(buildRow(body, verified));
        document.getElementById('agents-empty')?.remove();

        form.reset();
        numberStatus.hidden = true;
        readiness = null;
    } catch (error) {
        showError(error.message ?? String(error));
    } finally {
        submitButton.disabled = false;
    }
});

phoneInput.addEventListener('blur', checkNumber);
