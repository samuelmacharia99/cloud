<?php

namespace Tests\Unit\Services;

use App\Services\Provisioning\DockerStatsParser;
use PHPUnit\Framework\TestCase;

class DockerStatsParserTest extends TestCase
{
    public function test_parses_tab_separated_docker_stats_line(): void
    {
        $parsed = DockerStatsParser::parseLine(
            "12.34%\t256MiB / 512MiB\t1.2MB / 3.4MB\t0B / 0B",
            'talksasa-67-hqpbma'
        );

        $this->assertSame('12.34%', $parsed['cpu']);
        $this->assertSame('256MiB / 512MiB', $parsed['mem']);
    }

    public function test_parses_legacy_json_line(): void
    {
        $parsed = DockerStatsParser::parseLine(
            '{"cpu":"5.00%","mem":"128MiB / 256MiB","net":"0B / 0B","block":"0B / 0B"}',
            'app'
        );

        $this->assertSame('5.00%', $parsed['cpu']);
    }

    public function test_throws_on_empty_stats(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DockerStatsParser::parseLine('{}', 'missing-container');
    }

    public function test_memory_and_data_helpers(): void
    {
        $this->assertSame(256, DockerStatsParser::parseMemoryToMb('256MiB'));
        $this->assertSame(1048576, DockerStatsParser::parseDataToBytes('1MB'));
    }
}
