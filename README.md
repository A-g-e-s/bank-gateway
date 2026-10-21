# ages/bank-gateway

Jednotné **read-only** API k bankovním účtům: seznam účtů, zůstatky, pohyby a výpisy. Slouží k párování příchozích plateb (např. uvolnění objednávek placených převodem). Začínáme s **MONETA Money Bank**, další banky přibudou jako další implementace `BankInterface`.

- PHP 8.4+, Guzzle, `brick/money`
- Stránkování, opakování po HTTP 429 a autorizaci řeší knihovna
- Částky jako `Brick\Money\Money`, VS/KS/SS rozparsované

## Konfigurace (Nette DI / NEON)

```neon
services:
    monetaConfig: Ages\BankGateway\Config\MonetaConfig(
        apiKey: 'api-klic-z-internetoveho-bankovnictvi'
        # applicationName: 'Wolfio'           # hlavička application_name (volitelné)
        # identificationNumber: '12345678'    # hlavička identification_number = IČO (volitelné)
        # businessName: 'Firma s.r.o.'        # hlavička business_name (volitelné)
        # timeout: 30                          # výchozí hodnota
        # rateLimitRetries: 3                  # výchozí hodnota
    )

    - Ages\BankGateway\BankGateway(monetaConfig: @monetaConfig)
```

### Konfigurace za běhu (API klíč z databáze)

API klíč z internetového bankovnictví má omezenou platnost a pravidelně se obnovuje → nemusí být v NEONu. V NEONu zůstane jen společné nastavení bez klíče a klíč se doplní za běhu:

```neon
services:
    monetaConfig: Ages\BankGateway\Config\MonetaConfig(applicationName: 'Wolfio')
    - Ages\BankGateway\BankGateway(monetaConfig: @monetaConfig)
```

```php
use Ages\BankGateway\Config\MonetaConfig;
use Ages\BankGateway\Entity\Enum\Bank;

$base = $this->bankGateway->config(Bank::Moneta) ?? new MonetaConfig();   // nastavení z NEONu
$moneta = $this->bankGateway->connect($base->withApiKey($account->apiKey)); // klíč z DB
```

`connect()` přijme libovolnou `BankConfig` (každá banka má vlastní config třídu), pro stejnou konfiguraci vrací stejného klienta a bez přihlašovacích údajů vyhodí `GatewayInitializationException`. Obě cesty lze kombinovat – `bank(Bank)` dál používá konfiguraci z DI.

Konfigurace banky je **volitelná** — `BankGateway` bez `monetaConfig` normálně nastartuje, chyba (`GatewayInitializationException`) přijde až při `bank(Bank::Moneta)`. `isConfigured(Bank)` vrací `true`, jen když DI konfigurace obsahuje i přihlašovací údaje. Klient se sestaví líně při prvním použití.

### MONETA – produkce vs. sandbox

| Režim    | Autorizace | Konfigurace |
|----------|------------|-------------|
| Produkce | API klíč, který si klient vygeneruje v internetovém bankovnictví (Správa API služby, [návod PDF](https://ib.moneta.cz/documents/primebankovnictvi/Sprava-API-sluzby.pdf)); posílá se přímo jako `Bearer` | `apiKey` |
| Sandbox  | OAuth2 `client_credentials` se scope `vip_sandbox` (token platí ~20 min, knihovna ho obnovuje) | `clientId`, `clientSecret`, `sandbox: true` |

```neon
    monetaConfig: Ages\BankGateway\Config\MonetaConfig(
        clientId:     'client-id-z-api-portalu'
        clientSecret: 'client-secret-z-api-portalu'
        sandbox:      true
    )
```

Předpoklady:
1. Firemní účet v MONETĚ s aktivním internetovým bankovnictvím a zapnutou službou **MONETA API**.
2. Na [API portálu](https://apiportal.moneta.cz/apihub/) aplikace s přístupem ke službě **VipAISPPayments_v4** (pro sandbox navíc „Sandbox API plan“ a scope `vip_sandbox`).

Sandbox vrací předdefinovaná testovací data a nevaliduje parametry — vhodný na „happy day“, chybové stavy testuj na reálném účtu.

Volitelně lze `BankGateway` předat PSR-16 cache (`psr/simple-cache`) — sdílí OAuth token mezi requesty. U produkčního API klíče není potřeba.

---

## Použití

```php
use Ages\BankGateway\Entity\Enum\Bank;

$moneta = $this->bankGateway->bank(Bank::Moneta);   // nebo ->moneta()
```

### Účty

```php
foreach ($moneta->accounts() as $account) {
    echo $account->id, ' ', $account->iban, ' ', $account->accountNumber, ' ', $account->currency;
}

// Identifikátor účtu v API (id) se liší od čísla účtu → dohledání podle IBANu / čísla účtu
$account = $moneta->findAccount('CZ38 0600 0000 0002 3570 3317');   // nebo '235703317/0600'
```

### Párování příchozích plateb

```php
use Ages\BankGateway\Entity\TransactionQuery;

// Zaúčtované příchozí platby za posledních 7 dní (filtr podle data valuty)
$payments = $moneta->transactions(TransactionQuery::incoming($account->id, days: 7));

foreach ($payments as $payment) {
    if (!$payment->isPayableIncoming()) {      // zaúčtovaná, příchozí, ne storno
        continue;
    }

    $order = $orders->findUnpaidByCode($payment->variableSymbol);   // VS bez úvodních nul
    if ($order === null || !$payment->amount->isGreaterThanOrEqualTo($order->priceTax)) {
        continue;
    }

    // $payment->id = entryReference banky → ulož, ať se platba nespáruje dvakrát
    $bankTransferService->markPaid($order, $payment->bookingDate, $payment->id);
}
```

Vlastní dotaz:

```php
use Ages\BankGateway\Entity\Enum\TransactionDirection;
use Ages\BankGateway\Entity\Enum\TransactionStatus;

$query = new TransactionQuery(
    accountId: $account->id,
    from:      new DateTimeImmutable('2026-10-01'),
    to:        new DateTimeImmutable('2026-10-31'),     // včetně; null = bez horní meze
    direction: TransactionDirection::Incoming,          // null = obě
    status:    TransactionStatus::Booked,               // null = i čekající / blokace
);
```

> Historie pohybů je dostupná nejvýše 2 roky zpět. Starší historie může vyžadovat dvoufázové ověření (chyba `401 NARR`).

### Zůstatek

```php
foreach ($moneta->balances($account->id) as $balance) {
    echo $balance->amount;   // CZK 125000.00 (záporné = debet)
}
```

### Výpisy

```php
use Ages\BankGateway\Entity\Enum\StatementFormat;

$statements = $moneta->statements($account->id, new DateTimeImmutable('-1 month'), new DateTimeImmutable());

// Pohyby výpisu (JSON) – pozor: výpis nemá zprávu pro příjemce ani strukturovaný VS,
// VS se bere jen z endToEndIdentification. Pro párování používej transactions().
$transactions = $moneta->statementTransactions($account->id, $statements[0]->id);

// Soubor výpisu (PDF, XML, ABO_*) – sandbox vrací vždy XML
$file = $moneta->downloadStatement($account->id, $statements[0]->id, StatementFormat::Pdf);
file_put_contents($file->suggestedFileName($statements[0]->id), $file->content);
```

---

## Struktury

### `Transaction`

| Property              | Typ                    | Popis |
|-----------------------|------------------------|-------|
| `id`                  | `string\|null`         | identifikátor pohybu v bance (MONETA `entryReference`) |
| `amount`              | `Money`                | vždy kladná částka |
| `direction`           | `TransactionDirection` | `Incoming` / `Outgoing` |
| `status`              | `TransactionStatus`    | `Booked` / `Pending` |
| `bookingDate`         | `DateTimeImmutable\|null` | datum zaúčtování |
| `valueDate`           | `DateTimeImmutable\|null` | datum valuty |
| `variableSymbol`      | `string\|null`         | bez úvodních nul |
| `constantSymbol`      | `string\|null`         | s úvodními nulami (`0308`) |
| `specificSymbol`      | `string\|null`         | bez úvodních nul |
| `message`             | `string\|null`         | zpráva pro příjemce |
| `counterpartyName`    | `string\|null`         | plátce (příchozí) / příjemce (odchozí) |
| `counterpartyAccount` | `string\|null`         | číslo účtu protistrany |
| `counterpartyIban`    | `string\|null`         | |
| `endToEndId`          | `string\|null`         | |
| `description`         | `string\|null`         | popis pohybu dle banky |
| `type`                | `string\|null`         | např. `PAYMENT_ORDER_DOMESTIC`, `PAYMENT_CARD` |
| `reversal`            | `bool`                 | storno |
| `raw`                 | `array`                | původní data z API |

Metody: `isIncoming()`, `isBooked()`, `isPayableIncoming()`, `hasVariableSymbol($vs)`.

### `BankAccount`

`id`, `currency`, `iban`, `accountNumber`, `bankCode`, `bic`, `name`, `productName`, `ownerNames`, `raw`; metoda `matches($ibanOrNumber)`.

### `Balance`

`amount` (`Money`, se znaménkem), `type`, `date`, `raw`.

### `Statement` / `StatementFile`

`Statement`: `id`, `dateFrom`, `dateTo`, `period`, `year`, `month`, `sequenceNumber`, `formats` (`list<StatementFormat>`).
`StatementFile`: `format`, `content`, `contentType`, `suggestedFileName()`.

---

## Výjimky

Všechny rozšiřují `Ages\BankGateway\Exception\BankGatewayException`.

| Třída                            | Kdy nastane |
|----------------------------------|-------------|
| `GatewayInitializationException` | banka není nakonfigurována / chybí `apiKey` i `clientId`+`clientSecret` |
| `BankApiException`               | API vrátilo chybový HTTP status; `statusCode`, `errorCode` (`ID_NOT_FOUND`, `PARAMETER_INVALID`, `DT01`, `NARR`…), `responseBody` |
| `InvalidBankResponseException`   | neočekávaný formát odpovědi |
| `BankGatewayException`           | síťová chyba (timeout, DNS…) |

---

## Architektura

```
src/
├── Auth/AccessToken.php              OAuth token + expirace (sandbox)
├── Config/BankConfig.php            rozhraní konfigurace banky (bank(), hasCredentials())
├── Config/MonetaConfig.php
├── Entity/
│   ├── Enum/ Bank, TransactionDirection, TransactionStatus, StatementFormat
│   ├── BankAccount.php, Balance.php, Transaction.php
│   ├── TransactionQuery.php
│   └── Statement.php, StatementFile.php
├── Exception/
├── Gateway/
│   ├── BankInterface.php             accounts, findAccount, balances, transactions, statements, …
│   └── Moneta/
│       ├── MonetaClient.php          HTTP, autorizace, 429 retry, chyby
│       ├── MonetaMapper.php          JSON → entity, parsování VS/KS/SS
│       └── MonetaBank.php            VipAISPPayments_v4
└── BankGateway.php                   DI entry point – bank(Bank) / connect(BankConfig) vrátí BankInterface
```

Nová banka = nová implementace `BankInterface` v `Gateway/<Banka>/`, případ v enumu `Bank` a config třída implementující `BankConfig` (+ větev v `BankGateway::create()`).
