<?php

declare(strict_types=1);

namespace App\Domain;

/** Base for rule violations raised by the framework-independent domain core. */
class DomainException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
