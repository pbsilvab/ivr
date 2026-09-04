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

document.getElementById('agents').addEventListener('click', (event) => {
    const button = event.target.closest('[data-toggle]');

    if (button && !button.disabled) {
        toggle(button.closest('[data-agent]'));
    }
});
