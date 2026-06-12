<?php

namespace App\Services\Registrar\Drivers;

use App\Models\Registrar;
use App\Services\Registrar\RegistrarDriverInterface;

class ManualRegistrarDriver implements RegistrarDriverInterface
{
    public function testConnection(Registrar $registrar): array
    {
        return [
            'success' => true,
            'message' => 'Manual registrar — no API connection required.',
        ];
    }

    public function supportsRegistration(): bool
    {
        return false;
    }

    public function supportsTransfer(): bool
    {
        return false;
    }

    public function supportsRenewal(): bool
    {
        return false;
    }
}
