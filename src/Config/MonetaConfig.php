<?php declare(strict_types=1);

namespace Ages\BankGateway\Config;

/**
 * Konfigurace MONETA API (služba VipAISPPayments_v4 – čtení účtů a transakcí).
 *
 * Produkce: API klíč vygenerovaný klientem v internetovém bankovnictví MONETA
 * (Nastavení › Správa API služby) se posílá přímo jako Bearer token → stačí `apiKey`.
 *
 * Sandbox: access token se získává přes OAuth2 client_credentials se scope `vip_sandbox`
 * → `sandbox: true` + `clientId` / `clientSecret` aplikace z API portálu.
 */
readonly class MonetaConfig
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

    public function usesClientCredentials(): bool
    {
        return $this->apiKey === null || $this->apiKey === '';
    }

}
