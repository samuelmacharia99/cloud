<?php

namespace Tests\Unit\Services\Registrar;

use App\Services\Registrar\Openprovider\OpenproviderClient;
use PHPUnit\Framework\TestCase;

class OpenproviderClientNameServerRecordsTest extends TestCase
{
    public function test_name_server_records_deduplicate_values(): void
    {
        $records = OpenproviderClient::nameServerRecords([
            'ns1' => 'ns1.example.com',
            'ns2' => 'ns1.example.com',
            'ns3' => 'ns2.example.com',
        ]);

        $this->assertSame([
            ['name' => 'ns1.example.com'],
            ['name' => 'ns2.example.com'],
        ], $records);
    }

    public function test_name_server_records_ignore_empty_slots(): void
    {
        $records = OpenproviderClient::nameServerRecords([
            'ns1' => 'ns1.example.com',
            'ns2' => '',
            'ns3' => 'ns2.example.com',
        ]);

        $this->assertCount(2, $records);
    }
}
