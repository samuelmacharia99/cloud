<?php

use App\Models\ContainerTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('container_templates')
            ->where('slug', 'nodejs')
            ->update([
                'versions' => json_encode(ContainerTemplate::nodeRuntimeVersions(), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        $versions = array_values(array_filter(
            ContainerTemplate::nodeRuntimeVersions(),
            fn (string $version): bool => ! str_starts_with($version, '24'),
        ));

        DB::table('container_templates')
            ->where('slug', 'nodejs')
            ->update([
                'versions' => json_encode($versions, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }
};
