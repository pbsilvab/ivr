<?php

namespace App\Http\Controllers;

use App\Services\TaskAssignmentHandler;
use App\Services\TaskTimeoutHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TaskRouterController extends Controller
{
    public function assignment(Request $request, TaskAssignmentHandler $handler): Response|JsonResponse
    {
        // TaskRouter reads assignment instructions as JSON here, never TwiML.
        $instruction = $handler->handleAssignmentCallback($request->all());

        if ($instruction === []) {
            // "No instruction" has to be an empty body: an empty PHP array encodes to `[]`, and
            // TaskRouter rejects that with "Could not parse Assignment Instruction response".
            return response('', 200);
        }

        return response()->json($instruction, 200);
    }

    public function events(Request $request, TaskTimeoutHandler $handler): JsonResponse
    {
        $handler->handleTaskEvent($request->all());

        // TaskRouter ignores the body of an event callback; the 200 is the whole contract.
        return response()->json(['status' => 'processed'], 200);
    }
}
