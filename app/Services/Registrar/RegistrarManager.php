<?php

namespace App\Services\Registrar;

use App\Enums\RegistrarDriver;
use App\Models\DomainExtension;
use App\Models\Registrar;
use App\Services\Registrar\Drivers\CustomRegistrarDriver;
use App\Services\Registrar\Drivers\ManualRegistrarDriver;
use App\Services\Registrar\Drivers\OpenproviderRegistrarDriver;

class RegistrarManager
{
    public function driver(Registrar $registrar): RegistrarDriverInterface
    {
        return match ($registrar->driver) {
            RegistrarDriver::Manual => new ManualRegistrarDriver,
            RegistrarDriver::Openprovider => new OpenproviderRegistrarDriver,
            RegistrarDriver::Custom => new CustomRegistrarDriver,
        };
    }

    public function default(): ?Registrar
    {
        return Registrar::query()
            ->active()
            ->where('is_default', true)
            ->first()
            ?? Registrar::query()->active()->ordered()->first();
    }

    public function forExtension(DomainExtension $extension): ?Registrar
    {
        if ($extension->registrar_id) {
            return Registrar::query()->active()->find($extension->registrar_id);
        }

        return $this->default();
    }

    public function testConnection(Registrar $registrar): array
    {
        return $this->driver($registrar)->testConnection($registrar);
    }
}
