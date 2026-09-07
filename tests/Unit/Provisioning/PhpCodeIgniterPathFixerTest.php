<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\PhpCodeIgniterPathFixer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PhpCodeIgniterPathFixerTest extends TestCase
{
    #[Test]
    public function it_rewrites_the_public_front_controller_when_app_is_nested_under_docroot(): void
    {
        $fixer = new PhpCodeIgniterPathFixer;
        $source = <<<'PHP'
<?php
define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);
require FCPATH . '../app/Config/Paths.php';
$paths = new Config\Paths();
PHP;

        $this->assertTrue($fixer->needsFlattenedPathsRequire($source, true, false));
        $this->assertFalse($fixer->needsFlattenedPathsRequire($source, true, true));
        $this->assertFalse($fixer->needsFlattenedPathsRequire($source, false, false));

        $rewritten = $fixer->rewriteFrontController($source);
        $this->assertStringContainsString("require __DIR__ . '/app/Config/Paths.php'", $rewritten);
        $this->assertStringNotContainsString('../app/Config/Paths.php', $rewritten);
    }

    #[Test]
    public function it_points_the_require_at_a_discovered_paths_file(): void
    {
        $fixer = new PhpCodeIgniterPathFixer;
        $source = "require __DIR__ . '/../app/Config/Paths.php';\n";

        $this->assertSame(
            '/app/core/app/Config/Paths.php',
            $fixer->preferPathsCandidate([
                '/app/writable/Config/Paths.php',
                '/app/core/app/Config/Paths.php',
            ])
        );
        $this->assertSame(
            'core/app/Config/Paths.php',
            $fixer->relativeFromAppRoot('/app/core/app/Config/Paths.php')
        );

        $rewritten = $fixer->rewriteFrontController($source, 'core/app/Config/Paths.php');
        $this->assertStringContainsString("require __DIR__ . '/core/app/Config/Paths.php'", $rewritten);
    }

    #[Test]
    public function it_does_not_rewrite_when_the_stock_relative_path_already_exists(): void
    {
        $fixer = new PhpCodeIgniterPathFixer;
        $source = "require FCPATH . '../app/Config/Paths.php';\n";

        $this->assertFalse($fixer->needsFlattenedPathsRequire($source, true, true));
        $this->assertFalse($fixer->looksLikeCodeIgniterFrontController('<?php echo "hello";'));
    }
}
