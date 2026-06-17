<?php

namespace App\Services;

use App\Enums\NotificationEvent;
use App\Mail\GenericNotificationMail;
use App\Mail\TicketCreatedMail;
use App\Mail\TicketEscalatedCustomerMail;
use App\Mail\TicketRepliedMail;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TicketNotificationService
{
    public function __construct(
        private NotificationService $notifications,
        private EmailDeliveryService $emailDelivery,
        private NotificationPreferenceService $preferences,
        private SmsService $smsService,
        private TicketRoutingService $routing,
        private ResellerBrandingResolver $brandingResolver,
    ) {}

    public function notifyCreated(Ticket $ticket): void
    {
        $ticket->loadMissing('user');

        $this->notifyCustomerTicketCreated($ticket);

        if ($ticket->isHandledByPlatform()) {
            $this->notifyPlatformAdminsTicketCreated($ticket);

            return;
        }

        $this->notifyResellerNewCustomerTicket($ticket);
    }

    public function notifyReplied(Ticket $ticket, TicketReply $reply): void
    {
        $ticket->loadMissing('user', 'assignee');
        $reply->loadMissing('user');

        if ($reply->user?->is_admin) {
            $this->notifyCustomerTicketReplied($ticket, $reply);

            return;
        }

        if ($reply->user?->is_reseller) {
            $this->notifyCustomerTicketReplied($ticket, $reply);

            return;
        }

        if ($ticket->isHandledByReseller()) {
            $this->notifyResellerCustomerReplied($ticket, $reply);

            return;
        }

        $this->notifyPlatformStaffCustomerReplied($ticket, $reply);
    }

    public function notifyEscalated(Ticket $ticket): void
    {
        $ticket->loadMissing('user', 'escalatedByUser');

        if (! $this->platformTicketAlertsEnabled()) {
            return;
        }

        $reseller = $this->routing->owningReseller($ticket);
        $resellerName = $reseller?->name ?? 'Reseller';
        $subject = 'Ticket #'.$ticket->id.' escalated from '.$resellerName;

        $body = "Ticket #{$ticket->id} was escalated to platform support.\n\n"
            ."Customer: {$ticket->user->name}\n"
            ."Reseller: {$resellerName}\n"
            ."Title: {$ticket->title}\n"
            .'Priority: '.ucfirst($ticket->priority)."\n";

        if (filled($ticket->escalation_note)) {
            $body .= "\nNote from reseller:\n{$ticket->escalation_note}\n";
        }

        try {
            $this->emailDelivery->sendToAdmins(
                new GenericNotificationMail($subject, $body),
                $subject,
                NotificationEvent::TicketEscalated,
            );
        } catch (\Throwable $e) {
            Log::error('Failed to email admins about escalated ticket', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }

        $sms = "ESCALATED: Ticket #{$ticket->id} from {$resellerName} ({$ticket->user->name}). "
            .Str::limit($ticket->title, 50);
        $this->notifications->sendAdminSmsAlert(NotificationEvent::TicketEscalated, $sms);

        $this->notifyCustomerEscalated($ticket, $resellerName);
    }

    private function notifyCustomerTicketCreated(Ticket $ticket): void
    {
        if (! $this->preferences->isGloballyEnabled(NotificationEvent::TicketCreated)) {
            return;
        }

        if (! $this->emailDelivery->mailConfiguredFor($ticket->user)) {
            return;
        }

        try {
            $subject = 'Support Ticket #'.$ticket->id.' Created';
            $this->emailDelivery->sendCustomerMailable(
                $ticket->user,
                new TicketCreatedMail($ticket),
                $subject,
                NotificationEvent::TicketCreated,
                $ticket->description,
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send ticket created email to customer', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyPlatformAdminsTicketCreated(Ticket $ticket): void
    {
        if (! $this->platformTicketAlertsEnabled()) {
            return;
        }

        $subject = 'New support ticket #'.$ticket->id;
        $body = "New support ticket from {$ticket->user->name}.\n\n"
            ."Title: {$ticket->title}\n"
            .'Priority: '.ucfirst($ticket->priority)."\n\n"
            .$ticket->description;

        try {
            $this->emailDelivery->sendToAdmins(
                new GenericNotificationMail($subject, $body),
                $subject,
                NotificationEvent::TicketCreated,
            );
        } catch (\Throwable $e) {
            Log::error('Failed to email admins about new ticket', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }

        $sms = 'New support ticket #'.$ticket->id.' from '.$ticket->user->name
            .'. Priority: '.ucfirst($ticket->priority).'. Title: '.Str::limit($ticket->title, 50);
        $this->notifications->sendAdminSmsAlert(NotificationEvent::TicketCreated, $sms);
    }

    private function notifyResellerNewCustomerTicket(Ticket $ticket): void
    {
        if (! $this->resellerTicketAlertsEnabled()) {
            return;
        }

        $reseller = $this->routing->owningReseller($ticket);
        if (! $reseller?->email) {
            return;
        }

        $subject = 'Customer support ticket #'.$ticket->id;
        $body = "Your customer {$ticket->user->name} opened a support ticket.\n\n"
            ."Title: {$ticket->title}\n"
            .'Priority: '.ucfirst($ticket->priority)."\n\n"
            .$ticket->description;

        try {
            $this->emailDelivery->sendPlatformMailable(
                $reseller->email,
                new GenericNotificationMail($subject, $body),
                $subject,
                NotificationEvent::TicketCreated,
                $reseller,
                $body,
            );
        } catch (\Throwable $e) {
            Log::error('Failed to email reseller about customer ticket', [
                'ticket_id' => $ticket->id,
                'reseller_id' => $reseller->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($this->smsService->isConfigured() && filled($reseller->phone)) {
            try {
                $this->smsService->send(
                    $reseller->phone,
                    "Customer ticket #{$ticket->id} from {$ticket->user->name}: ".Str::limit($ticket->title, 60)
                );
            } catch (\Throwable $e) {
                Log::error('Failed to SMS reseller about customer ticket', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function notifyCustomerTicketReplied(Ticket $ticket, TicketReply $reply): void
    {
        if (! $this->preferences->isGloballyEnabled(NotificationEvent::TicketReplied)) {
            return;
        }

        if (! $this->emailDelivery->mailConfiguredFor($ticket->user)) {
            return;
        }

        try {
            $subject = 'Re: Support Ticket #'.$ticket->id;
            $this->emailDelivery->sendCustomerMailable(
                $ticket->user,
                new TicketRepliedMail($ticket, $reply),
                $subject,
                NotificationEvent::TicketReplied,
                $reply->message,
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send ticket reply email to customer', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($ticket->user->phone) {
            $branding = $this->brandingResolver->forCustomer($ticket->user);
            $message = "New reply on your support ticket #{$ticket->id} from {$branding['company_name']}. Log in to view.";
            $this->sendCustomerTicketSms($ticket->user, $message);
        }
    }

    private function notifyResellerCustomerReplied(Ticket $ticket, TicketReply $reply): void
    {
        if (! $this->resellerTicketAlertsEnabled()) {
            return;
        }

        $reseller = $this->routing->owningReseller($ticket);
        if (! $reseller?->email) {
            return;
        }

        $subject = "Customer replied: ticket #{$ticket->id}";
        $body = "{$ticket->user->name} replied to ticket #{$ticket->id}.\n\n{$reply->message}";

        try {
            $this->emailDelivery->sendPlatformMailable(
                $reseller->email,
                new GenericNotificationMail($subject, $body),
                $subject,
                NotificationEvent::TicketReplied,
                $reseller,
                $body,
            );
        } catch (\Throwable $e) {
            Log::error('Failed to email reseller about ticket reply', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($this->smsService->isConfigured() && filled($reseller->phone)) {
            try {
                $this->smsService->send(
                    $reseller->phone,
                    "Reply on ticket #{$ticket->id} from {$ticket->user->name}"
                );
            } catch (\Throwable $e) {
                Log::error('Failed to SMS reseller about ticket reply', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function notifyPlatformStaffCustomerReplied(Ticket $ticket, TicketReply $reply): void
    {
        if (! $this->platformTicketAlertsEnabled()) {
            return;
        }

        $notifyUser = $ticket->assignee ?? User::query()->where('is_admin', true)->orderBy('id')->first();
        if (! $notifyUser?->email) {
            return;
        }

        $subject = 'Customer reply: ticket #'.$ticket->id;

        try {
            $this->emailDelivery->sendPlatformMailable(
                $notifyUser->email,
                new TicketRepliedMail($ticket, $reply),
                $subject,
                NotificationEvent::TicketReplied,
                $notifyUser,
                $reply->message,
            );
        } catch (\Throwable $e) {
            Log::error('Failed to email staff about ticket reply', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($this->smsService->isConfigured()
            && is_array($notifyUser->notification_phones)
            && ! empty($notifyUser->notification_phones)) {
            try {
                $this->smsService->send(
                    $notifyUser->notification_phones,
                    'Customer reply to ticket #'.$ticket->id.'. Title: '.Str::limit($ticket->title, 50)
                );
            } catch (\Throwable $e) {
                Log::error('Failed to SMS staff about ticket reply', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function notifyCustomerEscalated(Ticket $ticket, string $resellerName): void
    {
        if (! $this->preferences->isGloballyEnabled(NotificationEvent::TicketCreated)) {
            return;
        }

        if (! $this->emailDelivery->mailConfiguredFor($ticket->user)) {
            return;
        }

        try {
            $subject = 'Your ticket #'.$ticket->id.' has been escalated';
            $this->emailDelivery->sendCustomerMailable(
                $ticket->user,
                new TicketEscalatedCustomerMail($ticket, $resellerName),
                $subject,
                NotificationEvent::TicketCreated,
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send ticket escalation email to customer', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendCustomerTicketSms(User $customer, string $message): void
    {
        if (! $this->preferences->isSmsEnabledForUser($customer, NotificationEvent::TicketReplied)) {
            return;
        }

        if ($customer->reseller_id !== null) {
            $reseller = $this->brandingResolver->resellerForCustomer($customer);
            $sms = $reseller?->settings['sms'] ?? [];
            if (! empty($sms['enabled']) && ! empty($sms['api_key']) && filled($customer->phone)) {
                app(TalksasaSmsService::class)->sendSms($reseller, $customer->phone, $message);
            }

            return;
        }

        if ($this->smsService->isConfigured() && filled($customer->phone)) {
            $this->smsService->send($customer->phone, $message);
        }
    }

    private function platformTicketAlertsEnabled(): bool
    {
        if (! $this->preferences->isGloballyEnabled(NotificationEvent::TicketCreated)) {
            return false;
        }

        return in_array(Setting::getValue('notify_ticket_platform', Setting::getValue('notify_ticket', '1')), ['1', 'true', true], true);
    }

    private function resellerTicketAlertsEnabled(): bool
    {
        if (! $this->preferences->isGloballyEnabled(NotificationEvent::TicketCreated)) {
            return false;
        }

        return in_array(Setting::getValue('notify_ticket_reseller', '1'), ['1', 'true', true], true);
    }
}
