<?php declare(strict_types=1);

namespace Ages\BankGateway\Gateway;

use Ages\BankGateway\Entity\Balance;
use Ages\BankGateway\Entity\BankAccount;
use Ages\BankGateway\Entity\Enum\StatementFormat;
use Ages\BankGateway\Entity\Statement;
use Ages\BankGateway\Entity\StatementFile;
use Ages\BankGateway\Entity\Transaction;
use Ages\BankGateway\Entity\TransactionQuery;

/**
 * Pasivní (read-only) přístup k bankovnímu účtu.
 */
interface BankInterface
{

    /**
     * @return list<BankAccount>
     */
    public function accounts(): array;

    /**
     * Najde účet podle IBANu nebo čísla účtu (`123456789/0600`), null = nenalezen.
     */
    public function findAccount(string $ibanOrNumber): BankAccount|null;

    /**
     * @return list<Balance>
     */
    public function balances(string $accountId, string|null $currency = null): array;

    /**
     * Všechny pohyby odpovídající dotazu (stránkování řeší knihovna), seřazené od nejstaršího.
     * Pro párování plateb – obsahuje VS/KS/SS i zprávu pro příjemce.
     *
     * @return list<Transaction>
     */
    public function transactions(TransactionQuery $query): array;

    /**
     * Výpisy z účtu, jejichž období zasahuje do zadaného rozsahu.
     *
     * @return list<Statement>
     */
    public function statements(string $accountId, \DateTimeInterface $from, \DateTimeInterface $to): array;

    /**
     * Pohyby konkrétního výpisu.
     *
     * @return list<Transaction>
     */
    public function statementTransactions(string $accountId, string $statementId): array;

    /**
     * Stáhne výpis jako soubor (PDF, XML, ABO…).
     */
    public function downloadStatement(string $accountId, string $statementId, StatementFormat $format): StatementFile;

}
