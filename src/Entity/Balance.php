<?php declare(strict_types=1);

namespace Ages\BankGateway\Entity;

use Brick\Money\Money;

/**
 * Zůstatek účtu (záporná částka = debetní zůstatek).
 */
readonly class Balance
{

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public Money $amount,
        /** Kód typu zůstatku dle banky (`type.codeOrProprietary.code`). */
        public string|null $type,
        public \DateTimeImmutable|null $date,
        public array $raw = [],
    ) {
    }

}
