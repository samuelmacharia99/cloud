<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\EmailDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sends one admin broadcast email, then schedules the next recipient after a delay
 * so SMTP providers are not flooded (1 email every N seconds).
 */
class SendAdminBroadcastEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const DELAY_SECONDS = 5;

    public int $tries = 3;

    /**
     * @param  list<int>  $userIds
     */
    public function __construct(
        public array $userIds,
        public string $subject,
        public string $body,
        public int $index = 0,
        public ?int $sentById = null,
    ) {}

    public function handle(EmailDeliveryService $emailDelivery): void
    {
        $userId = $this->userIds[$this->index] ?? null;

        if ($userId === null) {
            return;
        }

        $customer = User::query()->find($userId);

        if ($customer) {
            try {
                $emailDelivery->sendAdminBroadcast(
                    $customer,
                    $this->subject,
                    $this->body,
                    $this->sentById,
                );
            } catch (\Throwable $e) {
                Log::error('Admin broadcast job failed', [
                    'user_id' => $userId,
                    'index' => $this->index,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $nextIndex = $this->index + 1;

        if (! isset($this->userIds[$nextIndex])) {
            return;
        }

        self::dispatch(
            $this->userIds,
            $this->subject,
            $this->body,
            $nextIndex,
            $this->sentById,
        )->delay(now()->addSeconds(self::DELAY_SECONDS));
    }
}
