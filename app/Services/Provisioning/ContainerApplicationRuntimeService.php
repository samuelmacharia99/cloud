<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
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
            $platformCommand = $this->platformNodeListenCommand($procfileCommand, $defaultPort, $packageJson);
            if ($platformCommand !== null) {
                return $this->platformNodeRuntime($platformCommand, $defaultPort, $packageJson);
            }

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
                    $start = trim((string) $data['scripts']['start']);
                    $platformCommand = $this->platformNodeListenCommand($start, $defaultPort, $packageJson);
                    if ($platformCommand !== null) {
                        return $this->platformNodeRuntime($platformCommand, $defaultPort, $packageJson);
                    }

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

    /**
     * Rewrite framework start commands that hardcode a listen port/hostname or use a
     * development middleware server that cannot survive production installs.
     *
     * Customer apps often ship:
     * - `next start -p 3001` → 502 behind our PORT mapping
     * - `tsx server.ts` that `import 'vite'` → crash after `npm install --omit=dev`
     */
    public function platformNodeListenCommand(string $command, int $defaultPort, ?string $packageJson = null): ?string
    {
        if (preg_match('/\bnext\s+start\b/i', $command)) {
            return 'npx next start -H 0.0.0.0 -p ${PORT:-'.$defaultPort.'}';
        }

        if ($this->commandLooksLikeVitePreview($command)
            || $this->shouldRewriteStartToVitePreview($command, $packageJson)) {
            // --strictPort: without it Vite silently binds the next free port when the
            // configured one is taken, which shows up as an unexplained 502.
            return 'npx vite preview --host 0.0.0.0 --port ${PORT:-'.$defaultPort.'} --strictPort';
        }

        return null;
    }

    public function packageJsonHasVite(?string $packageJson): bool
    {
        if ($packageJson === null || trim($packageJson) === '') {
            return false;
        }

        $data = json_decode($packageJson, true);
        if (! is_array($data)) {
            return false;
        }

        $dependencies = array_merge(
            is_array($data['dependencies'] ?? null) ? $data['dependencies'] : [],
            is_array($data['devDependencies'] ?? null) ? $data['devDependencies'] : []
        );

        return isset($dependencies['vite']);
    }

    /**
     * True when the start script is a Vite middleware / tsx-dev entry that imports Vite at runtime,
     * or a Vite template custom server under dist/ that still requires Vite after build.
     * Those apps must be rewritten to `vite preview` after a production build.
     */
    public function commandLooksLikeViteDevServer(string $command): bool
    {
        $normalized = strtolower(trim($command));
        if ($normalized === '') {
            return false;
        }

        if ($this->commandLooksLikeVitePreview($normalized)) {
            return false;
        }

        if (preg_match('/\bvite\b/', $normalized) && ! preg_match('/\bvite\s+build\b/', $normalized)) {
            return true;
        }

        // Common Vite template starts: tsx/ts-node hosting vite.middlewares
        if (preg_match('/\b(tsx|ts-node|ts-node-esm)\b.*\bserver(\.[jt]sx?)?\b/', $normalized)) {
            return true;
        }

        if (preg_match('/\b(tsx|ts-node)\b.*\b(index|main|app)(\.[jt]sx?)?\b/', $normalized)
            && preg_match('/\bvite\b/', $normalized)) {
            return true;
        }

        // AI Studio / react-example style: "start": "node dist/server.cjs" where the bundle
        // still require()'s vite. Production --omit=dev then crash-loops the container.
        if ($this->commandLooksLikeViteBundledCustomServer($normalized)) {
            return true;
        }

        return false;
    }

    /**
     * Vite SPA templates that ship a custom Node server built to dist/server.* often leave
     * `require('vite')` in the bundle. Prefer vite preview over running that server.
     */
    public function commandLooksLikeViteBundledCustomServer(string $command): bool
    {
        $normalized = strtolower(trim($command));

        return (bool) preg_match(
            '/\bnode\b(?:\s+[^\s]+)*\s+[\'"]?(?:\.\/)?(?:dist|build)\/server(?:\.[cm]?js)?[\'"]?(?:\s|$)/',
            $normalized
        );
    }

    public function commandLooksLikeVitePreview(string $command): bool
    {
        return (bool) preg_match('/\bvite\s+preview\b/i', $command);
    }

    public function shouldRewriteStartToVitePreview(string $command, ?string $packageJson): bool
    {
        if (! $this->packageJsonHasVite($packageJson)) {
            return false;
        }

        if (! $this->packageJsonHasBuildScript($packageJson)) {
            return false;
        }

        return $this->commandLooksLikeViteDevServer($command);
    }

    public function productionStartRequiresVite(?string $packageJson): bool
    {
        if (! $this->packageJsonHasVite($packageJson)) {
            return false;
        }

        $start = $this->packageJsonStartScript($packageJson);
        if ($start === null) {
            return false;
        }

        $platform = $this->platformNodeListenCommand($start, 3000, $packageJson);

        return is_string($platform) && str_contains($platform, 'vite preview');
    }

    public function packageJsonStartScript(?string $packageJson): ?string
    {
        if ($packageJson === null || trim($packageJson) === '') {
            return null;
        }

        $data = json_decode($packageJson, true);
        if (! is_array($data)) {
            return null;
        }

        $start = trim((string) ($data['scripts']['start'] ?? ''));

        return $start !== '' ? $start : null;
    }

    private function platformNodeRuntime(string $platformCommand, int $defaultPort, ?string $packageJson): ApplicationRuntime
    {
        $isNext = str_contains($platformCommand, 'next start');

        return $this->shellRuntime(
            $platformCommand,
            $defaultPort,
            $isNext ? 'next' : 'vite',
            $isNext ? 'Next.js server' : 'Vite production preview',
            $this->nodeBootstrap($packageJson)
        );
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

    /**
     * True when postinstall runs a frontend build tool that is normally a devDependency.
     * Running that under `npm install --omit=dev` causes crash loops (e.g. `vite: not found`).
     */
    public function packageJsonPostinstallRequiresBuildTools(?string $packageJson): bool
    {
        if ($packageJson === null || trim($packageJson) === '') {
            return false;
        }

        $data = json_decode($packageJson, true);
        if (! is_array($data)) {
            return false;
        }

        $scripts = $data['scripts'] ?? [];
        if (! is_array($scripts)) {
            return false;
        }

        $postinstall = strtolower(trim((string) ($scripts['postinstall'] ?? '')));
        if ($postinstall === '') {
            return false;
        }

        foreach (['vite', 'next', 'nuxt', 'webpack', 'astro', 'react-scripts', 'npm run build', 'yarn build', 'pnpm run build'] as $needle) {
            if (str_contains($postinstall, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function npmOmitDevInstallCommand(?string $packageJson = null): string
    {
        $command = 'npm install --omit=dev --legacy-peer-deps';
        if ($this->packageJsonPostinstallRequiresBuildTools($packageJson)) {
            $command .= ' --ignore-scripts';
        }

        return $command;
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

        // Peer conflicts (e.g. Next 16 + older @sentry/nextjs) are common; prefer install success.
        return 'install --production=false --include=dev --legacy-peer-deps --no-audit --no-fund'.$forceFlag;
    }

    public function npmInstallShellCommand(bool $force = false): string
    {
        return $this->nodeCleanNpmCommand($this->npmInstallForProductionBuildCommand($force), 'development');
    }

    /**
     * Install that keeps devDependencies, for runtimes (Vite preview) whose config file
     * imports build-time packages at boot.
     */
    public function npmDevInstallShellCommand(?string $packageJson = null): string
    {
        $args = $this->npmInstallForProductionBuildCommand();
        if ($this->packageJsonPostinstallRequiresBuildTools($packageJson)) {
            // Otherwise a `postinstall: vite build` re-builds on every container start.
            $args .= ' --ignore-scripts';
        }

        return $this->nodeCleanNpmCommand($args, 'development');
    }

    public function npmCiShellCommand(bool $force = false): string
    {
        $forceFlag = $force ? ' --force' : '';

        return $this->nodeCleanNpmCommand(
            'ci --include=dev --legacy-peer-deps --no-audit --no-fund'.$forceFlag,
            'development'
        );
    }

    public function npmInstallNextPeersShellCommand(): string
    {
        return $this->nodeCleanNpmCommand(
            'install react react-dom --production=false --legacy-peer-deps --no-audit --no-fund --no-save',
            'development'
        );
    }

    public function packageJsonUsesNext(?string $packageJson): bool
    {
        if ($packageJson === null || trim($packageJson) === '') {
            return false;
        }

        $data = json_decode($packageJson, true);
        if (! is_array($data)) {
            return false;
        }

        $dependencies = array_merge(
            is_array($data['dependencies'] ?? null) ? $data['dependencies'] : [],
            is_array($data['devDependencies'] ?? null) ? $data['devDependencies'] : [],
        );

        return isset($dependencies['next']);
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

        return $this->nodeCleanNpmCommand(
            'install --production=false --save-dev --legacy-peer-deps --no-audit --no-fund '.$list,
            'development'
        );
    }

    public function npmBuildShellCommand(
        ?int $containerMemoryLimitMb = null,
        bool $withoutContainerMemoryLimit = false,
        ?string $packageJson = null,
        array $extraEnv = [],
    ): string {
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

        foreach ($extraEnv as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }
            $extra[$key] = (string) $value;
        }

        // Prefer the Next CLI via node so bind-mounted installs do not depend on fragile
        // node_modules/.bin/next shims (common "sh: next: not found" on Alpine volumes).
        if ($this->packageJsonUsesNext($packageJson)) {
            return $this->nodeCleanCommand('node ./node_modules/next/dist/bin/next build', 'production', $extra);
        }

        return $this->nodeCleanNpmCommand('run build', 'production', $extra);
    }

    /**
     * Public / build-time env vars from the deployment (injected into env -i build commands).
     *
     * @return array<string, string>
     */
    public function collectNodeBuildEnvFromDeployment(ContainerDeployment $deployment): array
    {
        $values = is_array($deployment->env_values) ? $deployment->env_values : [];
        $allowed = [];

        foreach ($values as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }

            if (! $this->isAllowedNodeBuildEnvKey($key)) {
                continue;
            }

            $stringValue = trim((string) $value);
            if ($stringValue === '' || preg_match('/\s/', $stringValue) || strlen($stringValue) > 500) {
                continue;
            }

            if (! preg_match('/^[A-Za-z0-9._:\/@%+=,-]+$/', $stringValue)) {
                continue;
            }

            $allowed[$key] = $stringValue;
        }

        $allowed['NEXT_TELEMETRY_DISABLED'] = $allowed['NEXT_TELEMETRY_DISABLED'] ?? '1';

        return $allowed;
    }

    public function isAllowedNodeBuildEnvKey(string $key): bool
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            return false;
        }

        foreach (['NEXT_PUBLIC_', 'VITE_', 'NUXT_PUBLIC_', 'REACT_APP_', 'PUBLIC_'] as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return in_array($key, [
            'NEXT_TELEMETRY_DISABLED',
            'NUXT_TELEMETRY_DISABLED',
            'CI',
            'DATABASE_URL',
            'DIRECT_URL',
        ], true);
    }

    /**
     * Like nodeCleanNpmCommand but runs an arbitrary safe argv (not necessarily npm).
     *
     * @param  array<string, string>  $extraEnv
     */
    public function nodeCleanCommand(string $command, ?string $nodeEnv = null, array $extraEnv = []): string
    {
        $command = trim($command);
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

        return $env.' '.$command;
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
        return $this->nodeCleanNpmCommand('prune --omit=dev --legacy-peer-deps', 'production');
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
     *
     * @deprecated Prefer nodeIntegrityMarkerRelativePaths() — Next installs can
     *             leave `next` present while `react` is missing.
     */
    public function nodeIntegrityMarkerRelativePath(?string $packageJson): ?string
    {
        $markers = $this->nodeIntegrityMarkerRelativePaths($packageJson);

        return $markers[0] ?? null;
    }

    /**
     * Relative paths under /app that must all exist after npm ci/install.
     *
     * @return list<string>
     */
    public function nodeIntegrityMarkerRelativePaths(?string $packageJson): array
    {
        if ($packageJson === null || trim($packageJson) === '') {
            return [];
        }

        $data = json_decode($packageJson, true);
        if (! is_array($data)) {
            return [];
        }

        $dependencies = array_merge(
            is_array($data['dependencies'] ?? null) ? $data['dependencies'] : [],
            is_array($data['devDependencies'] ?? null) ? $data['devDependencies'] : [],
        );

        if (isset($dependencies['next'])) {
            // next can be present while peers were not extracted (common 502/build failure).
            // Also require the CLI binary — package.json alone can exist with a broken/incomplete install
            // which surfaces as `sh: next: not found` during npm run build.
            return [
                'node_modules/next/package.json',
                'node_modules/next/dist/bin/next',
                'node_modules/react/package.json',
                'node_modules/react/index.js',
                'node_modules/react-dom/package.json',
            ];
        }

        if (isset($dependencies['nuxt'])) {
            return ['node_modules/nuxt/package.json'];
        }

        if (isset($dependencies['vite'])) {
            return [
                'node_modules/vite/package.json',
                'node_modules/vite/bin/vite.js',
            ];
        }

        return [];
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
        $binFix = 'find node_modules/.bin node_modules/next/dist/bin node_modules/vite/bin -type f -exec chmod u+x {} + 2>/dev/null || true';
        $installForBuild = $this->npmInstallShellCommand();
        $buildCommand = $this->npmBuildShellCommand(null, false, $packageJson);
        $pruneCommand = $this->npmPruneShellCommand();
        $prepareStep = $this->nodeBuildPrepareEnabled()
            ? '[ -f .talksasa/prepare-build.cjs ] && node .talksasa/prepare-build.cjs && '
            : '';
        // `vite preview` boots through vite.config, which imports vite itself plus plugins
        // like @vitejs/plugin-react — all devDependencies. Pruning them is what crash-loops
        // these apps, so Vite runtimes keep their dev tree instead of reinstalling pieces.
        $keepDevDependencies = $this->productionStartRequiresVite($packageJson);
        $steadyStateInstall = $keepDevDependencies
            ? $this->npmDevInstallShellCommand($packageJson)
            : $this->npmOmitDevInstallCommand($packageJson);

        if (! $this->packageJsonRequiresProductionBuild($packageJson)) {
            return '[ -f package.json ] && '.$steadyStateInstall.' && '.$binFix;
        }

        $artifactMissingCheck = $this->packageJsonBuildArtifactMissingCheck($packageJson);
        $pruneStep = $keepDevDependencies ? '' : $pruneCommand;
        $buildBranch = $installForBuild.' && '.$binFix.' && '.$prepareStep.$buildCommand
            .($pruneStep !== '' ? ' && '.$pruneStep : '');

        return '[ -f package.json ] && { if '.$artifactMissingCheck.'; then rm -rf node_modules && '.$buildBranch.'; else '.$steadyStateInstall.' && '.$binFix.'; fi; }';
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
