<?php

use App\Models\ContainerTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ContainerTemplate::query()->where('slug', 'nodejs')->update([
            'setup_commands' => ['npm install --omit=dev'],
        ]);

        ContainerTemplate::query()->where('slug', 'ruby')->update([
            'setup_commands' => ['bundle install --without development test'],
        ]);
    }

    public function down(): void
    {
        ContainerTemplate::query()->where('slug', 'nodejs')->update([
            'setup_commands' => ['npm install', 'npm start'],
        ]);

        ContainerTemplate::query()->where('slug', 'ruby')->update([
            'setup_commands' => [],
        ]);
    }
};
