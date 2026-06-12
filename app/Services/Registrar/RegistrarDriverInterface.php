<?php

namespace App\Services\Registrar;

use App\Models\Registrar;

interface RegistrarDriverInterface
{
    public function testConnection(Registrar $registrar): array;

    public function supportsRegistration(): bool;

    public function supportsTransfer(): bool;

    public function supportsRenewal(): bool;
}
