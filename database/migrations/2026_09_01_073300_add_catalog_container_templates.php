<?php

use App\Models\ContainerTemplate;
use Database\Seeders\ContainerTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new ContainerTemplateSeeder)->seedCatalogStacks();
    }

    public function down(): void
    {
        ContainerTemplate::query()
            ->whereIn('slug', ['n8n', 'go', 'directus', 'chatwoot', 'odoo', 'erpnext'])
            ->whereDoesntHave('products')
            ->delete();
    }
};
