<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class LocImportDiagnosticException extends RuntimeException
{
    public readonly string $causeExceptionType;

    public function __construct(
        public readonly string $incidentId,
        public readonly string $stage,
        public readonly string $errorCode,
        public readonly string $userMessage,
        public readonly string $technicalMessage,
        public readonly array $location,
        public readonly array $stageMetrics,
        Throwable $previous,
        public readonly array $diagnosticDetails = [],
    ) {
        $this->causeExceptionType = $previous::class;
        parent::__construct($userMessage, (int) $previous->getCode(), $previous);
    }
}
