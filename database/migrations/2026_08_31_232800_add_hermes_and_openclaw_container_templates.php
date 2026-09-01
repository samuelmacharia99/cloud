<?php

use App\Models\ContainerTemplate;
use Database\Seeders\ContainerTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new ContainerTemplateSeeder)->seedAgentStacks();
    }

    public function down(): void
    {
        ContainerTemplate::query()
            ->whereIn('slug', ['hermes', 'openclaw'])
            ->whereDoesntHave('products')
            ->delete();
    }
};
