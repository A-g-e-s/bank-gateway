<?php declare(strict_types=1);

namespace Ages\BankGateway\Entity;

use Ages\BankGateway\Entity\Enum\TransactionDirection;
use Ages\BankGateway\Entity\Enum\TransactionStatus;
use Brick\Money\Money;

/**
 * Pohyb na účtu – normalizovaný napříč bankami.
 *
 * Částka `amount` je vždy kladná, směr určuje `direction`.
 */
readonly class Transaction
{

    /**
     * @param array<string, mixed> $raw Původní data z API banky.
     */
    public function __construct(
        /** Identifikátor pohybu přidělený bankou – vhodný jako unikátní klíč pro idempotentní párování. */
        public string|null $id,
        public Money $amount,
        public TransactionDirection $direction,
        public TransactionStatus $status,
        /** Datum zaúčtování. */
        public \DateTimeImmutable|null $bookingDate,
        /** Datum valuty (podle něj filtruje API). */
        public \DateTimeImmutable|null $valueDate,
        /** Variabilní symbol bez úvodních nul. */
        public string|null $variableSymbol,
        public string|null $constantSymbol,
        public string|null $specificSymbol,
        /** Zpráva pro příjemce. */
        public string|null $message,
        /** Jméno protistrany (plátce u příchozí, příjemce u odchozí platby). */
        public string|null $counterpartyName,
        /** Číslo účtu protistrany v českém formátu, pokud ho banka poskytne. */
        public string|null $counterpartyAccount,
        public string|null $counterpartyIban,
        public string|null $endToEndId,
        /** Popis pohybu dle banky. */
        public string|null $description,
        /** Typ platby dle banky (MONETA `clearingSystemReference`, např. PAYMENT_ORDER_DOMESTIC). */
        public string|null $type,
        /** true = storno pohybu. */
        public bool $reversal = false,
        public array $raw = [],
    ) {
    }

    public function isIncoming(): bool
    {
        return $this->direction === TransactionDirection::Incoming;
    }

    public function isBooked(): bool
    {
        return $this->status === TransactionStatus::Booked;
    }

    /**
     * Zaúčtovaná příchozí platba, která není stornem – kandidát pro párování s objednávkou.
     */
    public function isPayableIncoming(): bool
    {
        return $this->isIncoming() && $this->isBooked() && !$this->reversal;
    }

    /**
     * Porovná variabilní symbol (ignoruje úvodní nuly a mezery).
     */
    public function hasVariableSymbol(string|int $variableSymbol): bool
    {
        $vs = ltrim(trim((string) $variableSymbol), '0');

        return $vs !== '' && $this->variableSymbol === $vs;
    }

}
