<?php

namespace App\Services\Provisioning;

use App\Services\SSH\SSHService;

class ContainerApplicationRuntimeService
{
    /**
     * @var list<string>
     */
    private const RUNTIME_TEMPLATE_SLUGS = ['nodejs', 'python', 'ruby'];

    public function supportsTemplate(?string $slug): bool
    {
        return in_array($slug, self::RUNTIME_TEMPLATE_SLUGS, true);
    }

    public function detectFromHost(
        SSHService $ssh,
        string $hostAppPath,
        string $slug,
        int $defaultPort
    ): ApplicationRuntime {
        return match ($slug) {
            'nodejs' => $this->detectNodeRuntime($ssh, $hostAppPath, $defaultPort),
            'ruby' => $this->detectRubyRuntime($ssh, $hostAppPath, $defaultPort),
            'python' => $this->detectPythonRuntime($ssh, $hostAppPath, $defaultPort),
            default => $this->fallbackRuntime($slug, $defaultPort),
        };
    }

    public function detectNodeRuntime(SSHService $ssh, string $hostAppPath, int $defaultPort): ApplicationRuntime
    {
        $procfile = $this->readProcfileWebCommand($ssh, $hostAppPath);
        $packageJson = $this->readHostFile($ssh, $hostAppPath.'/package.json');

        return $this->detectNodeFromContents(
            $procfile,
            $packageJson,
            $this->hostFileExists($ssh, $hostAppPath.'/server.js'),
            $this->hostFileExists($ssh, $hostAppPath.'/app.js'),
            $this->hostFileExists($ssh, $hostAppPath.'/index.js'),
            $defaultPort
        );
    }

    public function detectNodeFromContents(
        ?string $procfileCommand,
        ?string $packageJson,
        bool $hasServerJs,
        bool $hasAppJs,
        bool $hasIndexJs,
        int $defaultPort
    ): ApplicationRuntime {
        if ($procfileCommand !== null) {
            return $this->shellRuntime(
                $procfileCommand,
                $defaultPort,
                'procfile',
                'Procfile web process',
                $this->nodeBootstrap($packageJson)
            );
        }

        if ($packageJson !== null) {
            $data = json_decode($packageJson, true);
            if (is_array($data)) {
                if (! empty($data['scripts']['start'])) {
                    return $this->shellRuntime(
                        'npm start',
                        $defaultPort,
                        'package.json',
                        'npm start',
                        $this->nodeBootstrap($packageJson)
                    );
                }

                if (! empty($data['main']) && is_string($data['main'])) {
                    $main = trim($data['main']);
                    if ($main !== '' && ! str_contains($main, ' ')) {
                        return $this->shellRuntime(
                            'node '.$main,
                            $defaultPort,
                            'package.json',
                            'node '.$main,
                            $this->nodeBootstrap($packageJson)
                        );
                    }
                }
            }
        }

        if ($hasServerJs) {
            return $this->shellRuntime('node server.js', $defaultPort, 'entrypoint', 'node server.js', $this->nodeBootstrap($packageJson));
        }

        if ($hasAppJs) {
            return $this->shellRuntime('node app.js', $defaultPort, 'entrypoint', 'node app.js', $this->nodeBootstrap($packageJson));
        }

        if ($hasIndexJs) {
            return $this->shellRuntime('node index.js', $defaultPort, 'entrypoint', 'node index.js', $this->nodeBootstrap($packageJson));
        }

        return $this->fallbackRuntime('nodejs', $defaultPort);
    }

    public function detectRubyRuntime(SSHService $ssh, string $hostAppPath, int $defaultPort): ApplicationRuntime
    {
        $procfile = $this->readProcfileWebCommand($ssh, $hostAppPath);

        return $this->detectRubyFromContents(
            $procfile,
            $this->hostPathExists($ssh, $hostAppPath.'/bin/rails'),
            $this->hostFileExists($ssh, $hostAppPath.'/config.ru'),
            $defaultPort
        );
    }

    public function detectRubyFromContents(
        ?string $procfileCommand,
        bool $hasBinRails,
        bool $hasConfigRu,
        int $defaultPort
    ): ApplicationRuntime {
        if ($procfileCommand !== null) {
            return $this->shellRuntime(
                $procfileCommand,
                $defaultPort,
                'procfile',
                'Procfile web process',
                $this->rubyBootstrap()
            );
        }

        if ($hasBinRails) {
            return $this->shellRuntime(
                'bundle exec rails server -b 0.0.0.0 -p ${PORT:-'.$defaultPort.'}',
                $defaultPort,
                'rails',
                'Rails server',
                $this->rubyBootstrap()
            );
        }

        if ($hasConfigRu) {
            return $this->shellRuntime(
                'bundle exec rackup config.ru -o 0.0.0.0 -p ${PORT:-'.$defaultPort.'}',
                $defaultPort,
                'rack',
                'Rack application',
                $this->rubyBootstrap()
            );
        }

        return $this->fallbackRuntime('ruby', $defaultPort);
    }

    public function detectPythonRuntime(SSHService $ssh, string $hostAppPath, int $defaultPort): ApplicationRuntime
    {
        $procfile = $this->readProcfileWebCommand($ssh, $hostAppPath);
        $requirements = $this->readHostFile($ssh, $hostAppPath.'/requirements.txt');
        $wsgi = $this->readHostFile($ssh, $hostAppPath.'/wsgi.py');

        return $this->detectPythonFromContents(
            $procfile,
            $requirements,
            $wsgi,
            $this->hostFileExists($ssh, $hostAppPath.'/manage.py'),
            $this->hostFileExists($ssh, $hostAppPath.'/main.py'),
            $this->hostFileExists($ssh, $hostAppPath.'/app.py'),
            $defaultPort
        );
    }

    public function detectPythonFromContents(
        ?string $procfileCommand,
        ?string $requirements,
        ?string $wsgiContents,
        bool $hasManagePy,
        bool $hasMainPy,
        bool $hasAppPy,
        int $defaultPort
    ): ApplicationRuntime {
        if ($procfileCommand !== null) {
            return $this->shellRuntime(
                $procfileCommand,
                $defaultPort,
                'procfile',
                'Procfile web process',
                $this->pythonBootstrap()
            );
        }

        if ($hasManagePy) {
            return $this->shellRuntime(
                'python manage.py runserver 0.0.0.0:${PORT:-'.$defaultPort.'}',
                $defaultPort,
                'django',
                'Django development server',
                $this->pythonBootstrap()
            );
        }

        if ($requirements !== null && stripos($requirements, 'gunicorn') !== false && $wsgiContents !== null) {
            $module = $this->resolvePythonWsgiModule($wsgiContents);
            if ($module !== null) {
                return $this->shellRuntime(
                    'gunicorn '.$module.' --bind 0.0.0.0:${PORT:-'.$defaultPort.'}',
                    $defaultPort,
                    'gunicorn',
                    'Gunicorn WSGI server',
                    $this->pythonBootstrap()
                );
            }
        }

        if ($requirements !== null && stripos($requirements, 'uvicorn') !== false && $hasMainPy) {
            return $this->shellRuntime(
                'uvicorn main:app --host 0.0.0.0 --port ${PORT:-'.$defaultPort.'}',
                $defaultPort,
                'uvicorn',
                'Uvicorn ASGI server',
                $this->pythonBootstrap()
            );
        }

        if ($hasMainPy) {
            return $this->shellRuntime('python main.py', $defaultPort, 'entrypoint', 'python main.py', $this->pythonBootstrap());
        }

        if ($hasAppPy) {
            return $this->shellRuntime('python app.py', $defaultPort, 'entrypoint', 'python app.py', $this->pythonBootstrap());
        }

        return $this->fallbackRuntime('python', $defaultPort);
    }

    public function fallbackRuntime(string $slug, int $defaultPort): ApplicationRuntime
    {
        $command = match ($slug) {
            'nodejs' => 'node -e "require(\'http\').createServer((_,res)=>{res.writeHead(200,{\'Content-Type\':\'text/plain\'});res.end(\'Talksasa: add your Node.js app to /app\');}).listen(process.env.PORT||'
                .$defaultPort.",'0.0.0.0')\"",
            'python' => 'python -m http.server ${PORT:-'.$defaultPort.'} --bind 0.0.0.0',
            'ruby' => 'ruby -run -e httpd . -p ${PORT:-'.$defaultPort.'} -b 0.0.0.0',
            default => 'sleep infinity',
        };

        return new ApplicationRuntime(
            ['sh', '-lc', $command],
            'fallback',
            'Placeholder HTTP server'
        );
    }

    public function shellRuntime(
        string $innerCommand,
        int $defaultPort,
        string $source,
        string $label,
        ?string $bootstrap = null
    ): ApplicationRuntime {
        $innerCommand = $this->sanitizeInnerCommand($innerCommand);
        $prefix = '';

        if ($bootstrap !== null && trim($bootstrap) !== '') {
            $prefix = trim($bootstrap).' && ';
        }

        return new ApplicationRuntime(
            ['sh', '-lc', 'cd /app && export PORT=${PORT:-'.$defaultPort.'} && '.$prefix.'exec '.$innerCommand],
            $source,
            $label
        );
    }

    public function sanitizeInnerCommand(string $command): string
    {
        $command = trim($command);
        if ($command === '' || strlen($command) > 500) {
            throw new \InvalidArgumentException('Application start command is invalid.');
        }

        if (preg_match('/[`<>\\\\]|\$\(/', $command)) {
            throw new \InvalidArgumentException('Application start command contains disallowed tokens.');
        }

        if (str_contains($command, ';') || str_contains($command, '|') || str_contains($command, '&')) {
            throw new \InvalidArgumentException('Application start command contains disallowed tokens.');
        }

        return $command;
    }

    private function resolvePythonWsgiModule(string $wsgiContents): ?string
    {
        if (preg_match("/DJANGO_SETTINGS_MODULE',\s*'([^']+)'/", $wsgiContents, $matches)) {
            $settingsModule = $matches[1];
            $wsgiModule = preg_replace('/\.settings$/', '.wsgi', $settingsModule);

            return $wsgiModule.':application';
        }

        if (preg_match('/^\s*app\s*=\s*/m', $wsgiContents)) {
            return 'wsgi:app';
        }

        if (preg_match('/^\s*application\s*=\s*/m', $wsgiContents)) {
            return 'wsgi:application';
        }

        return null;
    }

    private function readProcfileWebCommand(SSHService $ssh, string $hostAppPath): ?string
    {
        foreach (['Procfile', 'procfile'] as $filename) {
            $contents = $this->readHostFile($ssh, $hostAppPath.'/'.$filename);
            if ($contents === null) {
                continue;
            }

            foreach (preg_split('/\R/', $contents) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                if (preg_match('/^web:\s*(.+)$/i', $line, $matches)) {
                    $command = trim($matches[1]);

                    return $command !== '' ? $command : null;
                }
            }
        }

        return null;
    }

    private function readHostFile(SSHService $ssh, string $path, int $maxBytes = 65536): ?string
    {
        if (! $this->hostFileExists($ssh, $path)) {
            return null;
        }

        $pathArg = escapeshellarg($path);
        $maxBytesArg = escapeshellarg((string) $maxBytes);
        $output = trim($ssh->exec("head -c {$maxBytesArg} {$pathArg}", 15));

        return $output !== '' ? $output : null;
    }

    private function hostFileExists(SSHService $ssh, string $path): bool
    {
        return $this->hostPathExists($ssh, $path, 'f');
    }

    private function hostPathExists(SSHService $ssh, string $path, string $type = 'f'): bool
    {
        $flag = $type === 'd' ? '-d' : ($type === 'x' ? '-x' : '-f');
        $pathArg = escapeshellarg($path);

        return trim($ssh->exec("[ {$flag} {$pathArg} ] && echo yes || echo no", 10)) === 'yes';
    }

    public function packageJsonHasBuildScript(?string $packageJson): bool
    {
        if ($packageJson === null || trim($packageJson) === '') {
            return false;
        }

        $data = json_decode($packageJson, true);
        if (! is_array($data)) {
            return false;
        }

        $scripts = $data['scripts'] ?? [];

        return is_array($scripts) && ! empty($scripts['build']);
    }

    public function packageJsonRequiresProductionBuild(?string $packageJson): bool
    {
        if ($packageJson === null || trim($packageJson) === '') {
            return false;
        }

        $data = json_decode($packageJson, true);
        if (! is_array($data)) {
            return false;
        }

        $scripts = $data['scripts'] ?? [];
        if (! is_array($scripts) || empty($scripts['build'])) {
            return false;
        }

        $dependencies = array_merge(
            is_array($data['dependencies'] ?? null) ? $data['dependencies'] : [],
            is_array($data['devDependencies'] ?? null) ? $data['devDependencies'] : []
        );

        $buildPackages = [
            'next',
            'nuxt',
            '@sveltejs/kit',
            '@remix-run/react',
            '@remix-run/node',
            '@angular/core',
            '@angular/cli',
            'vite',
            'astro',
        ];

        foreach ($buildPackages as $package) {
            if (isset($dependencies[$package])) {
                return true;
            }
        }

        $start = strtolower((string) ($scripts['start'] ?? ''));
        if (str_contains($start, 'next start')
            || str_contains($start, 'nuxt start')
            || str_contains($start, 'remix-serve')
            || str_contains($start, 'vite preview')
            || str_contains($start, 'astro preview')
            || str_contains($start, 'dist/')
            || str_contains($start, '.next')
            || str_contains($start, 'build/')) {
            return true;
        }

        return false;
    }

    public function packageJsonBuildOutputDir(?string $packageJson): string
    {
        if ($packageJson === null || trim($packageJson) === '') {
            return 'dist';
        }

        $data = json_decode($packageJson, true);
        if (! is_array($data)) {
            return 'dist';
        }

        $dependencies = array_merge(
            is_array($data['dependencies'] ?? null) ? $data['dependencies'] : [],
            is_array($data['devDependencies'] ?? null) ? $data['devDependencies'] : []
        );

        if (isset($dependencies['next'])) {
            return '.next';
        }

        if (isset($dependencies['nuxt'])) {
            return '.output';
        }

        if (isset($dependencies['@angular/core']) || isset($dependencies['@angular/cli'])) {
            return 'dist';
        }

        return 'dist';
    }

    /**
     * Shell test that is true when a production build artifact is missing or incomplete.
     */
    public function packageJsonBuildArtifactMissingCheck(?string $packageJson): string
    {
        if ($packageJson === null || trim($packageJson) === '') {
            return '[ ! -d dist ]';
        }

        $data = json_decode($packageJson, true);
        if (! is_array($data)) {
            return '[ ! -d dist ]';
        }

        $dependencies = array_merge(
            is_array($data['dependencies'] ?? null) ? $data['dependencies'] : [],
            is_array($data['devDependencies'] ?? null) ? $data['devDependencies'] : []
        );

        if (isset($dependencies['next'])) {
            return '[ ! -f .next/BUILD_ID ]';
        }

        if (isset($dependencies['nuxt'])) {
            return '[ ! -d .output/server ]';
        }

        $artifactDir = $this->packageJsonBuildOutputDir($packageJson);

        return '[ ! -d '.$artifactDir.' ]';
    }

    private const NODE_NPM_BIN = '/usr/local/bin/npm';

    private const NODE_CLEAN_ENV = 'HOME=/tmp NPM_CONFIG_CACHE=/tmp/.npm PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin npm_config_production=false NPM_CONFIG_PRODUCTION=false npm_config_omit=';

    public function nodeCleanNpmCommand(string $npmArgs, ?string $nodeEnv = null, array $extraEnv = []): string
    {
        $npmArgs = trim($npmArgs);
        $env = 'env -i '.self::NODE_CLEAN_ENV;
        foreach ($extraEnv as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value === '' || preg_match('/\s/', $value)) {
                continue;
            }

            $env .= ' '.$key.'='.$value;
        }
        if ($nodeEnv !== null && $nodeEnv !== '') {
            $env .= ' NODE_ENV='.$nodeEnv;
        }

        return $env.' '.self::NODE_NPM_BIN.' '.$npmArgs;
    }

    public function nodeBuildHeapLimitMb(?int $containerMemoryLimitMb = null): int
    {
        $ratio = 0.65;
        $minimum = 384;

        if (function_exists('config')) {
            try {
                $ratio = (float) config('containers.node_build.heap_limit_ratio', 0.65);
                $minimum = (int) config('containers.node_build.min_heap_limit_mb', 384);
            } catch (\Throwable) {
            }
        }

        if ($containerMemoryLimitMb === null || $containerMemoryLimitMb <= 0) {
            return max($minimum, 768);
        }

        $heap = (int) floor($containerMemoryLimitMb * $ratio);

        return max($minimum, min($heap, $containerMemoryLimitMb - 128));
    }

    public function nodeNpmProductionOffPrefix(): string
    {
        return 'npm_config_production=false NPM_CONFIG_PRODUCTION=false';
    }

    public function npmInstallForProductionBuildCommand(bool $force = false): string
    {
        $forceFlag = $force ? ' --force' : '';

        return 'install --production=false --include=dev --no-audit --no-fund'.$forceFlag;
    }

    public function npmInstallShellCommand(bool $force = false): string
    {
        return $this->nodeCleanNpmCommand($this->npmInstallForProductionBuildCommand($force), 'development');
    }

    public function npmCiShellCommand(bool $force = false): string
    {
        $forceFlag = $force ? ' --force' : '';

        return $this->nodeCleanNpmCommand(
            'ci --include=dev --no-audit --no-fund'.$forceFlag,
            'development'
        );
    }

    public function npmCacheCleanShellCommand(): string
    {
        return $this->nodeCleanNpmCommand('cache clean --force');
    }

    public function npmInstallDevPackagesShellCommand(?string $packageJson): string
    {
        if ($packageJson === null || trim($packageJson) === '') {
            return $this->npmInstallShellCommand();
        }

        $data = json_decode($packageJson, true);
        if (! is_array($data)) {
            return $this->npmInstallShellCommand();
        }

        $devDependencies = $data['devDependencies'] ?? [];
        if (! is_array($devDependencies) || $devDependencies === []) {
            return $this->npmInstallShellCommand();
        }

        $names = array_values(array_filter(
            array_keys($devDependencies),
            fn (string $name): bool => (bool) preg_match('/^[@a-zA-Z0-9._\/-]+$/', $name)
        ));

        if ($names === []) {
            return $this->npmInstallShellCommand();
        }

        $list = implode(' ', $names);
        if (strlen($list) > 300) {
            return $this->npmInstallShellCommand();
        }

        return $this->nodeCleanNpmCommand('install --production=false --save-dev --no-audit --no-fund '.$list, 'development');
    }

    public function npmBuildShellCommand(?int $containerMemoryLimitMb = null, bool $withoutContainerMemoryLimit = false): string
    {
        $extra = [];

        if ($withoutContainerMemoryLimit) {
            $heapLimit = 4096;

            if (function_exists('config')) {
                try {
                    $heapLimit = (int) config('containers.node_build.unlimited_heap_limit_mb', 4096);
                } catch (\Throwable) {
                }
            }

            if ($heapLimit > 0) {
                $extra['NODE_OPTIONS'] = '--max-old-space-size='.$heapLimit;
            }
        } else {
            $extra['NODE_OPTIONS'] = '--max-old-space-size='.$this->nodeBuildHeapLimitMb($containerMemoryLimitMb);
        }

        return $this->nodeCleanNpmCommand('run build', 'production', $extra);
    }

    public function nodeBuildPrepareCommand(): string
    {
        return 'node .talksasa/prepare-build.cjs';
    }

    public function nodeBuildPreparePrefix(): string
    {
        if (! $this->nodeBuildPrepareEnabled()) {
            return '';
        }

        return 'node .talksasa/prepare-build.cjs && ';
    }

    public function nodeBuildPrepareEnabled(): bool
    {
        if (! function_exists('config')) {
            return true;
        }

        try {
            return (bool) config('containers.node_build.prepare_before_build', true);
        } catch (\Throwable) {
            return true;
        }
    }

    public function npmPruneShellCommand(): string
    {
        return $this->nodeCleanNpmCommand('prune --omit=dev', 'production');
    }

    /**
     * @return array<string, string>
     */
    public function nodeBuildEnvironmentOverrides(): array
    {
        return [
            'NPM_CONFIG_PRODUCTION' => 'false',
            'npm_config_production' => 'false',
        ];
    }

    /**
     * Relative path under /app used to verify a framework install is complete.
     */
    public function nodeIntegrityMarkerRelativePath(?string $packageJson): ?string
    {
        if ($packageJson === null || trim($packageJson) === '') {
            return null;
        }

        $data = json_decode($packageJson, true);
        if (! is_array($data)) {
            return null;
        }

        $dependencies = array_merge(
            is_array($data['dependencies'] ?? null) ? $data['dependencies'] : [],
            is_array($data['devDependencies'] ?? null) ? $data['devDependencies'] : [],
        );

        if (isset($dependencies['next'])) {
            return 'node_modules/next/dist/compiled/browserslist/index.js';
        }

        if (isset($dependencies['nuxt'])) {
            return 'node_modules/nuxt/package.json';
        }

        return null;
    }

    /**
     * Build output directories to remove before a clean Node install.
     *
     * @return list<string>
     */
    public function nodeBuildArtifactDirs(?string $packageJson): array
    {
        $dirs = ['.next'];

        if ($packageJson === null || trim($packageJson) === '') {
            return $dirs;
        }

        $data = json_decode($packageJson, true);
        if (! is_array($data)) {
            return $dirs;
        }

        $dependencies = array_merge(
            is_array($data['dependencies'] ?? null) ? $data['dependencies'] : [],
            is_array($data['devDependencies'] ?? null) ? $data['devDependencies'] : [],
        );

        if (isset($dependencies['nuxt'])) {
            $dirs[] = '.nuxt';
            $dirs[] = '.output';
        }

        $outputDir = $this->packageJsonBuildOutputDir($packageJson);
        if ($outputDir !== 'dist' && preg_match('/^[a-zA-Z0-9._-]+$/', $outputDir)) {
            $dirs[] = $outputDir;
        }

        return array_values(array_unique($dirs));
    }

    /** @deprecated use nodeNpmProductionOffPrefix() */
    public function nodeBuildEnvironmentPrefix(): string
    {
        return $this->nodeNpmProductionOffPrefix();
    }

    public function nodeBootstrap(?string $packageJson = null): string
    {
        $binFix = 'find node_modules/.bin -type f -exec chmod u+x {} + 2>/dev/null';
        $installForBuild = $this->npmInstallShellCommand();
        $buildCommand = $this->npmBuildShellCommand();
        $pruneCommand = $this->npmPruneShellCommand();
        $prepareStep = $this->nodeBuildPrepareEnabled()
            ? '[ -f .talksasa/prepare-build.cjs ] && node .talksasa/prepare-build.cjs && '
            : '';

        if (! $this->packageJsonRequiresProductionBuild($packageJson)) {
            return '[ -f package.json ] && npm install --omit=dev && '.$binFix;
        }

        $artifactMissingCheck = $this->packageJsonBuildArtifactMissingCheck($packageJson);

        return '[ -f package.json ] && { if '.$artifactMissingCheck.'; then rm -rf node_modules && '.$installForBuild.' && '.$binFix.' && '.$prepareStep.$buildCommand.' && '.$pruneCommand.' && '.$binFix.'; else npm install --omit=dev && '.$binFix.'; fi; }';
    }

    private function rubyBootstrap(): string
    {
        return '[ -f Gemfile ] && bundle install --without development test';
    }

    private function pythonBootstrap(): string
    {
        return '[ -f requirements.txt ] && pip install --no-cache-dir -r requirements.txt';
    }
}
