<?php

declare(strict_types=1);

namespace App\Domain\Calling;

use App\Domain\DomainException;

enum RecordingState: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Recording = 'recording';
    case Processing = 'processing';
    case Available = 'available';
    case Failed = 'failed';
    case DeletedByRetention = 'deleted_by_retention';

    public function canMoveTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::NotRequired => [],
            self::Pending => [self::Recording, self::Failed, self::Processing],
            self::Recording => [self::Processing, self::Failed],
            self::Processing => [self::Available, self::Failed],
            self::Failed => [self::Processing, self::Pending], // recovery: file found / retry / late provider link
            self::Available => [self::DeletedByRetention],
            self::DeletedByRetention => [],
        }, true);
    }

    public function to(self $to): self
    {
        if ($this === $to) {
            return $this; // idempotent
        }
        if (!$this->canMoveTo($to)) {
            throw new DomainException('invalid_recording_transition', "Recording cannot go from {$this->value} to {$to->value}.");
        }
        return $to;
    }

    /**
     * What to do when a mandatory recording cannot start / fails mid-call.
     * @return 'terminate'|'flag'|'ignore'
     */
    public static function failureAction(string $recordingMode, string $onFailure): string
    {
        if ($recordingMode !== 'mandatory') {
            return $recordingMode === 'off' ? 'ignore' : 'flag';
        }
        return $onFailure === 'terminate' ? 'terminate' : 'flag';
    }
}
