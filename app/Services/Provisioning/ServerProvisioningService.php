<?php

namespace App\Services\Provisioning;

use App\Enums\NotificationEvent;
use App\Mail\AdminServerOrderMail;
use App\Models\Service;
use App\Models\Setting;
use App\Services\EmailDeliveryService;
use App\Services\NotificationService;
use Illuminate\Support\Str;

/**
 * Provision VPS and Dedicated Server products.
 *
 * Generates a secure password for the root user, stores credentials,
 * sets service to active, and notifies both customer and admin.
 */
class ServerProvisioningService
{
    /**
     * Provision a server service with auto-generated credentials.
     */
    public function provision(Service $service): void
    {
        $password = Str::password(16);

        $service->update([
            'credentials' => json_encode([
                'username' => 'root',
                'password' => $password,
            ]),
            'status' => 'active',
        ]);

        $service->load(['user', 'product']);
        $notifications = app(NotificationService::class);
        $notifications->notifyServerCredentials($service);

        $adminEmail = Setting::getValue('admin_email');
        if ($adminEmail) {
            app(EmailDeliveryService::class)->sendPlatformMailable(
                $adminEmail,
                new AdminServerOrderMail($service),
                'New server order — '.$service->name,
                NotificationEvent::AdminNewOrder,
            );
        }
    }
}
