<?php

use App\Models\ResellerProduct;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_products', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
        });

        ResellerProduct::query()
            ->orderBy('id')
            ->each(function (ResellerProduct $product): void {
                $product->forceFill([
                    'slug' => ResellerProduct::uniqueSlugForReseller(
                        (int) $product->reseller_id,
                        (string) $product->name,
                        (int) $product->id,
                    ),
                ])->saveQuietly();
            });

        Schema::table('reseller_products', function (Blueprint $table) {
            $table->unique(['reseller_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('reseller_products', function (Blueprint $table) {
            $table->dropUnique(['reseller_id', 'slug']);
            $table->dropColumn('slug');
        });
    }
};
