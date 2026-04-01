<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dns_zone_id')->constrained('dns_zones')->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SOA']);
            $table->text('content');
            $table->integer('priority')->nullable();
            $table->integer('ttl')->default(3600);
            $table->timestamps();

            $table->index('dns_zone_id');
            $table->index(['dns_zone_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_records');
    }
};
