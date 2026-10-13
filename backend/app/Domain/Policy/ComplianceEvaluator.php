<?php

declare(strict_types=1);

namespace App\Domain\Policy;

/**
 * Turns per-control acknowledgement results into an application state and a compliance verdict.
 * A device is compliant ONLY if every mandatory control is applied AND verified.
 */
final class ComplianceEvaluator
{
    public const PENDING = 'pending';
    public const APPLYING = 'applying';
    public const APPLIED = 'applied';
    public const PARTIAL = 'partially_applied';
    public const FAILED = 'failed';

    /**
     * @param array<string,mixed> $values effective policy
     * @param array<string,array{status:string,verified?:bool,detail?:string}> $results per-control results from device/AMAPI
     * @return array{state:string, compliant:bool, failing_mandatory:list<string>, failing_optional:list<string>}
     */
    public static function evaluate(array $values, array $results): array
    {
        $mandatory = $values['compliance.mandatory'] ?? [];
        if ($results === []) {
            return ['state' => self::PENDING, 'compliant' => false, 'failing_mandatory' => $mandatory, 'failing_optional' => []];
        }
        $failM = [];
        $failO = [];
        $pending = false;
        foreach (array_keys(PolicyCatalog::all()) as $key) {
            $r = $results[$key] ?? null;
            $ok = $r !== null && $r['status'] === 'applied' && ($r['verified'] ?? false) === true;
            if ($r === null || $r['status'] === 'pending') {
                $pending = true;
            }
            if (!$ok) {
                if (in_array($key, $mandatory, true)) {
                    $failM[] = $key;
                } elseif (($r['status'] ?? null) !== 'requires_setup') {
                    // Optional device-level controls on an app-only phone (no Android Management) cannot apply by design;
                    // they are not a partial failure. Mandatory ones still fail compliance.
                    $failO[] = $key;
                }
            }
        }
        if ($failM !== []) {
            $state = $pending ? self::APPLYING : self::FAILED;
        } elseif ($failO !== []) {
            $state = $pending ? self::APPLYING : self::PARTIAL;
        } else {
            $state = self::APPLIED;
        }
        return ['state' => $state, 'compliant' => $failM === [], 'failing_mandatory' => $failM, 'failing_optional' => $failO];
    }
}
