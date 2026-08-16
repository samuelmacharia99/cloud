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

    public function test_parses_named_batch_lines(): void
    {
        $parsed = DockerStatsParser::parseNamedLines(
            "app-one\t1.00%\t64MiB / 256MiB\t1KiB / 2KiB\t3MiB / 4MiB\n"
            ."app-two\t2.50%\t128MiB / 512MiB\t5MB / 6MB\t7GB / 8GB"
        );

        $this->assertSame('1.00%', $parsed['app-one']['cpu']);
        $this->assertSame('2.50%', $parsed['app-two']['cpu']);
        $this->assertSame(1024, DockerStatsParser::parseDataToBytes('1KiB'));
        $this->assertSame(3 * 1024 * 1024, DockerStatsParser::parseDataToBytes('3MiB'));
        $this->assertSame(7 * 1000 * 1000 * 1000, DockerStatsParser::parseDataToBytes('7GB'));
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
        $this->assertSame(1024, DockerStatsParser::parseMemoryToMb('1GiB'));
        $this->assertSame(512, DockerStatsParser::parseMemoryToMb('0.5GB'));
        $this->assertSame(1000 * 1000, DockerStatsParser::parseDataToBytes('1MB'));
        $this->assertSame(1500.0, DockerStatsParser::clampCpuPercentage(1500));
    }
}
