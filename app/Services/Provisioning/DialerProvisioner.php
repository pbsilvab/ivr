<?php

namespace App\Services\Provisioning;

use Twilio\Rest\Client;

/**
 * Creates (or reuses) the TwiML Application the browser dialer places calls through, plus the
 * API Key pair an Access Token has to be signed with. Same idempotent shape as
 * {@see TaskRouterProvisioner}, so it is safe to re-run whenever the public URL changes.
 */
class DialerProvisioner
{
    private const APPLICATION_NAME = 'Browser Dialer';

    public function __construct(private readonly Client $client) {}

    /**
     * @return array{twimlAppSid: string, created: bool}
     */
    public function provision(string $appUrl): array
    {
        $voiceUrl = "{$appUrl}/api/dialer/outbound";

        $existing = $this->client->applications
            ->read(['friendlyName' => self::APPLICATION_NAME], 1);

        if ($existing !== []) {
            $sid = $existing[0]->sid;

            $this->client->applications($sid)->update([
                'voiceUrl' => $voiceUrl,
                'voiceMethod' => 'POST',
            ]);

            $created = false;
        } else {
            $sid = $this->client->applications->create([
                'friendlyName' => self::APPLICATION_NAME,
                'voiceUrl' => $voiceUrl,
                'voiceMethod' => 'POST',
            ])->sid;

            $created = true;
        }

        return ['twimlAppSid' => $sid, 'created' => $created];
    }

    /**
     * Access Tokens are signed with an API Key, not the account auth token. The secret is only
     * ever returned here, so the caller has to show it to the user right away.
     *
     * @return array{sid: string, secret: string}
     */
    public function createApiKey(): array
    {
        $key = $this->client->newKeys->create(['friendlyName' => self::APPLICATION_NAME]);

        return ['sid' => $key->sid, 'secret' => $key->secret];
    }
}
