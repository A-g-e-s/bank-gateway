<?php declare(strict_types=1);

namespace Ages\BankGateway\Entity;

/**
 * Účet klienta tak, jak ho vrací API banky.
 */
readonly class BankAccount
{

    /**
     * @param list<string> $ownerNames
     * @param array<string, mixed> $raw
     */
    public function __construct(
        /** Identifikátor účtu v API banky – předává se do transactions() / balance(). */
        public string $id,
        public string $currency,
        public string|null $iban,
        /** Číslo účtu v českém formátu (např. `235703317/0600`). */
        public string|null $accountNumber,
        public string|null $bankCode,
        public string|null $bic,
        public string|null $name,
        public string|null $productName,
        public array $ownerNames = [],
        public array $raw = [],
    ) {
    }

    /**
     * Shoduje se účet s IBANem nebo číslem účtu? (ignoruje mezery, velikost písmen a úvodní nuly / předčíslí 0)
     */
    public function matches(string $ibanOrNumber): bool
    {
        $needle = self::normalize($ibanOrNumber);
        if ($needle === '') {
            return false;
        }

        foreach ([$this->iban, $this->accountNumber] as $candidate) {
            if ($candidate !== null && self::normalize($candidate) === $needle) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $value): string
    {
        $value = strtoupper((string) preg_replace('/\s+/', '', $value));

        // Číslo účtu „[předčíslí-]číslo/banka“ → bez úvodních nul a nulového předčíslí
        if (preg_match('~^(?:(\d+)-)?(\d+)/(\d{4})$~', $value, $m) === 1) {
            $prefix = ltrim($m[1], '0');
            $number = ltrim($m[2], '0');

            return ($prefix !== '' ? $prefix . '-' : '') . $number . '/' . $m[3];
        }

        return $value;
    }

}
