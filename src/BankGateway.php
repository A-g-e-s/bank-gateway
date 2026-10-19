<?php declare(strict_types=1);

namespace Ages\BankGateway;

use Ages\BankGateway\Config\MonetaConfig;
use Ages\BankGateway\Entity\Enum\Bank;
use Ages\BankGateway\Exception\GatewayInitializationException;
use Ages\BankGateway\Gateway\BankInterface;
use Ages\BankGateway\Gateway\Moneta\MonetaBank;
use Ages\BankGateway\Gateway\Moneta\MonetaClient;
use Psr\SimpleCache\CacheInterface;

/**
 * DI vstupní bod – bank(Bank) vrátí BankInterface nakonfigurované banky.
 *
 * Klienti se sestavují líně při prvním použití, takže nevyplněná konfigurace
 * jiné banky nebrání startu aplikace.
 */
class BankGateway
{

    private MonetaBank|null $moneta = null;

    public function __construct(
        private readonly MonetaConfig|null $monetaConfig = null,
        private readonly CacheInterface|null $cache = null,
    ) {
    }

    public function bank(Bank $bank): BankInterface
    {
        return match ($bank) {
            Bank::Moneta => $this->moneta(),
        };
    }

    public function isConfigured(Bank $bank): bool
    {
        return match ($bank) {
            Bank::Moneta => $this->monetaConfig !== null,
        };
    }

    public function moneta(): MonetaBank
    {
        if ($this->monetaConfig === null) {
            throw new GatewayInitializationException(sprintf('Bank %s is not configured', Bank::Moneta->name));
        }

        return $this->moneta ??= new MonetaBank(new MonetaClient($this->monetaConfig, $this->cache));
    }

}
