<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agents — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/agents.js'])
</head>
<body class="h-full bg-slate-100 text-slate-900">

<main class="mx-auto max-w-3xl p-6">

    <header class="mb-6 flex items-baseline justify-between">
        <div>
            <h1 class="text-lg font-semibold">Agents</h1>
            <p class="text-sm text-slate-500">Only <strong>Available</strong> agents can have a Task reserved for them.</p>
        </div>
        <a href="{{ route('dialer') }}" class="text-sm font-medium text-slate-600 underline hover:text-slate-900">
            Open dialer →
        </a>
    </header>

    <form id="new-agent" class="mb-6 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <h2 class="mb-3 text-sm font-semibold">Add an agent</h2>

        <div class="flex flex-col gap-3 sm:flex-row">
            <input id="agent-name" name="name" type="text" placeholder="Name" autocomplete="off" required
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
            <input id="agent-phone" name="phone_number" type="tel" placeholder="+15551234567" autocomplete="off" required
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm focus:border-slate-900 focus:outline-none sm:max-w-56">
            <button id="agent-submit" type="submit"
                    class="shrink-0 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:bg-slate-300">
                Add
            </button>
        </div>

        <div id="number-status" hidden class="mt-3 rounded-lg p-3 text-sm"></div>

        <p class="mt-3 text-xs text-slate-500">
            The agent's number is what TaskRouter dials when a call is assigned, so it has to be
            reachable from your Twilio account.
        </p>
    </form>

    <div id="agents" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
        @forelse ($agents as $agent)
            <article data-agent="{{ $agent->id }}"
                     class="flex items-center gap-4 border-b border-slate-100 p-4 last:border-b-0">

                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium">{{ $agent->name }}</p>
                    <p class="truncate font-mono text-xs text-slate-500">{{ $agent->phone_number }}</p>
                    @unless ($agent->twilio_worker_sid)
                        <p class="mt-1 text-xs text-amber-700">
                            No Twilio Worker — run <code class="rounded bg-amber-50 px-1">php artisan taskrouter:provision</code>
                        </p>
                    @endunless

                    @unless (in_array($agent->phone_number, $verifiedNumbers, true))
                        <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-amber-700">
                            <span>Number not verified — calls will fail with 21219 on a trial account.</span>
                            <button type="button" data-verify
                                    class="rounded bg-amber-100 px-2 py-0.5 font-medium text-amber-900 transition hover:bg-amber-200">
                                Verify
                            </button>
                        </p>
                    @endunless

                    <p data-verify-code hidden class="mt-1 text-xs text-slate-700"></p>
                </div>

                <span data-status
                      class="rounded-full px-2.5 py-1 text-xs font-medium
                             {{ $agent->status === 'available'
                                 ? 'bg-emerald-100 text-emerald-800'
                                 : 'bg-slate-100 text-slate-600' }}">
                    {{ $agent->status === 'available' ? 'Available' : 'Unavailable' }}
                </span>

                <button type="button" data-toggle
                        @disabled(! $agent->twilio_worker_sid)
                        aria-pressed="{{ $agent->status === 'available' ? 'true' : 'false' }}"
                        class="relative h-6 w-11 shrink-0 rounded-full transition disabled:cursor-not-allowed disabled:opacity-40
                               {{ $agent->status === 'available' ? 'bg-emerald-600' : 'bg-slate-300' }}">
                    <span data-knob
                          class="absolute top-0.5 size-5 rounded-full bg-white shadow transition-all
                                 {{ $agent->status === 'available' ? 'left-[1.375rem]' : 'left-0.5' }}"></span>
                </button>
            </article>
        @empty
            <p id="agents-empty" class="p-6 text-sm text-slate-500">
                No agents yet — add one above.
            </p>
        @endforelse
    </div>

    <p id="error-message" hidden class="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-rose-200"></p>

</main>
</body>
</html>
