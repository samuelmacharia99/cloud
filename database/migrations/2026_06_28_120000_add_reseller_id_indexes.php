<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! $this->hasIndex('users', 'users_reseller_id_index')) {
                $table->index('reseller_id');
            }
        });

        Schema::table('domains', function (Blueprint $table) {
            if (! $this->hasIndex('domains', 'domains_reseller_id_index')) {
                $table->index('reseller_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if ($this->hasIndex('users', 'users_reseller_id_index')) {
                $table->dropIndex(['reseller_id']);
            }
        });

        Schema::table('domains', function (Blueprint $table) {
            if ($this->hasIndex('domains', 'domains_reseller_id_index')) {
                $table->dropIndex(['reseller_id']);
            }
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = Schema::getIndexes($table);

        foreach ($indexes as $index) {
            if (($index['name'] ?? '') === $indexName) {
                return true;
            }
        }

        return false;
    }
};
