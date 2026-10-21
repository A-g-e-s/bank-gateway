<?php declare(strict_types=1);

namespace Ages\BankGateway;

use Ages\BankGateway\Config\BankConfig;
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
 * Konfigurace banky se předává buď v DI (NEON) → bank(Bank), nebo za běhu → connect(BankConfig),
 * např. když se API klíč pravidelně obnovuje a je uložený v databázi.
 *
 * Klienti se sestavují líně při prvním použití, takže nevyplněná konfigurace
 * jiné banky nebrání startu aplikace.
 */
class BankGateway
{

    private MonetaBank|null $moneta = null;

    /** @var array<string, BankInterface> klienti sestavení přes connect(), podle konfigurace */
    private array $connected = [];

    public function __construct(
        private readonly MonetaConfig|null $monetaConfig = null,
        private readonly CacheInterface|null $cache = null,
    ) {
    }

    /** Banka s konfigurací z DI. */
    public function bank(Bank $bank): BankInterface
    {
        return match ($bank) {
            Bank::Moneta => $this->moneta(),
        };
    }

    /** Má banka v DI kompletní konfiguraci (včetně přihlašovacích údajů)? */
    public function isConfigured(Bank $bank): bool
    {
        return $this->config($bank)?->hasCredentials() ?? false;
    }

    /** Konfigurace banky z DI (null = nenastavená) – např. jako základ pro MonetaConfig::withApiKey(). */
    public function config(Bank $bank): BankConfig|null
    {
        return match ($bank) {
            Bank::Moneta => $this->monetaConfig,
        };
    }

    /**
     * Banka s konfigurací předanou za běhu (např. API klíč z databáze).
     * Pro stejnou konfiguraci vrací stejného klienta.
     */
    public function connect(BankConfig $config): BankInterface
    {
        if (!$config->hasCredentials()) {
            throw new GatewayInitializationException(sprintf('Bank %s has no credentials', $config->bank()->name));
        }

        return $this->connected[hash('sha256', serialize($config))] ??= $this->create($config);
    }

    public function moneta(): MonetaBank
    {
        if ($this->monetaConfig === null) {
            throw new GatewayInitializationException(sprintf('Bank %s is not configured', Bank::Moneta->name));
        }

        return $this->moneta ??= new MonetaBank(new MonetaClient($this->monetaConfig, $this->cache));
    }

    private function create(BankConfig $config): BankInterface
    {
        return match (true) {
            $config instanceof MonetaConfig => new MonetaBank(new MonetaClient($config, $this->cache)),
            default => throw new GatewayInitializationException(sprintf('Unsupported config %s', $config::class)),
        };
    }

}
