<?php declare(strict_types=1);

namespace Ages\BankGateway\Gateway\Moneta;

use Ages\BankGateway\Entity\BankAccount;
use Ages\BankGateway\Entity\Enum\StatementFormat;
use Ages\BankGateway\Entity\StatementFile;
use Ages\BankGateway\Entity\Transaction;
use Ages\BankGateway\Entity\TransactionQuery;
use Ages\BankGateway\Exception\InvalidBankResponseException;
use Ages\BankGateway\Gateway\BankInterface;

/**
 * MONETA Money Bank – služba VipAISPPayments_v4.
 *
 * @see https://apiportal.moneta.cz/apihub/#/wiki?mode=view&uri=Moneta-API
 */
class MonetaBank implements BankInterface
{

    private const string BASE = 'api/v4/vip/aisp/my/accounts';
    private const int PAGE_SIZE = 100;
    /** Pojistka proti zacyklení stránkování. */
    private const int MAX_PAGES = 500;

    /** @var list<BankAccount>|null */
    private array|null $accounts = null;

    public function __construct(
        private readonly MonetaClient $client,
    ) {
    }

    public function accounts(): array
    {
        if ($this->accounts !== null) {
            return $this->accounts;
        }

        $accounts = [];
        foreach ($this->paginate(self::BASE, [], 'accounts') as $row) {
            $accounts[] = MonetaMapper::account($row);
        }

        return $this->accounts = $accounts;
    }

    public function findAccount(string $ibanOrNumber): BankAccount|null
    {
        foreach ($this->accounts() as $account) {
            if ($account->matches($ibanOrNumber)) {
                return $account;
            }
        }

        return null;
    }

    public function balances(string $accountId, string|null $currency = null): array
    {
        $data = $this->client->getJson(self::accountPath($accountId, 'balance'), ['currency' => $currency]);

        return array_map(
            MonetaMapper::balance(...),
            self::rows($data, 'balances'),
        );
    }

    public function transactions(TransactionQuery $query): array
    {
        $rows = $this->paginate(self::accountPath($query->accountId, 'transactions'), [
            'fromDate' => $query->from->format('Y-m-d'),
            'toDate' => $query->to?->format('Y-m-d'),
            'sort' => 'date',
            'order' => 'ASC',
        ], 'transactions');

        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            $transaction = MonetaMapper::transaction($row);

            // Blokace se přidávají ke každé stránce navíc → mohou se opakovat
            if ($transaction->id !== null) {
                $key = $transaction->id . '|' . $transaction->status->value;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
            }

            if ($query->accepts($transaction)) {
                $result[] = $transaction;
            }
        }

        return $result;
    }

    public function statements(string $accountId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $data = $this->client->getJson(self::accountPath($accountId, 'statements'), [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]);

        return array_map(
            MonetaMapper::statement(...),
            self::rows($data, 'accountStatements'),
        );
    }

    public function statementTransactions(string $accountId, string $statementId): array
    {
        $data = $this->client->getJson(
            self::accountPath($accountId, 'statement/download'),
            self::statementQuery($statementId, 'JSON') + ['feature' => 'JSON$STND'],
        );

        return array_map(
            MonetaMapper::transaction(...),
            self::rows($data, 'transactions'),
        );
    }

    public function downloadStatement(string $accountId, string $statementId, StatementFormat $format): StatementFile
    {
        $data = $this->client->getJson(
            self::accountPath($accountId, 'statement/download'),
            self::statementQuery($statementId, $format->value),
        );

        $url = $data['dataDownloadURL'] ?? null;
        if (!is_string($url) || $url === '') {
            throw new InvalidBankResponseException('MONETA statement download did not return dataDownloadURL.');
        }

        $response = $this->client->get(self::BASE . '/statement', ['file' => self::fileIdentifier($url)]);

        return new StatementFile(
            format: $format,
            content: (string) $response->getBody(),
            contentType: $response->getHeaderLine('Content-Type') ?: null,
        );
    }

    /**
     * Projde všechny stránky stránkovaného endpointu (page od 0, `nextPage` chybí na poslední stránce).
     *
     * @param array<string, scalar|null> $query
     * @return list<array<mixed>>
     */
    private function paginate(string $path, array $query, string $itemsKey): array
    {
        $items = [];
        $page = 0;

        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            $data = $this->client->getJson($path, $query + ['page' => $page, 'size' => self::PAGE_SIZE]);
            array_push($items, ...self::rows($data, $itemsKey));

            $next = $data['nextPage'] ?? null;
            if (!is_int($next) || $next <= $page) {
                return $items;
            }
            $page = $next;
        }

        throw new InvalidBankResponseException(sprintf('MONETA pagination of %s exceeded %d pages.', $path, self::MAX_PAGES));
    }

    /**
     * Dokumentace se rozchází v názvu parametru (spec `accountStatementId`, wiki `statementId`) → posíláme oba.
     *
     * @return array<string, string>
     */
    private static function statementQuery(string $statementId, string $format): array
    {
        return [
            'accountStatementId' => $statementId,
            'statementId' => $statementId,
            'format' => $format,
        ];
    }

    /**
     * `dataDownloadURL` obsahuje identifikátor souboru – buď jako `?file=…`, nebo jako poslední segment cesty.
     */
    private static function fileIdentifier(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $params);
            if (is_string($params['file'] ?? null) && $params['file'] !== '') {
                return $params['file'];
            }
        }

        $path = parse_url($url, PHP_URL_PATH);
        $segment = is_string($path) ? basename($path) : '';

        return $segment !== '' && $segment !== 'statement' ? $segment : $url;
    }

    private static function accountPath(string $accountId, string $suffix): string
    {
        return self::BASE . '/' . rawurlencode($accountId) . '/' . $suffix;
    }

    /**
     * @param array<mixed> $data
     * @return list<array<mixed>>
     */
    private static function rows(array $data, string $key): array
    {
        $rows = $data[$key] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

}
