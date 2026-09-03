<?php

use App\Http\Controllers\VoiceController;
use Illuminate\Support\Facades\Route;

// Rutas de webhooks de Twilio (voice, TaskRouter) y toggle de disponibilidad del agente.
// Se agregan en las siguientes fases de implementación (ver docs/LaravelImplementation.md §6).

Route::middleware(['api', 'twilio.signature'])->prefix('voice')->group(function () {
    Route::post('incoming', [VoiceController::class, 'incoming']);
});
