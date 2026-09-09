<?php

namespace Tests\Unit\Provisioning;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContainerNodeRelativeFetchScriptTest extends TestCase
{
    #[Test]
    public function it_rewrites_relative_fetch_urls_against_the_internal_api(): void
    {
        $script = realpath(__DIR__.'/../../../resources/container-templates/nodejs/resolve-relative-fetch.cjs');
        $this->assertNotFalse($script);

        $harness = sys_get_temp_dir().'/talksasa-fetch-harness-'.uniqid().'.cjs';
        file_put_contents($harness, <<<'JS'
'use strict';
const calls = [];
globalThis.fetch = async (input) => {
  calls.push(typeof input === 'string' ? input : String(input?.url ?? input));
  return { ok: true };
};
process.env.TALKSASA_FETCH_BASE = 'http://backend:8000';
require(process.argv[2]);
fetch('/api/restaurants/public').then(() => {
  if (calls[0] !== 'http://backend:8000/api/restaurants/public') {
    console.error(JSON.stringify(calls));
    process.exit(1);
  }
});
JS);

        $output = [];
        $exitCode = 0;
        exec(
            'node '.escapeshellarg($harness).' '.escapeshellarg($script).' 2>&1',
            $output,
            $exitCode
        );
        @unlink($harness);

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }
}
