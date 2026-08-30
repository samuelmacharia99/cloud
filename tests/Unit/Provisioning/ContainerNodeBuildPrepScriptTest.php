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

    #[Test]
    public function it_strips_hardcoded_next_start_port_from_package_json(): void
    {
        $temp = sys_get_temp_dir().'/talksasa-node-prep-port-'.uniqid();
        mkdir($temp, 0777, true);

        file_put_contents($temp.'/package.json', json_encode([
            'name' => 'betty-website',
            'scripts' => [
                'build' => 'next build',
                'start' => 'next start -p 3001',
            ],
            'dependencies' => ['next' => '14.2.35'],
        ], JSON_THROW_ON_ERROR));

        $script = realpath(__DIR__.'/../../../resources/container-templates/nodejs/prepare-build.cjs');
        $this->assertNotFalse($script);

        $output = [];
        $exitCode = 0;
        exec('cd '.escapeshellarg($temp).' && node '.escapeshellarg($script).' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));

        $pkg = json_decode(file_get_contents($temp.'/package.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('next start -H 0.0.0.0', $pkg['scripts']['start']);
        $this->assertStringNotContainsString('3001', $pkg['scripts']['start']);
    }

    #[Test]
    public function it_runs_prisma_generate_when_a_schema_is_present(): void
    {
        $temp = sys_get_temp_dir().'/talksasa-node-prep-prisma-'.uniqid();
        mkdir($temp.'/prisma', 0777, true);
        mkdir($temp.'/node_modules/prisma/build', 0777, true);

        file_put_contents($temp.'/package.json', json_encode([
            'name' => 'prisma-app',
            'dependencies' => ['next' => '14.2.35', 'prisma' => '5.22.0'],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($temp.'/prisma/schema.prisma', "generator client {\n  provider = \"prisma-client-js\"\n}\n");
        file_put_contents(
            $temp.'/node_modules/prisma/build/index.js',
            "const fs = require('fs');\n"
            ."fs.writeFileSync('prisma-generated.marker', JSON.stringify({\n"
            ."  argv: process.argv.slice(2),\n"
            ."  binaryTargets: process.env.PRISMA_CLI_BINARY_TARGETS ?? null,\n"
            ."}));\n"
        );

        $script = realpath(__DIR__.'/../../../resources/container-templates/nodejs/prepare-build.cjs');
        $this->assertNotFalse($script);

        $output = [];
        $exitCode = 0;
        exec('cd '.escapeshellarg($temp).' && node '.escapeshellarg($script).' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertFileExists($temp.'/prisma-generated.marker');
        $generated = json_decode((string) file_get_contents($temp.'/prisma-generated.marker'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['generate'], $generated['argv']);
        $this->assertNull($generated['binaryTargets']);

        $marker = json_decode((string) file_get_contents($temp.'/.talksasa/build-prepared.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('prisma/schema.prisma', $marker['prisma']['schema']);
        $this->assertSame(0, $marker['prisma']['status']);
    }
}
