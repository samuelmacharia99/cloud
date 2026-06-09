<?php

namespace App\Support;

class ServiceLiveStatusResult
{
    /**
     * @param  array<string, mixed>  $detail
     */
    public function __construct(
        public readonly string $status,
        public readonly string $label,
        public readonly string $source,
        public readonly array $detail = [],
        public readonly bool $checked = true,
        public readonly ?string $error = null,
    ) {}

    public static function unavailable(string $reason, string $source = 'none'): self
    {
        return new self(
            status: 'unavailable',
            label: $reason,
            source: $source,
            checked: false,
            error: $reason,
        );
    }

    public static function unknown(string $reason, string $source): self
    {
        return new self(
            status: 'unknown',
            label: $reason,
            source: $source,
            error: $reason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'live_status' => $this->status,
            'live_status_label' => $this->label,
            'live_status_source' => $this->source,
            'live_status_detail' => $this->detail,
            'live_status_checked_at' => now(),
            'error' => $this->error,
        ];
    }
}
