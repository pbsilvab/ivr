<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\AgentAvailabilityHandler;
use App\Services\Agents\CreateAgentAction;
use App\Services\Agents\NumberReadiness;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AgentAvailabilityController extends Controller
{
    /**
     * Agent console: flip each agent between Available and Unavailable, which is what decides
     * whether a TaskRouter Task finds anyone to reserve.
     */
    public function index(AgentAvailabilityHandler $handler, NumberReadiness $numbers): View
    {
        try {
            $handler->syncStatuses();
        } catch (\Throwable $e) {
            // Twilio unreachable: show the last known state rather than an error page.
            report($e);
        }

        return view('agents', [
            'agents' => Agent::query()->orderBy('name')->get(),
            // On a trial account a call to an unverified number fails with 21219 no matter how
            // healthy the Worker looks, so the console has to show it per row.
            'verifiedNumbers' => $numbers->verifiedNumbers(),
        ]);
    }

    public function store(Request $request, CreateAgentAction $createAgent): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/', 'unique:agents,phone_number'],
            'enable_country' => 'sometimes|boolean',
        ]);

        try {
            $agent = $createAgent->handle(
                $validated['name'],
                $validated['phone_number'],
                (bool) ($validated['enable_country'] ?? false),
            );

            return response()->json($agent, 201);
        } catch (\Exception $e) {
            // The agent row survives a Worker failure on purpose, so hand it back too: the console
            // renders it in its "no Twilio Worker" state rather than losing the entry.
            return response()->json([
                'error' => $e->getMessage(),
                'agent' => Agent::where('phone_number', $validated['phone_number'])->first(),
            ], 502);
        }
    }

    /**
     * Called by the form while a number is being typed, so the country's calling permissions are
     * visible before the agent exists — rather than after a call has already failed with 21215.
     */
    public function checkNumber(Request $request, NumberReadiness $numbers): JsonResponse
    {
        $validated = $request->validate([
            'phone_number' => 'required|string|max:20',
        ]);

        return response()->json($numbers->inspect($validated['phone_number']), 200);
    }

    /**
     * Trigger Twilio's Caller ID verification for an agent's number.
     *
     * Placing the call is the whole point, so the UI has to say so before this is hit. Only
     * numbers already on an agent can be dialled this way, which keeps it from being a way to
     * ring arbitrary numbers.
     */
    public function verifyNumber(int $agentId, NumberReadiness $numbers): JsonResponse
    {
        $agent = Agent::find($agentId);

        if (! $agent) {
            return response()->json(['error' => "Agent {$agentId} not found."], 404);
        }

        try {
            return response()->json($numbers->requestVerification($agent->phone_number, $agent->name), 200);
        } catch (\Exception $e) {
            // Trial accounts cannot place verification calls at all — the one restriction the API
            // route cannot lift is the one trial accounts are subject to. Whatever the reason,
            // the Console path always works, so hand it over instead of a bare API error.
            return response()->json([
                'error' => $e->getMessage(),
                'hint' => 'Add it by hand in Twilio Console → Phone Numbers → Manage → Verified Caller IDs.',
            ], 502);
        }
    }

    public function toggle(Request $request, int $agentId, AgentAvailabilityHandler $handler): JsonResponse
    {
        try {
            $result = $handler->toggleAvailability($agentId);

            return response()->json($result, 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => "Agent {$agentId} not found."], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function set(Request $request, int $agentId, AgentAvailabilityHandler $handler): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:available,unavailable',
        ]);

        try {
            $result = $handler->setAvailability($agentId, $request->input('status'));

            return response()->json($result, 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => "Agent {$agentId} not found."], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}
