<?php

namespace App\Enums;

enum DaConvertBatchItemStatus: string
{
    case Queued = 'queued';
    case Converting = 'converting';
    case Converted = 'converted';
    case Failed = 'failed';
    case Blocked = 'blocked';
    case NeedsAck = 'needs_ack';
    case WaitingDns = 'waiting_dns';
    case WaitingMx = 'waiting_mx';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Converting => 'Converting',
            self::Converted => 'Converted',
            self::Failed => 'Failed',
            self::Blocked => 'Blocked',
            self::NeedsAck => 'Needs acknowledgement',
            self::WaitingDns => 'Waiting on DNS',
            self::WaitingMx => 'Waiting on MX',
            self::Done => 'Done',
        };
    }

    public function isActiveConvert(): bool
    {
        return in_array($this, [self::Queued, self::Converting], true);
    }
}
