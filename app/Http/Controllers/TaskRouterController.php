<?php

namespace App\Http\Controllers;

use App\Services\TaskAssignmentHandler;
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
}

