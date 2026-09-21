<?php

namespace App\Services\Registry;

use App\Rules\SwissUid;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Looks organisations up in the public Swiss UID register (UID-WSE, SOAP 1.1)
 * with plain HTTP, so ext-soap is not required.
 */
class UidRegister
{
    private const string ENDPOINT = 'https://www.uid-wse.admin.ch/V5.0/PublicServices.svc';

    private const string SOAP_ACTION = '"http://www.uid.admin.ch/xmlns/uid-wse/IPublicServices/GetByUID"';

    private const int CACHE_SECONDS = 86400;

    /** Not-found answers are cached briefly so a new registration shows up soon. */
    private const int NOT_FOUND_CACHE_SECONDS = 600;

    private const int LOOKUPS_PER_MINUTE = 5;

    /**
     * The register entry of the UID, or null when the UID is invalid or unknown.
     *
     * @param  int|string|null  $userId  Limits uncached lookups per user when given.
     *
     * @throws UidRegisterUnavailable
     */
    public function lookup(string $uid, int|string|null $userId = null): ?UidRecord
    {
        $normalized = SwissUid::normalize($uid);

        if ($normalized === null) {
            return null;
        }

        $cacheKey = 'uid-register:'.$normalized;
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached === [] ? null : UidRecord::fromArray($cached);
        }

        $record = null;

        if ($userId === null) {
            $record = $this->fetch($normalized);
        } else {
            $limiterKey = 'uid-lookup:'.$userId;
            $allowed = RateLimiter::attempt($limiterKey, self::LOOKUPS_PER_MINUTE, function () use ($normalized, &$record): void {
                $record = $this->fetch($normalized);
            }, 60);

            if ($allowed === false) {
                throw UidRegisterUnavailable::rateLimited(RateLimiter::availableIn($limiterKey));
            }
        }

        $record === null
            ? Cache::put($cacheKey, [], self::NOT_FOUND_CACHE_SECONDS)
            : Cache::put($cacheKey, $record->toArray(), self::CACHE_SECONDS);

        return $record;
    }

    /**
     * Parse a GetByUID SOAP response. Null when it holds no organisation.
     */
    public function parse(string $xml): ?UidRecord
    {
        $xpath = $this->xpath($xml);

        if ($xpath === null) {
            return null;
        }

        $organisation = $this->first($xpath, '//*[local-name()="organisationType"]/*[local-name()="organisation"]');

        if ($organisation === null) {
            return null;
        }

        $identification = $this->first($xpath, './*[local-name()="organisationIdentification"]', $organisation);
        $uidDigits = $this->text($xpath, './*[local-name()="uid"]/*[local-name()="uidOrganisationId"]', $identification);
        $name = $this->text($xpath, './*[local-name()="organisationName"]', $identification);

        if ($uidDigits === null || $name === null) {
            return null;
        }

        $address = $this->first($xpath, './*[local-name()="address"][*[local-name()="addressCategory"]="LEGAL"]', $organisation)
            ?? $this->first($xpath, './*[local-name()="address"]', $organisation);
        $vat = $this->first($xpath, '//*[local-name()="organisationType"]/*[local-name()="vatRegisterInformation"]');
        $vatDigits = $this->text($xpath, './*[local-name()="uidVat"]/*[local-name()="uidOrganisationId"]', $vat);

        return new UidRecord(
            uid: SwissUid::normalize('CHE'.$uidDigits) ?? 'CHE'.$uidDigits,
            name: $name,
            legalName: $this->text($xpath, './*[local-name()="organisationLegalName"]', $identification),
            legalForm: $this->text($xpath, './*[local-name()="legalForm"]', $identification),
            street: $this->text($xpath, './*[local-name()="street"]', $address),
            houseNumber: $this->text($xpath, './*[local-name()="houseNumber"]', $address),
            postalCode: $this->text($xpath, './*[local-name()="swissZipCode"]', $address),
            town: $this->text($xpath, './*[local-name()="town"]', $address),
            bfsNumber: $this->text($xpath, './*[local-name()="municipalityId"]', $address),
            cantonCode: $this->text($xpath, './*[local-name()="cantonAbbreviation"]', $address),
            vatStatus: $this->text($xpath, './*[local-name()="vatStatus"]', $vat),
            vatUid: $vatDigits === null ? null : SwissUid::normalize('CHE'.$vatDigits),
            vatLiquidated: $this->text($xpath, './*[local-name()="vatLiquidationDate"]', $vat) !== null,
            vatEntryStatus: $this->text($xpath, './*[local-name()="vatEntryStatus"]', $vat),
        );
    }

    /**
     * @throws UidRegisterUnavailable
     */
    private function fetch(string $normalizedUid): ?UidRecord
    {
        try {
            $response = Http::withHeaders(['SOAPAction' => self::SOAP_ACTION])
                ->withBody($this->requestBody((string) SwissUid::digits($normalizedUid)), 'text/xml; charset=utf-8')
                ->timeout(8)
                ->post(self::ENDPOINT);
        } catch (ConnectionException $exception) {
            throw new UidRegisterUnavailable(previous: $exception);
        }

        if ($response->successful()) {
            return $this->parse($response->body());
        }

        if ($this->isClientFault($response)) {
            return null;
        }

        throw new UidRegisterUnavailable("The UID register answered with HTTP {$response->status()}.");
    }

    /**
     * A SOAP client fault (e.g. "Data_validation_failed") means the UID was
     * rejected, not that the service is down. Request-limit faults count as
     * unavailable.
     */
    private function isClientFault(Response $response): bool
    {
        $xpath = $this->xpath($response->body());
        $faultCode = $xpath === null ? null : $this->text($xpath, '//*[local-name()="Fault"]/*[local-name()="faultcode"]');

        if ($faultCode === null || ! str_ends_with($faultCode, 'Client')) {
            return false;
        }

        $faultString = (string) $this->text($xpath, '//*[local-name()="Fault"]/*[local-name()="faultstring"]');

        return ! str_contains(strtolower($faultString), 'limit');
    }

    private function requestBody(string $digits): string
    {
        return <<<XML
            <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:uid="http://www.uid.admin.ch/xmlns/uid-wse" xmlns:ns="http://www.ech.ch/xmlns/eCH-0097/5">
              <soapenv:Header/>
              <soapenv:Body>
                <uid:GetByUID>
                  <uid:uid>
                    <ns:uidOrganisationIdCategorie>CHE</ns:uidOrganisationIdCategorie>
                    <ns:uidOrganisationId>{$digits}</ns:uidOrganisationId>
                  </uid:uid>
                </uid:GetByUID>
              </soapenv:Body>
            </soapenv:Envelope>
            XML;
    }

    private function xpath(string $xml): ?DOMXPath
    {
        if (trim($xml) === '') {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded ? new DOMXPath($document) : null;
    }

    private function first(DOMXPath $xpath, string $expression, ?DOMElement $context = null): ?DOMElement
    {
        if ($context === null && ! str_starts_with($expression, '/')) {
            return null;
        }

        $node = $xpath->query($expression, $context)->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function text(DOMXPath $xpath, string $expression, ?DOMElement $context = null): ?string
    {
        $node = $this->first($xpath, $expression, $context);
        $value = $node === null ? '' : trim($node->textContent);

        return $value === '' ? null : $value;
    }
}
