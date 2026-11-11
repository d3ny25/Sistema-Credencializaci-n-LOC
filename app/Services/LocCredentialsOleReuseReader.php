<?php

declare(strict_types=1);

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Reader\Xls;

class LocCredentialsOleReuseReader extends Xls
{
    private static ?string $activeScope = null;

    private static ?string $expectedFileSha256 = null;

    /**
     * @var array{scope: string, data: string, summary: ?string, document_summary: ?string}|null
     */
    private static ?array $cachedStreams = null;

    public static function beginScope(string $scope, ?string $expectedSha256 = null): void
    {
        self::$activeScope = $scope;
        self::$expectedFileSha256 = $expectedSha256 !== null ? strtoupper($expectedSha256) : null;
        self::$cachedStreams = null;
    }

    public static function endScope(): void
    {
        self::$activeScope = null;
        self::$expectedFileSha256 = null;
        self::$cachedStreams = null;
    }

    public static function clearCache(): void
    {
        self::endScope();
    }

    public static function hasActiveCache(): bool
    {
        return self::$cachedStreams !== null;
    }

    public static function activeScope(): ?string
    {
        return self::$activeScope;
    }

    protected function loadOLE(string $filename): void
    {
        if (self::$activeScope === null) {
            parent::loadOLE($filename);

            return;
        }

        if (self::$cachedStreams !== null && self::$cachedStreams['scope'] === self::$activeScope) {
            $this->data = self::$cachedStreams['data'];
            $this->summaryInformation = self::$cachedStreams['summary'];
            $this->documentSummaryInformation = self::$cachedStreams['document_summary'];

            return;
        }

        if (self::$expectedFileSha256 !== null) {
            $actualHash = strtoupper((string) hash_file('sha256', $filename));
            if (! hash_equals(self::$expectedFileSha256, $actualHash)) {
                parent::loadOLE($filename);

                return;
            }
        }

        parent::loadOLE($filename);

        self::$cachedStreams = [
            'scope' => self::$activeScope,
            'data' => $this->data,
            'summary' => $this->summaryInformation,
            'document_summary' => $this->documentSummaryInformation,
        ];
    }
}

