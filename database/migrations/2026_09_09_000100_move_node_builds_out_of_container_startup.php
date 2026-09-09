<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $template = DB::table('container_templates')->where('slug', 'nodejs')->first();
        if (! $template) {
            return;
        }

        $variables = json_decode((string) $template->environment_variables, true) ?: [];
        $variables = array_values(array_filter(
            $variables,
            fn (mixed $variable): bool => ! is_array($variable)
                || ! in_array($variable['key'] ?? null, ['NPM_CONFIG_PRODUCTION', 'npm_config_production'], true)
        ));

        DB::table('container_templates')->where('id', $template->id)->update([
            'environment_variables' => json_encode($variables, JSON_THROW_ON_ERROR),
            'setup_commands' => json_encode([], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $template = DB::table('container_templates')->where('slug', 'nodejs')->first();
        if (! $template) {
            return;
        }

        $variables = json_decode((string) $template->environment_variables, true) ?: [];
        $variables[] = [
            'key' => 'npm_config_production',
            'label' => 'Production Dependencies',
            'default' => 'false',
            'required' => false,
            'secret' => false,
        ];

        DB::table('container_templates')->where('id', $template->id)->update([
            'environment_variables' => json_encode($variables, JSON_THROW_ON_ERROR),
            'setup_commands' => json_encode(['npm install --omit=dev'], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }
};
