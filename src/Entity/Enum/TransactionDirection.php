<?php declare(strict_types=1);

namespace Ages\BankGateway\Entity\Enum;

enum TransactionDirection: string
{

    /** Příchozí platba (připsání na účet, CRDT). */
    case Incoming = 'incoming';

    /** Odchozí platba (odepsání z účtu, DBIT). */
    case Outgoing = 'outgoing';

}
