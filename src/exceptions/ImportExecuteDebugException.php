<?php

declare(strict_types=1);

namespace App\exceptions;

class ImportExecuteDebugException extends \RuntimeException
{
    public function __construct(string $message, private array $debugContext = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function debugContext(): array
    {
        return $this->debugContext;
    }
}

