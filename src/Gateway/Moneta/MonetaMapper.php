<?php declare(strict_types=1);

namespace Ages\BankGateway\Gateway\Moneta;

use Ages\BankGateway\Entity\Balance;
use Ages\BankGateway\Entity\BankAccount;
use Ages\BankGateway\Entity\Enum\StatementFormat;
use Ages\BankGateway\Entity\Enum\TransactionDirection;
use Ages\BankGateway\Entity\Enum\TransactionStatus;
use Ages\BankGateway\Entity\Statement;
use Ages\BankGateway\Entity\Transaction;
use Ages\BankGateway\Exception\InvalidBankResponseException;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

/**
 * Převod odpovědí MONETA API na entity knihovny.
 *
 * Pohyby z `/transactions` i z JSON výpisu mají téměř stejnou strukturu (ISO 20022 camt),
 * liší se jen tím, že výpis nemá `remittanceInformation` a data posílá jako prostý řetězec.
 */
final class MonetaMapper
{

    /**
     * @param array<mixed> $data
     */
    public static function account(array $data): BankAccount
    {
        $identification = self::arr($data, 'identification');
        $servicer = self::arr($data, 'servicer');
        $owners = array_values(array_filter(
            is_array($data['ownersNames'] ?? null) ? $data['ownersNames'] : [],
            'is_string',
        ));

        return new BankAccount(
            id: self::str($data, 'id') ?? throw new InvalidBankResponseException('MONETA account without id.'),
            currency: self::str($data, 'currency') ?? '',
            iban: self::str($identification, 'iban'),
            accountNumber: self::str($identification, 'other'),
            bankCode: self::str($servicer, 'bankCode'),
            bic: self::str($servicer, 'bic'),
            name: self::str($data, 'nameI18N'),
            productName: self::str($data, 'productI18N'),
            ownerNames: $owners,
            raw: self::raw($data),
        );
    }

    /**
     * @param array<mixed> $data
     */
    public static function balance(array $data): Balance
    {
        $money = self::signedMoney(self::arr($data, 'amount'));
        if (self::str($data, 'creditDebitIndicator') === 'DBIT' && $money->isPositive()) {
            $money = $money->negated();
        }

        return new Balance(
            amount: $money,
            type: self::str(self::arr(self::arr($data, 'type'), 'codeOrProprietary'), 'code'),
            date: self::date(self::arr($data, 'date')['dateTime'] ?? null),
            raw: self::raw($data),
        );
    }

    /**
     * @param array<mixed> $data
     */
    public static function transaction(array $data): Transaction
    {
        $signed = self::signedMoney(self::arr($data, 'amount'));
        $direction = match (self::str($data, 'creditDebitIndicator')) {
            'CRDT' => TransactionDirection::Incoming,
            'DBIT' => TransactionDirection::Outgoing,
            default => $signed->isNegative() ? TransactionDirection::Outgoing : TransactionDirection::Incoming,
        };

        $details = self::arr(self::arr($data, 'entryDetails'), 'transactionDetails');
        $references = self::arr($details, 'references');
        $parties = self::arr($details, 'relatedParties');
        $remittance = self::arr($details, 'remittanceInformation');

        $symbols = self::parseSymbols(
            self::str(self::arr(self::arr($remittance, 'structured'), 'creditorReferenceInformation'), 'reference')
        );
        $endToEnd = self::str($references, 'endToEndIdentification');

        // VS může být i v endToEndIdentification (a u JSON výpisu jinde není)
        if ($symbols['vs'] === null && $endToEnd !== null && preg_match('/^\d{1,10}$/', $endToEnd) === 1) {
            $symbols['vs'] = self::stripZeros($endToEnd);
        }

        $counterpartyKey = $direction === TransactionDirection::Incoming ? 'debtor' : 'creditor';
        $counterpartyAccount = self::arr(self::arr($parties, $counterpartyKey . 'Account'), 'identification');
        $other = $counterpartyAccount['other'] ?? null;

        return new Transaction(
            id: self::str($data, 'entryReference'),
            amount: $signed->abs(),
            direction: $direction,
            status: self::str($data, 'status') === 'PDNG' ? TransactionStatus::Pending : TransactionStatus::Booked,
            bookingDate: self::date($data['bookingDate'] ?? null),
            valueDate: self::date($data['valueDate'] ?? null),
            variableSymbol: $symbols['vs'],
            constantSymbol: $symbols['ks'],
            specificSymbol: $symbols['ss'],
            message: self::str($remittance, 'unstructured'),
            counterpartyName: self::str(self::arr($parties, $counterpartyKey), 'name'),
            counterpartyAccount: is_array($other) ? self::str($other, 'identification') : (is_string($other) ? $other : null),
            counterpartyIban: self::str($counterpartyAccount, 'iban'),
            endToEndId: $endToEnd,
            description: self::str($references, 'transactionDescription')
                ?? self::str($details, 'additionalTransactionInformation'),
            type: self::str($references, 'clearingSystemReference'),
            reversal: ($data['reversalIndicator'] ?? false) === true,
            raw: self::raw($data),
        );
    }

    /**
     * @param array<mixed> $data
     */
    public static function statement(array $data): Statement
    {
        $formats = [];
        foreach (is_array($data['formats'] ?? null) ? $data['formats'] : [] as $format) {
            $value = is_array($format) ? self::str($format, 'format') : null;
            $case = $value !== null ? StatementFormat::tryFrom($value) : null;
            if ($case !== null) {
                $formats[] = $case;
            }
        }

        return new Statement(
            id: self::str($data, 'accountStatementId')
                ?? throw new InvalidBankResponseException('MONETA statement without accountStatementId.'),
            dateFrom: self::date($data['dateFrom'] ?? null),
            dateTo: self::date($data['dateTo'] ?? null),
            period: self::str($data, 'period'),
            year: self::int($data, 'year'),
            month: self::int($data, 'month'),
            sequenceNumber: self::int($data, 'sequenceNumber'),
            formats: $formats,
            raw: self::raw($data),
        );
    }

    /**
     * Rozparsuje „VS:123 KS:0308 SS:456“.
     *
     * @return array{vs: string|null, ks: string|null, ss: string|null}
     */
    public static function parseSymbols(string|null $reference): array
    {
        $result = ['vs' => null, 'ks' => null, 'ss' => null];
        if ($reference === null) {
            return $result;
        }

        foreach (['vs' => 'VS', 'ks' => 'KS', 'ss' => 'SS'] as $key => $label) {
            if (preg_match('/\b' . $label . '\s*:\s*(\d+)/i', $reference, $m) === 1) {
                // KS má smysl i s úvodními nulami (0308), VS/SS se porovnávají bez nich
                $result[$key] = $key === 'ks' ? $m[1] : self::stripZeros($m[1]);
            }
        }

        return $result;
    }

    /**
     * @param array<mixed> $amount
     */
    private static function signedMoney(array $amount): Money
    {
        $value = $amount['value'] ?? null;
        $currency = self::str($amount, 'currency');
        if (!is_numeric($value) || $currency === null) {
            throw new InvalidBankResponseException('MONETA amount without value or currency.');
        }

        return Money::of((string) $value, $currency, roundingMode: RoundingMode::HALF_UP);
    }

    private static function date(mixed $value): \DateTimeImmutable|null
    {
        if (is_array($value)) {
            $value = $value['date'] ?? $value['dateTime'] ?? null;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }

        // Jen datum (YYYY-MM-DD) bereme jako půlnoc v lokální zóně, ne v UTC
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return $date !== false ? $date : null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private static function stripZeros(string $value): string
    {
        $stripped = ltrim($value, '0');

        return $stripped === '' ? '0' : $stripped;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function arr(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * @param array<mixed> $data
     */
    private static function str(array $data, string $key): string|null
    {
        $value = $data[$key] ?? null;
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param array<mixed> $data
     */
    private static function int(array $data, string $key): int|null
    {
        return is_numeric($data[$key] ?? null) ? (int) $data[$key] : null;
    }

    /**
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private static function raw(array $data): array
    {
        /** @var array<string, mixed> $data */
        return $data;
    }

}
