<?php

declare(strict_types=1);

namespace App\Domain\Policy;

/** One configurable policy control. $scope: 'device' (enforced by the management layer) or 'app' (enforced by the employee app). */
final class Control
{
    /** @param list<string>|null $enum */
    public function __construct(
        public readonly string $key,
        public readonly string $type, // bool|int|string|enum|list|hours
        public readonly mixed $default,
        public readonly string $scope,
        public readonly string $label,
        public readonly ?array $enum = null,
        public readonly int $minSdk = 26,
        public readonly ?int $min = null,
        public readonly ?int $max = null,
        public readonly ?string $setupNote = null,
    ) {
    }
}
