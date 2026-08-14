<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->text('api_token')->nullable()->change();
        });

        DB::table('nodes')
            ->whereNotNull('api_token')
            ->where('api_token', '!=', '')
            ->orderBy('id')
            ->each(function (object $node): void {
                try {
                    Crypt::decryptString((string) $node->api_token);
                } catch (Throwable) {
                    DB::table('nodes')
                        ->where('id', $node->id)
                        ->update(['api_token' => Crypt::encryptString((string) $node->api_token)]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally retain encryption on rollback.
    }
};
