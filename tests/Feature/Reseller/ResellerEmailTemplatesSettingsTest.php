<?php

namespace Tests\Feature\Reseller;

use App\Mail\TemplatedNotificationMail;
use App\Models\Invoice;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\ResellerEmailTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResellerEmailTemplatesSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function createResellerWithSmtp(): User
    {
        return User::factory()->reseller()->create([
            'name' => 'Reseller Person',
            'settings' => [
                'smtp' => [
                    'host' => 'smtp.example.test',
                    'port' => 587,
                    'username' => 'mailer@example.test',
                    'password' => 'secret',
                    'encryption' => 'tls',
                    'from_address' => 'noreply@example.test',
                    'from_name' => 'Example Hosting',
                    'enabled' => true,
                ],
                'branding' => [
                    'company_name' => 'Example Hosting Co',
                ],
            ],
        ]);
    }

    public function test_email_tab_lists_customer_email_templates(): void
    {
        $reseller = $this->createResellerWithSmtp();

        $this->actingAs($reseller)
            ->get(route('reseller.settings.index', ['tab' => 'email']))
            ->assertOk()
            ->assertSee('Email Templates', false)
            ->assertSee('Invoice Generated', false)
            ->assertSee('Account Welcome', false)
            ->assertSee('invoice_generated', false);
    }

    public function test_reseller_can_update_and_reset_email_template(): void
    {
        $reseller = $this->createResellerWithSmtp();

        $this->actingAs($reseller)
            ->putJson(route('reseller.settings.email-templates.update', 'invoice_generated'), [
                'subject' => 'Custom invoice {invoice_number}',
                'body' => 'Hello {customer_name}, pay {amount}.',
                'enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $reseller->refresh();
        $this->assertSame(
            'Custom invoice {invoice_number}',
            $reseller->settings['email_templates']['invoice_generated']['subject']
        );

        $this->actingAs($reseller)
            ->postJson(route('reseller.settings.email-templates.reset', 'invoice_generated'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $reseller->refresh();
        $this->assertArrayNotHasKey('invoice_generated', $reseller->settings['email_templates'] ?? []);
    }

    public function test_customized_template_is_used_when_notifying_reseller_customer(): void
    {
        Mail::fake();

        $reseller = $this->createResellerWithSmtp();
        app(ResellerEmailTemplateService::class)->update($reseller, 'invoice_generated', [
            'subject' => 'Pay up {invoice_number}',
            'body' => 'Hey {customer_name}, amount due is {amount}.',
            'enabled' => true,
        ]);

        $customer = User::factory()->customer()->create([
            'reseller_id' => $reseller->id,
            'name' => 'Ada Customer',
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'invoice_number' => 'INV-TEST-1',
            'total' => 2500,
            'status' => 'unpaid',
        ]);

        app(NotificationService::class)->notifyInvoiceGenerated($invoice->fresh(['user']));

        Mail::assertSent(TemplatedNotificationMail::class, function (TemplatedNotificationMail $mail) {
            return $mail->mailSubject === 'Pay up INV-TEST-1'
                && str_contains($mail->bodyText, 'Hey Ada Customer')
                && str_contains($mail->bodyText, '2,500.00');
        });
    }

    public function test_site_name_uses_branding_company_name_not_reseller_user_name(): void
    {
        Mail::fake();

        $reseller = $this->createResellerWithSmtp();
        app(ResellerEmailTemplateService::class)->update($reseller, 'invoice_generated', [
            'subject' => 'Invoice from {site_name}',
            'body' => 'Thanks for choosing {site_name}.',
            'enabled' => true,
        ]);

        $customer = User::factory()->customer()->create([
            'reseller_id' => $reseller->id,
            'name' => 'Ada Customer',
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'invoice_number' => 'INV-BRAND-1',
            'total' => 1000,
            'status' => 'unpaid',
        ]);

        app(NotificationService::class)->notifyInvoiceGenerated($invoice->fresh(['user']));

        Mail::assertSent(TemplatedNotificationMail::class, function (TemplatedNotificationMail $mail) {
            return $mail->mailSubject === 'Invoice from Example Hosting Co'
                && str_contains($mail->bodyText, 'Thanks for choosing Example Hosting Co.')
                && ! str_contains($mail->bodyText, 'Reseller Person')
                && ! str_contains($mail->mailSubject, 'Talksasa');
        });
    }

    public function test_customer_email_templates_scrub_reseller_wording(): void
    {
        Mail::fake();

        $reseller = $this->createResellerWithSmtp();
        app(ResellerEmailTemplateService::class)->update($reseller, 'invoice_generated', [
            'subject' => 'Update from your reseller account',
            'body' => 'Sign in to the reseller portal for invoice {invoice_number}.',
            'enabled' => true,
        ]);

        $customer = User::factory()->customer()->create([
            'reseller_id' => $reseller->id,
            'name' => 'Ada Customer',
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'invoice_number' => 'INV-WL-1',
            'total' => 500,
            'status' => 'unpaid',
        ]);

        app(NotificationService::class)->notifyInvoiceGenerated($invoice->fresh(['user']));

        Mail::assertSent(TemplatedNotificationMail::class, function (TemplatedNotificationMail $mail) {
            return $mail->mailSubject === 'Update from your account'
                && str_contains($mail->bodyText, 'Sign in to the client portal for invoice INV-WL-1.')
                && ! preg_match('/reseller/i', $mail->mailSubject.$mail->bodyText);
        });
    }

    public function test_disabled_template_skips_customer_email(): void
    {
        Mail::fake();

        $reseller = $this->createResellerWithSmtp();
        app(ResellerEmailTemplateService::class)->update($reseller, 'invoice_generated', [
            'subject' => 'Should not send',
            'body' => 'Nope',
            'enabled' => false,
        ]);

        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
        ]);

        app(NotificationService::class)->notifyInvoiceGenerated($invoice->fresh(['user']));

        Mail::assertNothingSent();
    }
}
