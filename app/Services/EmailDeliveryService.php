<?php

namespace App\Services;

use App\Enums\NotificationEvent;
use App\Mail\GenericNotificationMail;
use App\Mail\TemplatedNotificationMail;
use App\Models\Email;
use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;

class EmailDeliveryService
{
    public function __construct(
        private ResellerMailService $resellerMail,
        private NotificationPreferenceService $preferences,
        private EmailRateLimiter $rateLimiter,
        private ResellerBrandingResolver $brandingResolver,
    ) {}

    public function mailConfiguredFor(?User $customer = null): bool
    {
        if ($customer) {
            // Ownership boundary:
            // - Reseller-owned customers must use reseller SMTP only (no admin fallback).
            // - Admin-owned customers use platform/admin SMTP.
            if ($customer->reseller_id !== null) {
                $reseller = $this->brandingResolver->resellerForCustomer($customer);

                return $reseller !== null && $this->resellerMail->resellerSmtpEnabled($reseller);
            }
        }

        return $this->resellerMail->isConfigured();
    }

    public function sendCustomerMailable(
        User $customer,
        Mailable $mailable,
        string $subject,
        NotificationEvent $event,
        ?string $logBody = null,
    ): bool {
        if (! $this->mailConfiguredFor($customer)) {
            return false;
        }

        if (! $this->preferences->isEmailEnabledForUser($customer, $event)) {
            return false;
        }

        if (! $this->rateLimiter->allow($customer->email, $event)) {
            Log::warning('Email rate limited', ['email' => $customer->email, 'event' => $event->value]);

            return false;
        }

        try {
            $this->dispatchMail($customer->email, $mailable, $customer);
            $content = $this->captureMailableContent($mailable, $customer, $logBody);
            $this->logEmail($customer->email, $subject, 'sent', null, $content['body'], $event, $customer->id, null, $content['html_body']);

            return true;
        } catch (\Throwable $e) {
            $content = $this->captureMailableContent($mailable, $customer, $logBody);
            $this->logEmail($customer->email, $subject, 'failed', $e->getMessage(), $content['body'], $event, $customer->id, null, $content['html_body']);
            throw $e;
        }
    }

    public function sendPlatformMailable(
        string $email,
        Mailable $mailable,
        string $subject,
        NotificationEvent $event,
        ?User $user = null,
        ?string $logBody = null,
    ): bool {
        if (! $this->resellerMail->isConfigured()) {
            return false;
        }

        if ($user && ! $this->preferences->isEmailEnabledForUser($user, $event)) {
            return false;
        }

        if (! $this->rateLimiter->allow($email, $event)) {
            return false;
        }

        try {
            $this->dispatchMail($email, $mailable);
            $content = $this->captureMailableContent($mailable, $user, $logBody);
            $this->logEmail($email, $subject, 'sent', null, $content['body'], $event, $user?->id, null, $content['html_body']);

            return true;
        } catch (\Throwable $e) {
            $content = $this->captureMailableContent($mailable, $user, $logBody);
            $this->logEmail($email, $subject, 'failed', $e->getMessage(), $content['body'], $event, $user?->id, null, $content['html_body']);
            throw $e;
        }
    }

    public function sendToAdmins(Mailable $mailable, string $subject, NotificationEvent $event, ?string $logBody = null): int
    {
        if (! $this->preferences->isGloballyEnabled($event)) {
            return 0;
        }

        $sent = 0;
        foreach ($this->preferences->adminEmails() as $email) {
            try {
                if ($this->sendPlatformMailable($email, clone $mailable, $subject, $event, null, $logBody)) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                Log::error('Admin email failed', ['email' => $email, 'event' => $event->value, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    public function sendTemplated(
        ?User $recipient,
        NotificationEvent $event,
        array $data,
        ?string $overrideEmail = null,
    ): bool {
        $template = EmailTemplate::forEvent($event->value);

        if (! $template) {
            return false;
        }

        $email = $overrideEmail ?? $recipient?->email;
        if (! $email) {
            return false;
        }

        $subject = $template->renderSubject($data);
        $body = $template->renderBody($data);
        $mailable = new TemplatedNotificationMail($subject, $body, $data);

        if ($event->audience() === 'admin') {
            return $this->sendToAdmins($mailable, $subject, $event, $body) > 0;
        }

        if ($recipient === null) {
            return false;
        }

        if ($event->audience() === 'reseller' || $recipient->is_reseller) {
            return $this->sendPlatformMailable($email, $mailable, $subject, $event, $recipient, $body);
        }

        return $this->sendCustomerMailable($recipient, $mailable, $subject, $event, $body);
    }

    public function sendRawPlatform(string $email, string $subject, string $body, NotificationEvent $event, ?User $user = null): bool
    {
        $mailable = new GenericNotificationMail($subject, $subject, $body);

        return $this->sendPlatformMailable($email, $mailable, $subject, $event, $user, $body);
    }

    public function markBounced(string $messageId, ?string $reason = null): void
    {
        Email::where('message_id', $messageId)->update([
            'status' => 'bounced',
            'response' => $reason,
        ]);
    }

    /**
     * Resend a previously logged email (admin action). Bypasses rate limits and user preferences.
     */
    public function resendLoggedEmail(Email $email): void
    {
        $user = $email->user_id
            ? User::find($email->user_id)
            : User::where('email', $email->recipient)->first();

        if (! $this->mailConfiguredFor($user)) {
            throw new \RuntimeException('SMTP is not configured for this recipient.');
        }

        $event = NotificationEvent::tryFrom($email->event_key ?? '') ?? NotificationEvent::InvoiceGenerated;
        $mailable = new GenericNotificationMail($email->subject, $email->subject, $email->body);

        try {
            if ($user && $user->reseller_id !== null) {
                $this->resellerMail->sendToCustomer($user, $mailable, $email->subject);
            } else {
                Mail::to($email->recipient)->sendNow($mailable);
            }

            $this->logEmail(
                $email->recipient,
                $email->subject,
                'sent',
                null,
                $email->body,
                $event,
                $user?->id,
                null,
                $email->html_body,
            );
        } catch (\Throwable $e) {
            $this->logEmail(
                $email->recipient,
                $email->subject,
                'failed',
                $e->getMessage(),
                $email->body,
                $event,
                $user?->id,
                null,
                $email->html_body,
            );

            throw $e;
        }
    }

    protected function dispatchMail(string $email, Mailable $mailable, ?User $brandingCustomer = null): void
    {
        if ($brandingCustomer) {
            $this->resellerMail->sendToCustomer($brandingCustomer, $mailable);

            return;
        }

        if ($mailable instanceof ShouldQueue && $this->shouldQueue()) {
            Mail::to($email)->queue($mailable);

            return;
        }

        Mail::to($email)->send($mailable);
    }

    protected function shouldQueue(): bool
    {
        return in_array(Setting::getValue('email_queue_enabled', 'true'), ['1', 'true', true], true);
    }

    public function logEmail(
        string $to,
        string $subject,
        string $status,
        ?string $error = null,
        ?string $body = null,
        ?NotificationEvent $event = null,
        ?int $userId = null,
        ?string $messageId = null,
        ?string $htmlBody = null,
    ): void {
        try {
            Email::create([
                'recipient' => $to,
                'user_id' => $userId,
                'subject' => $subject,
                'event_key' => $event?->value,
                'message_id' => $messageId,
                'body' => $body ?? '',
                'html_body' => $htmlBody,
                'status' => $status,
                'response' => $error,
                'sent_by' => auth()->id(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to log email', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array{body: string, html_body: ?string}
     */
    protected function captureMailableContent(Mailable $mailable, ?User $customer, ?string $logBody): array
    {
        if ($customer) {
            View::share('emailBranding', $this->brandingResolver->forCustomer($customer));
        } elseif ($customer === null) {
            View::share('emailBranding', $this->brandingResolver->defaults());
        }

        $htmlBody = null;

        try {
            $htmlBody = $mailable->render();
        } catch (\Throwable $e) {
            Log::warning('Failed to render email for logging', ['error' => $e->getMessage()]);
        }

        $plainBody = $logBody;

        if ($plainBody === null && filled($htmlBody)) {
            $plainBody = EmailPreviewService::htmlToPlain($htmlBody);
        }

        return [
            'body' => $plainBody ?? '',
            'html_body' => $htmlBody,
        ];
    }
}
