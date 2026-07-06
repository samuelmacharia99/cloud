<?php

namespace Tests\Unit\Provisioning;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContainerNodeBuildPrepScriptTest extends TestCase
{
    #[Test]
    public function it_patches_tsconfig_and_wraps_next_config_for_hosted_builds(): void
    {
        $temp = sys_get_temp_dir().'/talksasa-node-prep-'.uniqid();
        mkdir($temp, 0777, true);

        file_put_contents($temp.'/package.json', json_encode([
            'name' => 'new-talksasa',
            'scripts' => ['build' => 'next build'],
            'dependencies' => ['next' => '14.2.35'],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($temp.'/tsconfig.json', json_encode([
            'compilerOptions' => [
                'target' => 'es5',
                'lib' => ['dom', 'dom.iterable', 'es6'],
            ],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($temp.'/next.config.mjs', "export default { reactStrictMode: true };\n");

        $script = realpath(__DIR__.'/../../../resources/container-templates/nodejs/prepare-build.cjs');
        $this->assertNotFalse($script);

        $output = [];
        $exitCode = 0;
        exec('cd '.escapeshellarg($temp).' && node '.escapeshellarg($script).' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));

        $tsconfig = json_decode(file_get_contents($temp.'/tsconfig.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains('es2022', $tsconfig['compilerOptions']['lib']);

        $this->assertFileExists($temp.'/next.config.js');
        $this->assertFileExists($temp.'/next.config.user.talksasa.mjs');
        $this->assertStringContainsString('ignoreBuildErrors', file_get_contents($temp.'/next.config.js'));
    }
}
