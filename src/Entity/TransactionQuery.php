<?php declare(strict_types=1);

namespace Ages\BankGateway\Entity;

use Ages\BankGateway\Entity\Enum\TransactionDirection;
use Ages\BankGateway\Entity\Enum\TransactionStatus;

/**
 * Dotaz na pohyby účtu.
 *
 * Období se filtruje podle data valuty (obě meze včetně). Směr a stav filtruje
 * knihovna až po stažení – API banky to neumí.
 */
readonly class TransactionQuery
{

    public function __construct(
        public string $accountId,
        public \DateTimeInterface $from,
        public \DateTimeInterface|null $to = null,
        public TransactionDirection|null $direction = null,
        public TransactionStatus|null $status = null,
    ) {
    }

    /**
     * Zaúčtované příchozí platby za posledních N dní – typický dotaz pro párování plateb.
     */
    public static function incoming(string $accountId, int $days = 7): self
    {
        return new self(
            accountId: $accountId,
            from: new \DateTimeImmutable(sprintf('-%d days', max(0, $days))),
            to: new \DateTimeImmutable('today'),
            direction: TransactionDirection::Incoming,
            status: TransactionStatus::Booked,
        );
    }

    public function accepts(Transaction $transaction): bool
    {
        return ($this->direction === null || $transaction->direction === $this->direction)
            && ($this->status === null || $transaction->status === $this->status);
    }

}
