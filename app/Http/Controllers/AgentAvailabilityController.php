<?php

namespace App\Http\Controllers;

use App\Services\AgentAvailabilityHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentAvailabilityController extends Controller
{
    public function toggle(Request $request, int $agentId, AgentAvailabilityHandler $handler): JsonResponse
    {
        try {
            $result = $handler->toggleAvailability($agentId);

            return response()->json($result, 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
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
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => "Agent {$agentId} not found."], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}

