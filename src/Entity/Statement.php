<?php declare(strict_types=1);

namespace Ages\BankGateway\Entity;

use Ages\BankGateway\Entity\Enum\StatementFormat;

/**
 * Metadata bankovního výpisu (bez pohybů).
 */
readonly class Statement
{

    /**
     * @param list<StatementFormat> $formats Formáty, ve kterých lze výpis stáhnout.
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable|null $dateFrom,
        public \DateTimeImmutable|null $dateTo,
        public string|null $period,
        public int|null $year,
        public int|null $month,
        public int|null $sequenceNumber,
        public array $formats = [],
        public array $raw = [],
    ) {
    }

}
