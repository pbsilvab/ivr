<?php

use App\Http\Middleware\ValidateTwilioSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ngrok (and any TLS-terminating proxy in front of the app) forwards over plain HTTP.
        // Without trusting it, url() builds http:// links on an https:// page — mixed content in
        // the dialer — and, worse, ValidateTwilioSignature compares against the http:// URL while
        // Twilio signed the https:// one it actually requested, so every webhook 403s.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'twilio.signature' => ValidateTwilioSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
