<?php

use App\Http\Controllers\TaskRouterController;
use App\Http\Controllers\VoiceController;
use Illuminate\Support\Facades\Route;

// Rutas de webhooks de Twilio (voice, TaskRouter) y toggle de disponibilidad del agente.
// Se agregan en las siguientes fases de implementación (ver docs/LaravelImplementation.md §6).

Route::middleware(['api', 'twilio.signature'])->prefix('voice')->group(function () {
    Route::post('incoming', [VoiceController::class, 'incoming']);
    Route::post('gather-digits', [VoiceController::class, 'gatherDigits']);
    Route::post('voicemail-record', [VoiceController::class, 'voicemailRecord']);
    Route::post('no-agent-available', [VoiceController::class, 'noAgentAvailable']);
});

Route::middleware(['api', 'twilio.signature'])->prefix('taskrouter')->group(function () {
    Route::post('assignment', [TaskRouterController::class, 'assignment']);
    Route::post('events', [TaskRouterController::class, 'events']);
});
