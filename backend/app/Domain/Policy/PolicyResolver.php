<?php

declare(strict_types=1);

namespace App\Domain\Policy;

/**
 * Deterministic precedence: catalog default < company default < team override < device override.
 * Each layer is a partial key=>value map. Output records the winning source per key.
 */
final class PolicyResolver
{
    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $team
     * @param array<string,mixed> $device
     * @return array{values: array<string,mixed>, sources: array<string,string>}
     */
    public static function effective(array $company, array $team = [], array $device = []): array
    {
        $values = PolicyCatalog::defaults();
        $sources = array_fill_keys(array_keys($values), 'default');
        foreach (['company' => $company, 'team' => $team, 'device' => $device] as $scope => $layer) {
            foreach ($layer as $key => $value) {
                $values[$key] = PolicyCatalog::validate($key, $value);
                $sources[$key] = $scope;
            }
        }
        ksort($values);
        ksort($sources);
        return ['values' => $values, 'sources' => $sources];
    }

    /** Stable hash of the effective values — used as the device-side "policy version" fingerprint. */
    public static function fingerprint(array $values): string
    {
        ksort($values);
        return substr(hash('sha256', json_encode($values, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, 16);
    }
}
