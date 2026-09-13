<?php

use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Database\Migrations\Migration;

/**
 * Ollama left the catalog. CPU-only nodes could not run a usable model and
 * the stack has been hidden from new deploys since 2026-09-01; the code that
 * drove it is gone with this release, so the row goes too.
 *
 * Refuses to run while any live service still resolves to the template: a
 * running stack must be terminated deliberately, never orphaned by a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $template = ContainerTemplate::query()->where('slug', 'ollama')->first();
        if (! $template) {
            return;
        }

        $live = Service::query()
            ->whereNotIn('status', ['terminated', 'cancelled'])
            ->where(function ($query) use ($template) {
                $query->whereHas('product', fn ($product) => $product->where('container_template_id', $template->id))
                    ->orWhere('service_meta->container_template_id', $template->id)
                    ->orWhere('service_meta->language_slug', 'ollama')
                    ->orWhere('service_meta->provision_template_slug', 'ollama');
            })
            ->count();

        if ($live > 0) {
            throw new RuntimeException(
                "Cannot remove the Ollama container template: {$live} live service(s) still resolve to it. Terminate them first."
            );
        }

        Product::query()
            ->where('container_template_id', $template->id)
            ->update(['container_template_id' => null]);

        $template->delete();
    }

    public function down(): void
    {
        // The seeder definition was removed together with the code; there is
        // nothing to restore the row from.
    }
};
