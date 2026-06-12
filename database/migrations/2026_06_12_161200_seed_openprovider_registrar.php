<?php

use App\Enums\RegistrarDriver;
use App\Models\DomainExtension;
use App\Models\Registrar;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('registrars')) {
            return;
        }

        $registrar = Registrar::query()->firstOrCreate(
            ['slug' => 'openprovider'],
            [
                'name' => 'Openprovider',
                'driver' => RegistrarDriver::Openprovider,
                'environment' => 'production',
                'is_active' => true,
                'is_default' => true,
                'description' => 'Wholesale domain registrar — all TLDs except Kenya (.ke) zones.',
                'config' => [
                    'login_ip' => '0.0.0.0',
                ],
                'sort_order' => 0,
            ],
        );

        Registrar::query()
            ->where('id', '!=', $registrar->id)
            ->update(['is_default' => false]);

        if (! Schema::hasTable('domain_extensions') || ! Schema::hasColumn('domain_extensions', 'registrar_id')) {
            return;
        }

        DomainExtension::query()
            ->where('extension', 'not like', '%.ke')
            ->update([
                'registrar_id' => $registrar->id,
                'registrar' => $registrar->slug,
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('registrars')) {
            return;
        }

        $registrar = Registrar::query()->where('slug', 'openprovider')->first();

        if ($registrar && Schema::hasTable('domain_extensions')) {
            DomainExtension::query()
                ->where('registrar_id', $registrar->id)
                ->update(['registrar_id' => null]);
        }

        Registrar::query()->where('slug', 'openprovider')->delete();
    }
};
