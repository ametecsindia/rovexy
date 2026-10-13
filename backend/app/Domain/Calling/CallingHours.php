<?php

declare(strict_types=1);

namespace App\Domain\Calling;

/** Evaluates a calling window in the COMPANY timezone. Overnight windows (start > end) are supported. */
final class CallingHours
{
    /** @param array{days:list<int>,start:string,end:string} $hours ISO days 1=Mon..7=Sun */
    public static function isOpen(array $hours, string $timezone, \DateTimeImmutable $nowUtc): bool
    {
        $local = $nowUtc->setTimezone(new \DateTimeZone($timezone));
        $minute = (int) $local->format('G') * 60 + (int) $local->format('i');
        [$sh, $sm] = array_map('intval', explode(':', $hours['start']));
        [$eh, $em] = array_map('intval', explode(':', $hours['end']));
        $start = $sh * 60 + $sm;
        $end = $eh * 60 + $em;
        $day = (int) $local->format('N');
        if ($start === $end) {
            return false;
        }
        if ($start < $end) {
            return in_array($day, $hours['days'], true) && $minute >= $start && $minute < $end;
        }
        // Overnight: the window belongs to the day it started.
        if ($minute >= $start) {
            return in_array($day, $hours['days'], true);
        }
        $prev = $day === 1 ? 7 : $day - 1;
        return $minute < $end && in_array($prev, $hours['days'], true);
    }
}
