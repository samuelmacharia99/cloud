<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('reseller_product_id')
                ->nullable()
                ->after('product_id')
                ->constrained('reseller_products')
                ->nullOnDelete();
        });

        $validListingIds = DB::table('reseller_products')->pluck('id')->flip();

        DB::table('services')
            ->whereNotNull('service_meta')
            ->select(['id', 'service_meta'])
            ->orderBy('id')
            ->chunkById(500, function ($services) use ($validListingIds): void {
                foreach ($services as $service) {
                    $meta = is_string($service->service_meta)
                        ? json_decode($service->service_meta, true)
                        : $service->service_meta;
                    $listingId = (int) (is_array($meta) ? ($meta['reseller_product_id'] ?? 0) : 0);

                    if ($listingId > 0 && $validListingIds->has($listingId)) {
                        DB::table('services')
                            ->where('id', $service->id)
                            ->update(['reseller_product_id' => $listingId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reseller_product_id');
        });
    }
};
