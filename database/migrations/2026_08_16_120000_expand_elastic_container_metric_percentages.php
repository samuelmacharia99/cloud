<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('container_metrics', function (Blueprint $table) {
            // Elastic services and multi-container stacks can legitimately exceed 999%.
            $table->decimal('cpu_percentage', 8, 2)->default(0)->change();
            $table->decimal('memory_percentage', 8, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('container_metrics', function (Blueprint $table) {
            $table->decimal('cpu_percentage', 5, 2)->default(0)->change();
            $table->decimal('memory_percentage', 5, 2)->default(0)->change();
        });
    }
};
