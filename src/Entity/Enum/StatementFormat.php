<?php declare(strict_types=1);

namespace Ages\BankGateway\Entity\Enum;

enum StatementFormat: string
{

    case Xml = 'XML';
    case Pdf = 'PDF';
    case AboMmb = 'ABO_MMB';
    case AboKb = 'ABO_KB';
    case AboIpb = 'ABO_IPB';

}
