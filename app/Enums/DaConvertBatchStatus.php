<?php

namespace App\Enums;

enum DaConvertBatchStatus: string
{
    case Queued = 'queued';
    case Converting = 'converting';
    case ReadyForCutover = 'ready_for_cutover';
    case Failed = 'failed';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Converting => 'Converting',
            self::ReadyForCutover => 'Ready for cutover',
            self::Failed => 'Failed',
            self::Completed => 'Completed',
        };
    }
}
