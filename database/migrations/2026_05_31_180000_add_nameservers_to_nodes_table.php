<?php

use App\Models\Node;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('nameserver_1')->nullable()->after('description');
            $table->string('nameserver_2')->nullable()->after('nameserver_1');
            $table->string('nameserver_3')->nullable()->after('nameserver_2');
            $table->string('nameserver_4')->nullable()->after('nameserver_3');
        });

        if (! Schema::hasTable('settings')) {
            return;
        }

        $legacy = [
            'nameserver_1' => Setting::getValue('domain_ns1', 'ns1.talksasa.cloud'),
            'nameserver_2' => Setting::getValue('domain_ns2', 'ns2.talksasa.cloud'),
            'nameserver_3' => Setting::getValue('domain_ns3') ?: null,
            'nameserver_4' => Setting::getValue('domain_ns4') ?: null,
        ];

        Node::where('type', 'directadmin')
            ->whereNull('nameserver_1')
            ->update($legacy);
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['nameserver_1', 'nameserver_2', 'nameserver_3', 'nameserver_4']);
        });
    }
};
