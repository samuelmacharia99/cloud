<?php

namespace Tests\Unit\Support;

use App\Support\ContainerConsoleTabs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContainerConsoleTabsTest extends TestCase
{
    #[Test]
    public function a_plain_application_gets_the_base_sections_in_order(): void
    {
        $this->assertSame(ContainerConsoleTabs::BASE, ContainerConsoleTabs::resolve());
    }

    #[Test]
    public function optional_sections_sit_next_to_the_tabs_they_belong_with(): void
    {
        $tabs = ContainerConsoleTabs::resolve(
            supportsGitRepository: true,
            supportsPhpExtensions: true,
            supportsPhpVersion: true,
        );

        $this->assertSame(
            ['terminal', 'github'],
            array_slice($tabs, array_search('terminal', $tabs, true), 2),
            'Git belongs right after the terminal.'
        );
        $this->assertSame(
            ['php-version', 'php-extensions', 'documentation'],
            array_slice($tabs, -3),
            'The runtime version comes before its extensions, both ahead of the docs tab.'
        );
        $this->assertSame(count(ContainerConsoleTabs::BASE) + 3, count($tabs));
    }

    #[Test]
    public function git_lands_after_the_terminal(): void
    {
        $tabs = ContainerConsoleTabs::resolve(supportsGitRepository: true);

        $this->assertSame(
            ['terminal', 'github'],
            array_slice($tabs, array_search('terminal', $tabs, true), 2)
        );
    }

    #[Test]
    public function a_deep_link_is_only_honoured_for_a_tab_that_exists(): void
    {
        $tabs = ContainerConsoleTabs::resolve();

        $this->assertSame('logs', ContainerConsoleTabs::initial($tabs, 'logs'));
        $this->assertSame('overview', ContainerConsoleTabs::initial($tabs, 'github'));
        $this->assertSame('overview', ContainerConsoleTabs::initial($tabs, null));
        $this->assertSame('overview', ContainerConsoleTabs::initial($tabs, ['logs']));
    }
}
