<?php

use App\Models\ContainerTemplate;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ContainerTemplate::query()
            ->whereIn('slug', ['php', 'wordpress', 'laravel'])
            ->update(['hosting_type' => 'container']);

        Setting::setValue('shared_hosting_sales_enabled', '0');
    }

    public function down(): void
    {
        // Intentionally leave templates as container — shared sales stay retired.
    }
};
