<?php declare(strict_types=1);

namespace Ages\BankGateway\Entity\Enum;

enum TransactionStatus: string
{

    /** Zaúčtovaná transakce (BOOK) – pro párování plateb používej jen tyto. */
    case Booked = 'booked';

    /** Čekající / blokovaná transakce (PDNG). */
    case Pending = 'pending';

}
