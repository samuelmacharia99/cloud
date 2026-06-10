<?php

namespace App\Services;

use App\Enums\NotificationEvent;
use App\Models\Email;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Str;

class EmailPreviewService
{
    public function __construct(
        private ResellerBrandingResolver $brandingResolver,
    ) {}

    public function resolveRecipient(Email $email): ?User
    {
        if ($email->user_id) {
            return $email->relationLoaded('user')
                ? $email->user
                : User::find($email->user_id);
        }

        return User::where('email', $email->recipient)->first();
    }

    public function branding(Email $email): array
    {
        return $this->brandingResolver->forCustomer($this->resolveRecipient($email));
    }

    public function fromName(Email $email): string
    {
        $branding = $this->branding($email);
        $recipient = $this->resolveRecipient($email);
        $reseller = $recipient ? $this->brandingResolver->resellerForCustomer($recipient) : null;

        if ($reseller && ! empty($reseller->settings['smtp']['from_name'])) {
            return $reseller->settings['smtp']['from_name'];
        }

        return $branding['company_name'] ?? Setting::getValue('mail_from_name', config('mail.from.name'));
    }

    public function fromAddress(Email $email): string
    {
        $recipient = $this->resolveRecipient($email);
        $reseller = $recipient ? $this->brandingResolver->resellerForCustomer($recipient) : null;

        if ($reseller && ! empty($reseller->settings['smtp']['from_address'])) {
            return $reseller->settings['smtp']['from_address'];
        }

        return Setting::getValue('mail_from_address', config('mail.from.address'));
    }

    public function eventLabel(Email $email): ?string
    {
        $event = NotificationEvent::tryFrom($email->event_key ?? '');

        return $event?->label();
    }

    public function plainTextContent(Email $email): string
    {
        if (filled($email->body) && ! $this->bodyLooksLikeHtml($email->body)) {
            return $email->body;
        }

        $html = $this->customerHtml($email);

        if (blank($html)) {
            return '';
        }

        return $this->htmlToPlain($html);
    }

    public function customerHtml(Email $email): ?string
    {
        if (filled($email->html_body)) {
            return $email->html_body;
        }

        if (blank($email->body)) {
            return null;
        }

        if ($this->bodyLooksLikeHtml($email->body)) {
            return $email->body;
        }

        return $this->wrapPlainBodyInLayout($email);
    }

    public function hasPreview(Email $email): bool
    {
        return filled($this->customerHtml($email));
    }

    public static function htmlToPlain(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $text = preg_replace('/<\/(p|div|h[1-6]|tr|li)>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function wrapPlainBodyInLayout(Email $email): string
    {
        $branding = $this->branding($email);

        if ($this->usesGenericLayout($email)) {
            return view('emails.generic-notification', [
                'heading' => $email->subject,
                'body' => $email->body,
                'emailBranding' => $branding,
            ])->render();
        }

        return view('emails.templated-notification', [
            'bodyText' => $email->body,
            'templateData' => [],
            'emailBranding' => $branding,
        ])->render();
    }

    private function usesGenericLayout(Email $email): bool
    {
        $event = NotificationEvent::tryFrom($email->event_key ?? '');

        if ($event === null) {
            return false;
        }

        return in_array($event, [
            NotificationEvent::TicketCreated,
            NotificationEvent::TicketReplied,
            NotificationEvent::PaymentFailed,
            NotificationEvent::DomainTransferCompleted,
            NotificationEvent::DomainTransferFailed,
            NotificationEvent::ResellerSslProvisionFailed,
        ], true);
    }

    private function bodyLooksLikeHtml(string $body): bool
    {
        return Str::contains($body, ['<html', '<body', '<div class="container"', '<table']);
    }
}
