<?php

use App\Models\ContainerTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ContainerTemplate::query()
            ->where('slug', 'ollama')
            ->update([
                'is_active' => false,
                'description' => 'Local Ollama runtime (existing services only). Not offered for new deploys — CPU 8B models are too slow for Hermes. Use an LLM API key on Hermes instead.',
            ]);
    }

    public function down(): void
    {
        ContainerTemplate::query()
            ->where('slug', 'ollama')
            ->update(['is_active' => true]);
    }
};
