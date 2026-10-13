<?php

declare(strict_types=1);

namespace App\Domain\Devices;

use App\Domain\DomainException;

enum ApprovalState: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Revoked = 'revoked';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected],
            self::Approved => [self::Revoked],
            // Terminal for this registration. Re-approval requires a NEW registration (new key pair),
            // which guarantees fresh credentials and keeps the old key permanently invalid.
            self::Rejected, self::Revoked => [],
        };
    }

    public function transitionTo(self $next): self
    {
        if (!in_array($next, $this->allowedNext(), true)) {
            throw new DomainException('invalid_transition', "Cannot move device approval from {$this->value} to {$next->value}.");
        }
        return $next;
    }

    public function permitsWork(): bool
    {
        return $this === self::Approved;
    }
}
