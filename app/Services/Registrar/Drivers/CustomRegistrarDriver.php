<?php

namespace App\Services\Registrar\Drivers;

use App\Models\Registrar;
use App\Services\Registrar\RegistrarDriverInterface;

class CustomRegistrarDriver implements RegistrarDriverInterface
{
    public function testConnection(Registrar $registrar): array
    {
        $config = $registrar->config ?? [];

        if (empty($config['api_url'])) {
            return [
                'success' => false,
                'message' => 'API base URL is required before testing the connection.',
            ];
        }

        return [
            'success' => false,
            'message' => 'Registrar API driver is not configured yet. Share the API spec to enable live connection tests.',
        ];
    }

    public function supportsRegistration(): bool
    {
        return true;
    }

    public function supportsTransfer(): bool
    {
        return true;
    }

    public function supportsRenewal(): bool
    {
        return true;
    }
}
