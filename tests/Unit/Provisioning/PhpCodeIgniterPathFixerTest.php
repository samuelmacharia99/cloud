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
        $this->assertStringContainsString("FCPATH . 'app/Config/Paths.php'", $rewritten);
        $this->assertStringNotContainsString('../app/Config/Paths.php', $rewritten);
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
