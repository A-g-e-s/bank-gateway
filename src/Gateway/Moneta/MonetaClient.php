<?php declare(strict_types=1);

namespace Ages\BankGateway\Gateway\Moneta;

use Ages\BankGateway\Auth\AccessToken;
use Ages\BankGateway\Config\MonetaConfig;
use Ages\BankGateway\Exception\BankApiException;
use Ages\BankGateway\Exception\BankGatewayException;
use Ages\BankGateway\Exception\GatewayInitializationException;
use Ages\BankGateway\Exception\InvalidBankResponseException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Nízkoúrovňový HTTP klient MONETA API.
 *
 * Řeší autorizaci (API klíč z IB, nebo OAuth client_credentials pro sandbox),
 * cache tokenu, opakování po HTTP 429 a převod chyb na výjimky.
 */
class MonetaClient
{

    private const string TOKEN_PATH = 'auth/oauth/v2/token';

    private Client $httpClient;

    private AccessToken|null $token = null;

    public function __construct(
        private readonly MonetaConfig $config,
        /** Volitelná PSR-16 cache pro sdílení OAuth tokenu mezi requesty (jen client credentials). */
        private readonly CacheInterface|null $cache = null,
        Client|null $httpClient = null,
    ) {
        if ($config->usesClientCredentials() && ($config->clientId === null || $config->clientSecret === null)) {
            throw new GatewayInitializationException(
                'MONETA: set either apiKey (production) or clientId + clientSecret (sandbox).'
            );
        }

        $this->httpClient = $httpClient ?? new Client([
            'base_uri' => rtrim($config->apiUrl, '/') . '/',
            'timeout' => $config->timeout,
            'http_errors' => false,
        ]);
    }

    /**
     * @param array<string, scalar|null> $query
     * @return array<mixed>
     */
    public function getJson(string $path, array $query = []): array
    {
        $response = $this->get($path, $query, 'application/json');
        $body = (string) $response->getBody();

        if ($body === '') {
            return [];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new InvalidBankResponseException(sprintf('MONETA API returned invalid JSON for %s.', $path));
        }

        return $data;
    }

    /**
     * @param array<string, scalar|null> $query
     */
    public function get(string $path, array $query = [], string $accept = '*/*'): ResponseInterface
    {
        $response = $this->send('GET', ltrim($path, '/'), [
            'query' => array_filter($query, static fn($v): bool => $v !== null),
            'headers' => $this->headers($accept),
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw BankApiException::fromResponse('MONETA', $status, (string) $response->getBody());
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $accept): array
    {
        return array_filter([
            'Authorization' => 'Bearer ' . $this->accessToken(),
            'Accept' => $accept,
            'application_name' => $this->config->applicationName,
            'identification_number' => $this->config->identificationNumber,
            'business_name' => $this->config->businessName,
        ], static fn(string|null $v): bool => $v !== null && $v !== '');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $path, array $options): ResponseInterface
    {
        $attempt = 0;

        while (true) {
            try {
                $response = $this->httpClient->request($method, $path, $options);
            } catch (GuzzleException $e) {
                throw new BankGatewayException('MONETA API request failed: ' . $e->getMessage(), 0, $e);
            }

            if ($response->getStatusCode() === 429 && $attempt < $this->config->rateLimitRetries) {
                $attempt++;
                $retryAfter = (int) ($response->getHeaderLine('Retry-After') ?: $attempt);
                sleep(max(1, min($retryAfter, 30)));
                continue;
            }

            return $response;
        }
    }

    private function accessToken(): string
    {
        if (!$this->config->usesClientCredentials()) {
            return (string) $this->config->apiKey;
        }

        if ($this->token !== null && !$this->token->isExpired()) {
            return $this->token->token;
        }

        $cached = $this->cache?->get($this->cacheKey());
        if ($cached instanceof AccessToken && !$cached->isExpired()) {
            return ($this->token = $cached)->token;
        }

        $this->token = $this->fetchToken();

        $ttl = $this->token->expiresAt->getTimestamp() - time() - 60;
        if ($ttl > 0) {
            $this->cache?->set($this->cacheKey(), $this->token, $ttl);
        }

        return $this->token->token;
    }

    private function fetchToken(): AccessToken
    {
        $form = [
            'client_id' => (string) $this->config->clientId,
            'client_secret' => (string) $this->config->clientSecret,
            'grant_type' => 'client_credentials',
        ];
        if ($this->config->sandbox) {
            $form['scope'] = MonetaConfig::SANDBOX_SCOPE;
        }

        $response = $this->send('POST', self::TOKEN_PATH, ['form_params' => $form]);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw BankApiException::fromResponse('MONETA', $status, $body);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new InvalidBankResponseException('MONETA returned invalid token response.');
        }

        return AccessToken::fromArray($data);
    }

    private function cacheKey(): string
    {
        return 'bank-gateway.moneta.token.' . substr(hash('sha256', (string) $this->config->clientId), 0, 16)
            . ($this->config->sandbox ? '.sandbox' : '');
    }

}
