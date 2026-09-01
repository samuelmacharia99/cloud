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
            ->where('slug', 'ollama')
            ->whereDoesntHave('products')
            ->delete();
    }
};
