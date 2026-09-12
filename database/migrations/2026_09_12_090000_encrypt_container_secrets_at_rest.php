<?php

use App\Services\Provisioning\ContainerSecretsAtRestBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A json column will not accept ciphertext; the encrypted:array cast
        // needs plain text storage, as nodes.api_token did.
        Schema::table('container_deployments', function (Blueprint $table) {
            $table->longText('env_values')->nullable()->change();
        });

        app(ContainerSecretsAtRestBackfill::class)->run();
    }

    public function down(): void
    {
        // Intentionally retain encryption on rollback.
    }
};
