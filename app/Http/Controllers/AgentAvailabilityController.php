<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\AgentAvailabilityHandler;
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
    public function index(AgentAvailabilityHandler $handler): View
    {
        try {
            $handler->syncStatuses();
        } catch (\Throwable $e) {
            // Twilio unreachable: show the last known state rather than an error page.
            report($e);
        }

        return view('agents', [
            'agents' => Agent::query()->orderBy('name')->get(),
        ]);
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
