<?php

namespace Tests\Unit\Services;

use App\Services\DomainInputParser;
use PHPUnit\Framework\TestCase;

class DomainInputParserTest extends TestCase
{
    private DomainInputParser $parser;

    private array $extensions = ['.com', '.co.ke', '.org'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new DomainInputParser;
    }

    public function test_parses_full_domain_from_name_field_and_auto_selects_extension(): void
    {
        $result = $this->parser->parse('ilsamedicaltravels.com', null, $this->extensions);

        $this->assertSame([
            'name' => 'ilsamedicaltravels',
            'extension' => '.com',
        ], $result);
    }

    public function test_parses_full_domain_when_extension_already_selected(): void
    {
        $result = $this->parser->parse('ilsamedicaltravels.com', '.com', $this->extensions);

        $this->assertSame([
            'name' => 'ilsamedicaltravels',
            'extension' => '.com',
        ], $result);
    }

    public function test_parses_label_with_separate_extension(): void
    {
        $result = $this->parser->parse('example', '.org', $this->extensions);

        $this->assertSame([
            'name' => 'example',
            'extension' => '.org',
        ], $result);
    }

    public function test_prefers_longer_extension_match(): void
    {
        $result = $this->parser->parse('travel.co.ke', null, $this->extensions);

        $this->assertSame([
            'name' => 'travel',
            'extension' => '.co.ke',
        ], $result);
    }

    public function test_returns_null_for_invalid_label(): void
    {
        $this->assertNull($this->parser->parse('-invalid.com', null, $this->extensions));
    }
}
