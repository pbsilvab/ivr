<?php

namespace App\Console\Commands;

use App\Services\Provisioning\DialerProvisioner;
use Illuminate\Console\Command;

class ProvisionDialerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dialer:provision';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or reuse the TwiML Application (and API Key) the browser dialer needs';

    /**
     * Execute the console command.
     */
    public function handle(DialerProvisioner $provisioner): int
    {
        $appUrl = rtrim((string) config('app.url'), '/');

        $result = $provisioner->provision($appUrl);

        $this->table(['Resource', 'Value'], [
            ['TwiML Application', $result['twimlAppSid']],
            ['Voice URL', "{$appUrl}/api/dialer/outbound"],
            ['Status', $result['created'] ? 'created' : 'reused (voice URL updated)'],
        ]);

        $this->line('');
        $this->line('Add to .env:');
        $this->line("TWILIO_TWIML_APP_SID={$result['twimlAppSid']}");

        $this->ensureApiKey($provisioner);

        return self::SUCCESS;
    }

    /**
     * An Access Token has to be signed with an API Key pair. Creating one is a real credential
     * on the user's account, so only do it when they say so — and print the secret immediately,
     * because Twilio never returns it again.
     */
    private function ensureApiKey(DialerProvisioner $provisioner): void
    {
        if ((string) config('services.twilio.api_key_sid') !== '' && (string) config('services.twilio.api_key_secret') !== '') {
            $this->line('');
            $this->info('TWILIO_API_KEY_SID / TWILIO_API_KEY_SECRET are already set — leaving them alone.');

            return;
        }

        $this->line('');
        $this->warn('No API Key configured yet. The dialer cannot mint Access Tokens without one.');

        if (! $this->confirm('Create a new Twilio API Key now?', false)) {
            $this->line('Skipped. Create one at Console → Account → API keys & tokens, then set TWILIO_API_KEY_SID and TWILIO_API_KEY_SECRET.');

            return;
        }

        $key = $provisioner->createApiKey();

        $this->line("TWILIO_API_KEY_SID={$key['sid']}");
        $this->line("TWILIO_API_KEY_SECRET={$key['secret']}");
        $this->line('');
        $this->warn('Copy the secret now — Twilio will not show it again.');
    }
}
