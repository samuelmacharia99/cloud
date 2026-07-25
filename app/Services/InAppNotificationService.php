<?php

namespace App\Services;

use App\Enums\NotificationEvent;
use App\Models\CustomerNotification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class InAppNotificationService
{
    public function push(
        User $user,
        string $type,
        string $title,
        ?string $body = null,
        ?string $actionUrl = null,
        ?array $meta = null,
    ): CustomerNotification {
        return CustomerNotification::query()->create([
            'user_id' => $user->id,
            'type' => Str::limit($type, 64, ''),
            'title' => Str::limit($title, 255, ''),
            'body' => $body !== null ? Str::limit($body, 2000, '') : null,
            'action_url' => $actionUrl,
            'meta' => $meta,
        ]);
    }

    public function pushEvent(
        User $user,
        NotificationEvent $event,
        string $title,
        ?string $body = null,
        ?string $actionUrl = null,
    ): CustomerNotification {
        return $this->push($user, $event->value, $title, $body, $actionUrl);
    }

    /**
     * @return Collection<int, CustomerNotification>
     */
    public function recentFor(User $user, int $limit = 20): Collection
    {
        return CustomerNotification::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function unreadCount(User $user): int
    {
        return CustomerNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    public function markRead(User $user, int $id): bool
    {
        $notification = CustomerNotification::query()
            ->where('user_id', $user->id)
            ->whereKey($id)
            ->first();

        if (! $notification) {
            return false;
        }

        $notification->markRead();

        return true;
    }

    public function markAllRead(User $user): int
    {
        return CustomerNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
