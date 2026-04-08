<?php

namespace App\Services\Provisioning;

use App\Mail\ServerCredentialsMail;
use App\Mail\AdminServerOrderMail;
use App\Models\Service;
use Illuminate\Support\Facades\Mail;
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
     *
     * @param Service $service
     * @return void
     */
    public function provision(Service $service): void
    {
        // Generate a secure 16-character password with letters, numbers, and symbols
        $password = Str::password(16);

        // Store credentials in JSON format on the service
        $credentials = [
            'username' => 'root',
            'password' => $password,
        ];

        $service->update([
            'credentials' => json_encode($credentials),
            'status' => 'active',
        ]);

        // Send credentials email to customer
        Mail::to($service->user->email)->send(new ServerCredentialsMail($service));

        // Send order notification email to admin
        $adminEmail = \App\Models\Setting::where('key', 'admin_email')->value('value');
        if ($adminEmail) {
            Mail::to($adminEmail)->send(new AdminServerOrderMail($service));
        }
    }
}
