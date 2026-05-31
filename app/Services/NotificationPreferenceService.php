<?php

namespace App\Services;

use App\Enums\NotificationEvent;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserNotificationPreference;

class NotificationPreferenceService
{
    public function isGloballyEnabled(NotificationEvent $event): bool
    {
        $value = Setting::getValue($event->settingKey(), 'true');

        return in_array($value, ['1', 'true', true], true);
    }

    public function isEmailEnabledForUser(?User $user, NotificationEvent $event): bool
    {
        if (! $this->isGloballyEnabled($event)) {
            return false;
        }

        if (! $user) {
            return true;
        }

        $pref = UserNotificationPreference::where('user_id', $user->id)
            ->where('event_key', $event->value)
            ->first();

        if ($pref === null) {
            return true;
        }

        return $pref->email_enabled;
    }

    public function isSmsEnabledForUser(?User $user, NotificationEvent $event): bool
    {
        if (! $this->isGloballyEnabled($event)) {
            return false;
        }

        if (! $user) {
            return true;
        }

        $pref = UserNotificationPreference::where('user_id', $user->id)
            ->where('event_key', $event->value)
            ->first();

        if ($pref === null) {
            return true;
        }

        return $pref->sms_enabled;
    }

    public function updatePreference(User $user, string $eventKey, bool $emailEnabled, bool $smsEnabled): UserNotificationPreference
    {
        return UserNotificationPreference::updateOrCreate(
            ['user_id' => $user->id, 'event_key' => $eventKey],
            ['email_enabled' => $emailEnabled, 'sms_enabled' => $smsEnabled]
        );
    }

    /**
     * @return array<int, string>
     */
    public function adminEmails(): array
    {
        $emails = [];

        $primary = Setting::getValue('admin_email', Setting::getValue('site_email', config('mail.from.address')));
        if ($primary) {
            $emails[] = $primary;
        }

        $admins = User::where('is_admin', true)->whereNotNull('email')->pluck('email')->all();

        return array_values(array_unique(array_filter(array_merge($emails, $admins))));
    }

    /**
     * Events a user can configure in their profile.
     *
     * @return array<string, string>
     */
    public function configurableEventsForUser(User $user): array
    {
        $events = [
            NotificationEvent::InvoiceGenerated,
            NotificationEvent::InvoiceReminder,
            NotificationEvent::InvoiceOverdue,
            NotificationEvent::PaymentReceived,
            NotificationEvent::ServiceActivated,
            NotificationEvent::ServiceSuspended,
            NotificationEvent::ServiceUnsuspended,
            NotificationEvent::ServiceTerminated,
            NotificationEvent::DomainExpiry,
            NotificationEvent::TicketCreated,
            NotificationEvent::TicketReplied,
        ];

        if ($user->is_reseller) {
            $events = array_merge($events, [
                NotificationEvent::ResellerDomainQueued,
                NotificationEvent::ResellerDomainPushed,
                NotificationEvent::ResellerNewCustomerOrder,
                NotificationEvent::ResellerWalletLow,
                NotificationEvent::ResellerWalletTopup,
            ]);
        }

        $result = [];
        foreach ($events as $event) {
            $result[$event->value] = $event->label();
        }

        return $result;
    }
}
