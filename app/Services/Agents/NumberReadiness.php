<?php

namespace App\Services\Agents;

use Throwable;
use Twilio\Rest\Client;

/**
 * Everything Twilio knows about a phone number before it is any use as an agent.
 *
 * Two account-level settings decide whether a call to a number can even be placed, and neither is
 * visible from the app: the country's Voice Geographic Permissions, and — on trial accounts —
 * whether the number is a Verified Caller ID. When either is missing the call fails with 21215
 * and the caller silently falls through to voicemail, so it is worth surfacing up front.
 */
class NumberReadiness
{
    public function __construct(private readonly Client $client) {}

    /**
     * @return array{
     *     valid: bool,
     *     countryCode: string|null,
     *     countryName: string|null,
     *     callingEnabled: bool|null,
     *     verifiedCallerId: bool|null,
     * }
     */
    public function inspect(string $phoneNumber): array
    {
        $result = [
            'valid' => false,
            'countryCode' => null,
            'countryName' => null,
            'callingEnabled' => null,
            'verifiedCallerId' => null,
        ];

        // Each lookup is optional: a failure here should degrade the warning, never block the
        // agent from being created. Nulls read as "could not determine".
        try {
            $lookup = $this->client->lookups->v2->phoneNumbers($phoneNumber)->fetch();

            $result['valid'] = (bool) $lookup->valid;
            $result['countryCode'] = $lookup->countryCode;
        } catch (Throwable) {
            return $result;
        }

        if (! $result['valid'] || $result['countryCode'] === null) {
            return $result;
        }

        try {
            $countries = $this->client->voice->v1->dialingPermissions->countries
                ->read(['isoCode' => $result['countryCode']], 1);

            if ($countries !== []) {
                $result['countryName'] = $countries[0]->name;
                $result['callingEnabled'] = (bool) $countries[0]->lowRiskNumbersEnabled;
            }
        } catch (Throwable) {
            // leave callingEnabled null
        }

        try {
            $result['verifiedCallerId'] = $this->client->outgoingCallerIds
                ->read(['phoneNumber' => $phoneNumber], 1) !== [];
        } catch (Throwable) {
            // leave verifiedCallerId null
        }

        return $result;
    }

    /**
     * The E.164 numbers currently on the account's Verified Caller ID list.
     *
     * One call for the whole console, rather than one `inspect()` per agent row.
     *
     * @return list<string>
     */
    public function verifiedNumbers(): array
    {
        try {
            return array_map(
                fn ($callerId): string => $callerId->phoneNumber,
                $this->client->outgoingCallerIds->read(),
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Ask Twilio to verify a number as a Caller ID.
     *
     * This places a real call to the number right away: Twilio reads out nothing, it waits for
     * the person answering to key in the returned code. Nothing is verified until they do, so the
     * code has to be shown to whoever triggered this.
     *
     * @return array{code: string, callSid: string|null}
     */
    public function requestVerification(string $phoneNumber, string $friendlyName): array
    {
        $request = $this->client->validationRequests->create($phoneNumber, [
            'friendlyName' => $friendlyName,
        ]);

        return [
            'code' => $request->validationCode,
            'callSid' => $request->callSid,
        ];
    }

    /**
     * Authorise outbound calls to a country.
     *
     * Only low-risk numbers: the high-risk special and toll-fraud buckets are the expensive
     * prefixes, and turning them on is never something an agent form should do on your behalf.
     */
    public function enableCountry(string $isoCode): void
    {
        $this->client->voice->v1->dialingPermissions->bulkCountryUpdates->create(
            json_encode([[
                'iso_code' => $isoCode,
                'low_risk_numbers_enabled' => true,
            ]])
        );
    }
}
