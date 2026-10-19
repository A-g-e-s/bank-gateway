<?php declare(strict_types=1);

namespace Ages\BankGateway\Entity;

use Ages\BankGateway\Entity\Enum\StatementFormat;

/**
 * Stažený soubor výpisu (PDF, XML, ABO…).
 */
readonly class StatementFile
{

    public function __construct(
        public StatementFormat $format,
        public string $content,
        public string|null $contentType,
    ) {
    }

    public function suggestedFileName(string $statementId): string
    {
        $extension = match ($this->format) {
            StatementFormat::Pdf => 'pdf',
            StatementFormat::Xml => 'xml',
            default => 'gpc',
        };

        return sprintf('vypis-%s.%s', preg_replace('/[^A-Za-z0-9_-]/', '', $statementId), $extension);
    }

}
