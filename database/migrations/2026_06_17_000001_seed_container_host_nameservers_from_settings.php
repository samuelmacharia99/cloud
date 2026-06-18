<?php

use App\Models\Node;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nodes') || ! Schema::hasTable('settings')) {
            return;
        }

        $legacy = [
            'nameserver_1' => Setting::getValue('domain_ns1', 'ns1.talksasa.cloud'),
            'nameserver_2' => Setting::getValue('domain_ns2', 'ns2.talksasa.cloud'),
            'nameserver_3' => Setting::getValue('domain_ns3') ?: null,
            'nameserver_4' => Setting::getValue('domain_ns4') ?: null,
        ];

        Node::where('type', 'container_host')
            ->whereNull('nameserver_1')
            ->update($legacy);
    }

    public function down(): void
    {
        // Non-destructive: leave nameserver values in place.
    }
};
