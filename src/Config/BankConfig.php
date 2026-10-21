<?php declare(strict_types=1);

namespace Ages\BankGateway\Config;

use Ages\BankGateway\Entity\Enum\Bank;

/**
 * Konfigurace jedné banky – předává se v DI (NEON), nebo za běhu do BankGateway::connect()
 * (např. API klíč uložený v databázi, který se pravidelně obnovuje).
 */
interface BankConfig
{

    public function bank(): Bank;

    /** Jsou vyplněné přihlašovací údaje (API klíč / client credentials)? */
    public function hasCredentials(): bool;

}
