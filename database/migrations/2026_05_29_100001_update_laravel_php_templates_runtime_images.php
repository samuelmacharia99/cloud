<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $registry = trim((string) config('containers.runtime_registry', 'talksasa'), '/');

        $updates = [
            'laravel' => "{$registry}/laravel-runtime:8.3",
            'php' => "{$registry}/php-runtime:8.3",
        ];

        foreach ($updates as $slug => $image) {
            DB::table('container_templates')
                ->where('slug', $slug)
                ->update(['docker_image' => $image]);
        }
    }

    public function down(): void
    {
        DB::table('container_templates')->where('slug', 'laravel')->update(['docker_image' => 'php:8.3-cli']);
        DB::table('container_templates')->where('slug', 'php')->update(['docker_image' => 'php:8.3-cli']);
    }
};
