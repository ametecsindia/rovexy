<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Security\LogRedactor;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

final class RedactingLogTap
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(static fn (LogRecord $r): LogRecord => $r->with(
            message: (string) LogRedactor::redact($r->message),
            context: LogRedactor::redact($r->context),
        ));
    }
}
