<?php

use App\Enums\RegistrarDriver;
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

        Registrar::query()->firstOrCreate(
            ['slug' => 'cosmotown'],
            [
                'name' => 'Cosmotown',
                'driver' => RegistrarDriver::Cosmotown,
                'environment' => 'sandbox',
                'is_active' => false,
                'is_default' => false,
                'description' => 'Cosmotown Reseller API V1.2 — configure API token and whitelist this server IP. Lifecycle ops pending Cosmotown docs.',
                'config' => [],
                'sort_order' => 10,
            ],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('registrars')) {
            return;
        }

        Registrar::query()->where('slug', 'cosmotown')->delete();
    }
};
