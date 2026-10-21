<?php declare(strict_types=1);

namespace Ages\BankGateway\Config;

use Ages\BankGateway\Entity\Enum\Bank;

/**
 * Konfigurace MONETA API (služba VipAISPPayments_v4 – čtení účtů a transakcí).
 *
 * Produkce: API klíč vygenerovaný klientem v internetovém bankovnictví MONETA
 * (Nastavení › Správa API služby) se posílá přímo jako Bearer token → stačí `apiKey`.
 *
 * Sandbox: access token se získává přes OAuth2 client_credentials se scope `vip_sandbox`
 * → `sandbox: true` + `clientId` / `clientSecret` aplikace z API portálu.
 *
 * API klíč z IB má omezenou platnost a mění se → v NEONu stačí společné nastavení
 * (applicationName, timeout…) a klíč se doplní za běhu přes withApiKey().
 */
readonly class MonetaConfig implements BankConfig
{

    public const string API_URL = 'https://api.moneta.cz';
    public const string SANDBOX_SCOPE = 'vip_sandbox';

    public function __construct(
        /** API klíč z internetového bankovnictví (produkce). */
        public string|null $apiKey = null,
        /** Client ID aplikace z API portálu (sandbox / client credentials). */
        public string|null $clientId = null,
        /** Client secret aplikace z API portálu (sandbox / client credentials). */
        public string|null $clientSecret = null,
        /** true = sandbox (předdefinovaná testovací data), token přes client_credentials. */
        public bool $sandbox = false,
        /** Název aplikace posílaný v hlavičce `application_name` (volitelné). */
        public string|null $applicationName = null,
        /** IČO posílané v hlavičce `identification_number` (volitelné). */
        public string|null $identificationNumber = null,
        /** Obchodní název posílaný v hlavičce `business_name` (volitelné). */
        public string|null $businessName = null,
        public string $apiUrl = self::API_URL,
        /** Timeout requestu v sekundách. */
        public int $timeout = 30,
        /** Kolikrát zopakovat request po HTTP 429 (Too Many Requests). */
        public int $rateLimitRetries = 3,
    ) {
    }

    public function bank(): Bank
    {
        return Bank::Moneta;
    }

    public function hasCredentials(): bool
    {
        return !$this->usesClientCredentials()
            || ($this->clientId !== null && $this->clientId !== '' && $this->clientSecret !== null && $this->clientSecret !== '');
    }

    public function usesClientCredentials(): bool
    {
        return $this->apiKey === null || $this->apiKey === '';
    }

    /** Kopie konfigurace s jiným API klíčem (např. načteným z databáze). */
    public function withApiKey(string $apiKey): self
    {
        return new self(
            apiKey: $apiKey,
            clientId: $this->clientId,
            clientSecret: $this->clientSecret,
            sandbox: $this->sandbox,
            applicationName: $this->applicationName,
            identificationNumber: $this->identificationNumber,
            businessName: $this->businessName,
            apiUrl: $this->apiUrl,
            timeout: $this->timeout,
            rateLimitRetries: $this->rateLimitRetries,
        );
    }

}
