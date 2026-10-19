<?php declare(strict_types=1);

namespace Ages\BankGateway\Auth;

use Ages\BankGateway\Exception\InvalidBankResponseException;

/**
 * Krátkodobý OAuth access token včetně expirace.
 */
readonly class AccessToken
{

    public function __construct(
        public string $token,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * @param array<mixed> $data Dekódovaná odpověď tokenového endpointu.
     */
    public static function fromArray(array $data, \DateTimeImmutable|null $now = null): self
    {
        $token = $data['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new InvalidBankResponseException('Access token missing in token response.');
        }

        $expiresIn = is_numeric($data['expires_in'] ?? null) ? (int) $data['expires_in'] : 600;
        $now ??= new \DateTimeImmutable();

        return new self($token, $now->modify(sprintf('+%d seconds', $expiresIn)));
    }

    /**
     * Token považujeme za prošlý o chvilku dřív, ať ho nepošleme těsně před vypršením.
     */
    public function isExpired(int $leewaySeconds = 60, \DateTimeImmutable|null $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return $this->expiresAt->getTimestamp() - $leewaySeconds <= $now->getTimestamp();
    }

}
