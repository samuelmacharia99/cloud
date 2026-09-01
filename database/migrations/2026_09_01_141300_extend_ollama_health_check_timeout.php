<?php

use App\Models\ContainerTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ContainerTemplate::query()
            ->where('slug', 'ollama')
            ->update(['health_check_timeout_seconds' => 900]);
    }

    public function down(): void
    {
        ContainerTemplate::query()
            ->where('slug', 'ollama')
            ->update(['health_check_timeout_seconds' => 180]);
    }
};
