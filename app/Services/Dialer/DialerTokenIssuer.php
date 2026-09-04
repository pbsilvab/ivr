<?php

namespace App\Services\Dialer;

use Illuminate\Support\Str;
use RuntimeException;
use Twilio\Jwt\AccessToken;
use Twilio\Jwt\Grants\VoiceGrant;

/**
 * Mints the short-lived Access Token the browser softphone needs to register with Twilio.
 *
 * The grant is outgoing-only and pinned to the dialer's TwiML Application, so a leaked token
 * can do nothing except hit /api/dialer/outbound, which enforces its own destination allowlist.
 */
class DialerTokenIssuer
{
    /**
     * @return array{token: string, identity: string, ttl: int}
     */
    public function issue(?string $identity = null): array
    {
        $accountSid = $this->requireConfig('services.twilio.sid', 'TWILIO_ACCOUNT_SID');
        $apiKeySid = $this->requireConfig('services.twilio.api_key_sid', 'TWILIO_API_KEY_SID');
        $apiKeySecret = $this->requireConfig('services.twilio.api_key_secret', 'TWILIO_API_KEY_SECRET');
        $twimlAppSid = $this->requireConfig('services.twilio.twiml_app_sid', 'TWILIO_TWIML_APP_SID');

        $identity = $this->normalizeIdentity($identity);
        $ttl = max(60, (int) config('services.twilio.dialer.token_ttl', 3600));

        $token = new AccessToken($accountSid, $apiKeySid, $apiKeySecret, $ttl, $identity);

        $token->addGrant(
            (new VoiceGrant)
                ->setOutgoingApplicationSid($twimlAppSid)
                ->setIncomingAllow(false)
        );

        return [
            'token' => $token->toJWT(),
            'identity' => $identity,
            'ttl' => $ttl,
        ];
    }

    /**
     * The identity ends up in the `client:<identity>` caller address, so keep it to characters
     * Twilio accepts there and cap the length.
     */
    private function normalizeIdentity(?string $identity): string
    {
        $identity = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $identity) ?? '';

        return $identity === ''
            ? 'dialer-'.Str::lower(Str::random(8))
            : Str::substr($identity, 0, 40);
    }

    private function requireConfig(string $key, string $envName): string
    {
        $value = (string) config($key);

        if ($value === '') {
            throw new RuntimeException("Missing {$envName}. Run `php artisan dialer:provision` and copy the values into .env.");
        }

        return $value;
    }
}
