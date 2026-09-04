<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dialer — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/dialer.js'])
</head>
<body class="h-full bg-slate-100 text-slate-900">

<script type="application/json" id="dialer-config">@json($dialerConfig)</script>

<main class="mx-auto flex min-h-full max-w-5xl flex-col gap-6 p-6 lg:flex-row lg:items-start">

    <section class="w-full rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 lg:max-w-sm">
        <header class="mb-5">
            <h1 class="text-lg font-semibold">Softphone</h1>
            <p class="text-sm text-slate-500">Places a call as an external customer would.</p>
        </header>

        @unless ($configured)
            <div class="mb-5 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200">
                <p class="font-medium">Not provisioned yet</p>
                <p class="mt-1">Run <code class="rounded bg-amber-100 px-1">php artisan dialer:provision</code> and copy the
                    printed values into <code class="rounded bg-amber-100 px-1">.env</code>.</p>
            </div>
        @endunless

        <div class="mb-4 flex items-center gap-2">
            <span id="status-dot" class="size-2.5 rounded-full bg-slate-300"></span>
            <span id="status-label" class="text-sm font-medium text-slate-600">Idle</span>
            <span id="call-timer" class="ml-auto font-mono text-sm tabular-nums text-slate-400"></span>
        </div>

        <label class="block text-xs font-medium uppercase tracking-wide text-slate-500" for="destination">
            Calling
        </label>
        <input id="destination" type="tel" value="{{ $destination }}" autocomplete="off"
               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-lg tracking-wide
                      focus:border-slate-900 focus:outline-none disabled:bg-slate-50 disabled:text-slate-500">
        <p class="mt-1 text-xs text-slate-500">
            Caller ID: <span class="font-mono">{{ $callerId ?: '—' }}</span>
        </p>

        <div id="keypad" class="mt-5 grid grid-cols-3 gap-2">
            @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'] as $key)
                <button type="button" data-digit="{{ $key }}"
                        class="rounded-lg bg-slate-100 py-3 font-mono text-lg font-medium text-slate-800
                               transition hover:bg-slate-200 active:scale-95">{{ $key }}</button>
            @endforeach
        </div>

        <p id="keypad-hint" class="mt-2 text-center text-xs text-slate-500">Type the number to dial</p>

        <div class="mt-5 grid grid-cols-2 gap-2">
            <button id="call-button" type="button"
                    class="col-span-2 rounded-lg bg-emerald-600 py-3 font-semibold text-white transition
                           hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300">
                Call
            </button>
            <button id="hangup-button" type="button" hidden
                    class="rounded-lg bg-rose-600 py-3 font-semibold text-white transition hover:bg-rose-700">
                Hang up
            </button>
            <button id="mute-button" type="button" hidden
                    class="rounded-lg bg-slate-200 py-3 font-semibold text-slate-800 transition hover:bg-slate-300">
                Mute
            </button>
        </div>

        <p id="error-message" hidden class="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-rose-200"></p>
    </section>

    <section class="w-full flex-1 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
        <header class="mb-4 flex items-baseline justify-between">
            <h2 class="text-lg font-semibold">Call log</h2>
            <span id="call-sid" class="font-mono text-xs text-slate-400"></span>
        </header>

        <div class="mb-4 rounded-lg bg-slate-50 p-4 text-sm text-slate-600 ring-1 ring-slate-200">
            <p class="font-medium text-slate-800">Walking the flow</p>
            <ol class="mt-2 list-decimal space-y-1 pl-4">
                <li>Press <strong>Call</strong> — the browser leg dials the Twilio number, which hits
                    <code>/api/voice/incoming</code>.</li>
                <li>The IVR asks for a digit. Use the keypad: <strong>1</strong> routes through TaskRouter to an
                    agent, <strong>2</strong> goes straight to voicemail.</li>
                <li>With no agent Available, the Task times out and the call falls back to voicemail.</li>
            </ol>
        </div>

        <ul id="log" class="space-y-1 font-mono text-xs text-slate-600"></ul>
    </section>

</main>
</body>
</html>
