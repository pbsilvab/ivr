<?php

namespace App\Http\Controllers;

use App\Services\TaskAssignmentHandler;
use App\Services\TaskTimeoutHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TaskRouterController extends Controller
{
    public function assignment(Request $request, TaskAssignmentHandler $handler): Response
    {
        $payload = $request->all();

        $response = $handler->handleAssignmentCallback($payload);

        return response($response, 200)
            ->header('Content-Type', 'application/xml');
    }

    public function events(Request $request, TaskTimeoutHandler $handler): Response
    {
        $payload = $request->all();
        $handler->handleTaskEvent($payload);

        // TaskRouter events don't require a response, but return 200 OK
        return response(json_encode(['status' => 'processed']), 200)
            ->header('Content-Type', 'application/json');
    }
}

