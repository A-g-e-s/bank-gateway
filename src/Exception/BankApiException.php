<?php declare(strict_types=1);

namespace Ages\BankGateway\Exception;

/**
 * API banky vrátilo neúspěšný HTTP status.
 *
 * `errorCode` je kód chyby banky (např. MONETA `ID_NOT_FOUND`, `PARAMETER_INVALID`, `DT01`, `NARR`).
 */
class BankApiException extends BankGatewayException
{

    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly string|null $errorCode = null,
        public readonly string|null $responseBody = null,
        \Throwable|null $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public static function fromResponse(string $bank, int $statusCode, string $body): self
    {
        $message = sprintf('%s API returned HTTP %d', $bank, $statusCode);
        $errorCode = null;

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            // MONETA: {"errors":[{"error":"ID_NOT_FOUND","message":"...","scope":"..."}]}
            $first = is_array($decoded['errors'] ?? null) ? ($decoded['errors'][0] ?? null) : null;
            if (is_array($first)) {
                $errorCode = is_string($first['error'] ?? null) ? $first['error'] : null;
                $detail = is_string($first['message'] ?? null) ? $first['message'] : null;
                $message .= ': ' . implode(' – ', array_filter([$errorCode, $detail]));
            } elseif (is_string($decoded['error_description'] ?? null)) {
                // OAuth chyba tokenového endpointu
                $errorCode = is_string($decoded['error'] ?? null) ? $decoded['error'] : null;
                $message .= ': ' . $decoded['error_description'];
            }
        }

        return new self($message, $statusCode, $errorCode, $body);
    }

}
