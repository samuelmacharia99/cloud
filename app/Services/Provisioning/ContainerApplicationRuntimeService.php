<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\SSH\SSHService;

class ContainerApplicationRuntimeService
{
    /**
     * @var list<string>
     */
    private const RUNTIME_TEMPLATE_SLUGS = ['nodejs', 'python', 'ruby', 'go'];

    public function supportsTemplate(?string $slug): bool
    {
        return in_array($slug, self::RUNTIME_TEMPLATE_SLUGS, true);
    }

    public function detectFromHost(
        SSHService $ssh,
        string $hostAppPath,
        string $slug,
        int $defaultPort,
        bool $includeNodeBootstrap = true,
    ): ApplicationRuntime {
        return match ($slug) {
            'nodejs' => $this->detectNodeRuntime($ssh, $hostAppPath, $defaultPort, $includeNodeBootstrap),
            'ruby' => $this->detectRubyRuntime($ssh, $hostAppPath, $defaultPort),
            'python' => $this->detectPythonRuntime($ssh, $hostAppPath, $defaultPort),
            'go' => $this->detectGoRuntime($ssh, $hostAppPath, $defaultPort),
            default => $this->fallbackRuntime($slug, $defaultPort),
        };
    }

    public function detectRuntimeAt(
        SSHService $ssh,
        string $hostAppPath,
        string $relativeRoot,
        string $slug,
        int $defaultPort,
        bool $includeNodeBootstrap = true,
    ): ApplicationRuntime {
        $relativeRoot = trim(str_replace('\\', '/', $relativeRoot), '/');
        if ($relativeRoot === '' || $relativeRoot === '.') {
            return $this->detectFromHost($ssh, $hostAppPath, $slug, $defaultPort, $includeNodeBootstrap);
        }
        if (str_contains($relativeRoot, '..')
            || preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$#', $relativeRoot) !== 1) {
            throw new \DomainException('Workload root must be a safe relative repository directory.');
        }

        $projectHost = rtrim($hostAppPath, '/').'/'.$relativeRoot;
        if ($slug === 'nodejs') {
            return $this->detectNodeRuntimeAt(
                $ssh,
                $hostAppPath,
                $relativeRoot,
                $defaultPort,
                $includeNodeBootstrap,
            );
        }

        $runtime = match ($slug) {
            'python' => $this->detectPythonRuntime($ssh, $projectHost, $defaultPort),
            'ruby' => $this->detectRubyRuntime($ssh, $projectHost, $defaultPort),
            'go' => $this->detectGoRuntime($ssh, $projectHost, $defaultPort),
            default => $this->fallbackRuntime($slug, $defaultPort),
        };

        return $this->withWorkdir($runtime, $relativeRoot);
    }

    public function detectNodeRuntime(
        SSHService $ssh,
        string $hostAppPath,
        int $defaultPort,
        bool $includeBootstrap = true,
    ): ApplicationRuntime {
        $relative = $this->discoverNodeProjectRelativeRoot($ssh, $hostAppPath);
        $projectHost = $relative === '' ? $hostAppPath : $hostAppPath.'/'.$relative;
        $workdir = $this->sanitizeContainerWorkdir($relative === '' ? '/app' : '/app/'.$relative);
        $rootPackageJson = $this->readHostFile($ssh, $hostAppPath.'/package.json');
        $projectPackageJson = $this->readHostFile($ssh, $projectHost.'/package.json');
        $isWorkspace = $this->isNodeWorkspaceLayout(
            $ssh,
            $hostAppPath,
            $relative,
            $rootPackageJson,
            $projectPackageJson
        );

        return $this->detectNodeFromContents(
            $this->readProcfileWebCommand($ssh, $projectHost),
            $projectPackageJson,
            $this->hostFileExists($ssh, $projectHost.'/server.js'),
            $this->hostFileExists($ssh, $projectHost.'/app.js'),
            $this->hostFileExists($ssh, $projectHost.'/index.js'),
            $defaultPort,
            $workdir,
            $isWorkspace ? $rootPackageJson : null,
            $isWorkspace ? '/app' : $workdir,
            $includeBootstrap,
        );
    }

    public function detectNodeRuntimeAt(
        SSHService $ssh,
        string $hostAppPath,
        string $relativeRoot,
        int $defaultPort,
        bool $includeBootstrap = true,
    ): ApplicationRuntime {
        $relativeRoot = trim(str_replace('\\', '/', $relativeRoot), '/');
        if ($relativeRoot === ''
            || str_contains($relativeRoot, '..')
            || preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$#', $relativeRoot) !== 1) {
            throw new \DomainException('Node workload root must be a safe relative repository directory.');
        }
        $projectHost = rtrim($hostAppPath, '/').'/'.$relativeRoot;
        $workdir = $this->sanitizeContainerWorkdir('/app/'.$relativeRoot);
        $rootPackageJson = $this->readHostFile($ssh, rtrim($hostAppPath, '/').'/package.json');
        $projectPackageJson = $this->readHostFile($ssh, $projectHost.'/package.json');
        if ($projectPackageJson === null) {
            throw new \DomainException("No package.json was found in {$relativeRoot}.");
        }
        $isWorkspace = $this->isNodeWorkspaceLayout(
            $ssh,
            $hostAppPath,
            $relativeRoot,
            $rootPackageJson,
            $projectPackageJson,
        );

        return $this->detectNodeFromContents(
            $this->readProcfileWebCommand($ssh, $projectHost),
            $projectPackageJson,
            $this->hostFileExists($ssh, $projectHost.'/server.js'),
            $this->hostFileExists($ssh, $projectHost.'/app.js'),
            $this->hostFileExists($ssh, $projectHost.'/index.js'),
            $defaultPort,
            $workdir,
            $isWorkspace ? $rootPackageJson : null,
            $isWorkspace ? '/app' : $workdir,
            $includeBootstrap,
        );
    }

    /**
     * @return list<string>
     */
    public function nodeProjectRootCandidates(): array
    {
        return [
            '',
            'backend',
            'server',
            'api',
            'app',
            'web',
            'frontend',
            'src',
            'apps/web',
            'apps/api',
            'packages/web',
            'packages/api',
        ];
    }

    public function sanitizeContainerWorkdir(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || $path === '/app' || $path === 'app') {
            return '/app';
        }
        if (str_starts_with($path, '/')) {
            if (! str_starts_with($path, '/app/')) {
                return '/app';
            }
            $path = substr($path, 1);
        } elseif (! str_starts_with($path, 'app/')) {
            $path = 'app/'.$path;
        }
        if (str_contains($path, '..') || preg_match('#^app(?:/[A-Za-z0-9._-]+)+$#', $path) !== 1) {
            return '/app';
        }

        return '/'.$path;
    }

    public function discoverNodeProjectRelativeRoot(SSHService $ssh, string $hostAppPath): string
    {
        $root = $this->inspectNodeProjectAt($ssh, $hostAppPath, '');
        if ($root['runnable'] && ! $root['workspace_only']) {
            return '';
        }

        foreach ($this->nodeProjectRootCandidates() as $relative) {
            if ($relative === '') {
                continue;
            }
            $candidate = $this->inspectNodeProjectAt($ssh, $hostAppPath, $relative);
            if ($candidate['runnable']) {
                return $relative;
            }
        }

        return '';
    }

    /**
     * @return array{runnable: bool, workspace_only: bool}
     */
    private function inspectNodeProjectAt(SSHService $ssh, string $hostAppPath, string $relative): array
    {
        $path = $relative === '' ? $hostAppPath : $hostAppPath.'/'.$relative;
        $packageJson = $this->readHostFile($ssh, $path.'/package.json');

        return [
            'runnable' => $this->packageJsonLooksRunnable(
                $packageJson,
                $this->hostFileExists($ssh, $path.'/server.js'),
                $this->hostFileExists($ssh, $path.'/app.js'),
                $this->hostFileExists($ssh, $path.'/index.js')
            ),
            'workspace_only' => $this->packageJsonIsWorkspaceRoot($packageJson)
                && ! $this->packageJsonHasDirectStart($packageJson),
        ];
    }

    public function packageJsonHasDirectStart(?string $packageJson): bool
    {
        if ($this->packageJsonStartScript($packageJson) !== null) {
            return true;
        }

        return $this->packageJsonPreviewScript($packageJson) !== null;
    }

    public function packageJsonPreviewScript(?string $packageJson): ?string
    {
        if ($packageJson === null || trim($packageJson) === '') {
            return null;
        }

        $data = json_decode($packageJson, true);
        if (! is_array($data)) {
            return null;
        }

        $preview = trim((string) ($data['scripts']['preview'] ?? ''));

        return $preview !== '' ? $preview : null;
    }

    public function packageJsonIsWorkspaceRoot(?string $packageJson): bool
    {
        if ($packageJson === null || trim($packageJson) === '') {
            return false;
        }

        $data = json_decode($packageJson, true);
        if (! is_array($data)) {
            return false;
        }

        if (! empty($data['workspaces'])) {
            return true;
        }

        $manager = strtolower(trim((string) ($data['packageManager'] ?? '')));

        return str_starts_with($manager, 'pnpm@') || $manager === 'pnpm';
    }

    public function packageJsonUsesWorkspaceProtocol(?string $packageJson): bool
    {
        if ($packageJson === null || trim($packageJson) === '') {
            return false;
        }

        $data = json_decode($packageJson, true);
        if (! is_array($data)) {
            return false;
        }

        foreach (['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies'] as $key) {
            $deps = $data[$key] ?? null;
            if (! is_array($deps)) {
                continue;
            }

            foreach ($deps as $spec) {
                if (is_string($spec) && str_starts_with($spec, 'workspace:')) {
                    return true;
                }
            }
        }

        return false;
    }

    public function packageJsonIndicatesWorkspaceLayout(
        ?string $rootPackageJson,
        ?string $projectPackageJson,
        string $relative = ''
    ): bool {
        if ($this->packageJsonUsesWorkspaceProtocol($projectPackageJson)
            || $this->packageJsonUsesWorkspaceProtocol($rootPackageJson)) {
            return true;
        }

        return $relative !== '' && $this->packageJsonIsWorkspaceRoot($rootPackageJson);
    }

    public function isNodeWorkspaceLayout(
        SSHService $ssh,
        string $hostAppPath,
        string $relative,
        ?string $rootPackageJson,
        ?string $projectPackageJson
    ): bool {
        if ($this->packageJsonIndicatesWorkspaceLayout($rootPackageJson, $projectPackageJson, $relative)) {
            return true;
        }

        return $relative !== ''
            && $this->hostFileExists($ssh, $hostAppPath.'/pnpm-workspace.yaml');
    }

    public function relativeDirUnderApp(string $containerWorkdir): string
    {
        $dir = $this->sanitizeContainerWorkdir($containerWorkdir);
        if ($dir === '/app') {
            return '';
        }

        return ltrim(substr($dir, strlen('/app/')), '/');
    }

    public function sanitizeArtifactRelativeDir(string $dir): string
    {
        $dir = trim(str_replace('\\', '/', $dir), '/');
        if ($dir === '' || str_contains($dir, '..') || preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$#', $dir) !== 1) {
            return '';
        }

        return $dir;
    }

    public function packageJsonLooksRunnable(
        ?string $packageJson,
        bool $hasServerJs = false,
        bool $hasAppJs = false,
        bool $hasIndexJs = false
    ): bool {
        if ($hasServerJs || $hasAppJs || $hasIndexJs) {
            return true;
        }
        if ($this->packageJsonHasDirectStart($packageJson)) {
            return true;
        }
        if ($packageJson === null || trim($packageJson) === '') {
            return false;
        }
        if ($this->packageJsonUsesNext($packageJson) || $this->packageJsonHasVite($packageJson)) {
            return true;
        }

        $data = json_decode($packageJson, true);

        return is_array($data) && ! empty($data['main']) && is_string($data['main']);
    }

    public function detectNodeFromContents(
        ?string $procfileCommand,
        ?string $packageJson,
        bool $hasServerJs,
        bool $hasAppJs,
        bool $hasIndexJs,
        int $defaultPort,
        string $containerWorkdir = '/app',
        ?string $workspaceRootPackageJson = null,
        ?string $bootstrapWorkdir = null,
        bool $includeBootstrap = true,
    ): ApplicationRuntime {
        $workdir = $this->sanitizeContainerWorkdir($containerWorkdir);
        $bootDir = $this->sanitizeContainerWorkdir(
            $bootstrapWorkdir
            ?? ($workspaceRootPackageJson !== null ? '/app' : $containerWorkdir)
        );
        $artifactRel = $bootDir !== $workdir ? $this->relativeDirUnderApp($workdir) : '';
        $bootstrap = $includeBootstrap
            ? $this->nodeBootstrap($packageJson, $workspaceRootPackageJson, $artifactRel)
            : null;

        if ($procfileCommand !== null) {
            $platformCommand = $this->platformNodeListenCommand($procfileCommand, $defaultPort, $packageJson);
            if ($platformCommand !== null) {
                return $this->platformNodeRuntime(
                    $platformCommand,
                    $defaultPort,
                    $packageJson,
                    $workdir,
                    null,
                    null,
                    $workspaceRootPackageJson,
                    $bootDir,
                    $includeBootstrap,
                );
            }

            return $this->shellRuntime(
                $procfileCommand,
                $defaultPort,
                'procfile',
                'Procfile web process',
                $bootstrap,
                $workdir,
                $bootDir
            );
        }

        if ($packageJson !== null) {
            $data = json_decode($packageJson, true);
            if (is_array($data)) {
                if (! empty($data['scripts']['start'])) {
                    $start = trim((string) $data['scripts']['start']);
                    $platformCommand = $this->platformNodeListenCommand($start, $defaultPort, $packageJson);
                    if ($platformCommand !== null) {
                        return $this->platformNodeRuntime(
                            $platformCommand,
                            $defaultPort,
                            $packageJson,
                            $workdir,
                            null,
                            null,
                            $workspaceRootPackageJson,
                            $bootDir,
                            $includeBootstrap,
                        );
                    }

                    // Keep custom Vite servers (API + SPA). Install Vite; do not strip to preview.
                    if ($this->productionStartRequiresVite($packageJson)
                        && $this->commandLooksLikeViteDevServer($start)) {
                        return $this->shellRuntime(
                            'npm start',
                            $defaultPort,
                            'vite',
                            'Vite app server',
                            $bootstrap,
                            $workdir,
                            $bootDir
                        );
                    }

                    return $this->shellRuntime(
                        'npm start',
                        $defaultPort,
                        'package.json',
                        'npm start',
                        $bootstrap,
                        $workdir,
                        $bootDir
                    );
                }

                $inferred = $this->inferNodeStartWithoutScriptsStart($packageJson, $defaultPort);
                if ($inferred !== null) {
                    return $this->platformNodeRuntime(
                        $inferred['command'],
                        $defaultPort,
                        $packageJson,
                        $workdir,
                        $inferred['source'],
                        $inferred['label'],
                        $workspaceRootPackageJson,
                        $bootDir,
                        $includeBootstrap,
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
                            $bootstrap,
                            $workdir,
                            $bootDir
                        );
                    }
                }
            }
        }

        if ($hasServerJs) {
            return $this->shellRuntime('node server.js', $defaultPort, 'entrypoint', 'node server.js', $bootstrap, $workdir, $bootDir);
        }

        if ($hasAppJs) {
            return $this->shellRuntime('node app.js', $defaultPort, 'entrypoint', 'node app.js', $bootstrap, $workdir, $bootDir);
        }

        if ($hasIndexJs) {
            return $this->shellRuntime('node index.js', $defaultPort, 'entrypoint', 'node index.js', $bootstrap, $workdir, $bootDir);
        }

        return $this->fallbackRuntime('nodejs', $defaultPort);
    }

    /**
     * @return array{command: string, source: string, label: string}|null
     */
    public function inferNodeStartWithoutScriptsStart(?string $packageJson, int $defaultPort): ?array
    {
        $preview = $this->packageJsonPreviewScript($packageJson);
        if ($preview !== null) {
            $platform = $this->platformNodeListenCommand($preview, $defaultPort, $packageJson);

            return [
                'command' => $platform ?? 'npm run preview',
                'source' => $platform !== null ? 'vite' : 'package.json',
                'label' => $platform !== null ? 'Vite production preview' : 'npm run preview',
            ];
        }

        if ($this->packageJsonUsesNext($packageJson)) {
            return [
                'command' => 'npx next start -H 0.0.0.0 -p ${PORT:-'.$defaultPort.'}',
                'source' => 'next',
                'label' => 'Next.js server',
            ];
        }

        if ($this->packageJsonHasVite($packageJson) && $this->packageJsonHasBuildScript($packageJson)) {
            return [
                'command' => 'npx vite preview --host 0.0.0.0 --port ${PORT:-'.$defaultPort.'} --strictPort',
                'source' => 'vite',
                'label' => 'Vite production preview',
            ];
        }

        if ($this->packageJsonHasExpo($packageJson)) {
            return [
                'command' => 'npx --yes serve@14 dist -s --listen tcp://0.0.0.0:${PORT:-'.$defaultPort.'}',
                'source' => 'expo-web',
                'label' => 'Expo web export',
            ];
        }

        return null;
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

    public function detectGoRuntime(SSHService $ssh, string $hostAppPath, int $defaultPort): ApplicationRuntime
    {
        return $this->detectGoFromContents(
            $this->readProcfileWebCommand($ssh, $hostAppPath),
            $this->hostFileExists($ssh, $hostAppPath.'/go.mod'),
            $this->hostFileExists($ssh, $hostAppPath.'/main.go'),
            $this->hostFileExists($ssh, $hostAppPath.'/cmd/server/main.go'),
            $defaultPort
        );
    }

    public function detectGoFromContents(
        ?string $procfileCommand,
        bool $hasGoMod,
        bool $hasMainGo,
        bool $hasCmdServer,
        int $defaultPort
    ): ApplicationRuntime {
        if ($procfileCommand !== null) {
            return $this->shellRuntime(
                $procfileCommand,
                $defaultPort,
                'procfile',
                'Procfile web process',
                $this->goBootstrap($hasGoMod)
            );
        }

        if ($hasCmdServer) {
            return $this->shellRuntime(
                'go run ./cmd/server',
                $defaultPort,
                'entrypoint',
                'go run ./cmd/server',
                $this->goBootstrap($hasGoMod)
            );
        }

        if ($hasMainGo || $hasGoMod) {
            return $this->shellRuntime(
                'go run .',
                $defaultPort,
                'entrypoint',
                'go run .',
                $this->goBootstrap($hasGoMod)
            );
        }

        return $this->fallbackRuntime('go', $defaultPort);
    }

    private function goBootstrap(bool $hasGoMod): ?string
    {
        return $hasGoMod ? 'go mod download' : null;
    }

    public function fallbackRuntime(string $slug, int $defaultPort): ApplicationRuntime
    {
        $command = match ($slug) {
            'nodejs' => 'node -e "require(\'http\').createServer((_,res)=>{res.writeHead(200,{\'Content-Type\':\'text/plain\'});res.end(\'Talksasa: add your Node.js app to /app\');}).listen(process.env.PORT||'
                .$defaultPort.",'0.0.0.0')\"",
            'python' => 'python -m http.server ${PORT:-'.$defaultPort.'} --bind 0.0.0.0',
            'ruby' => 'ruby -run -e httpd . -p ${PORT:-'.$defaultPort.'} -b 0.0.0.0',
            'go' => 'sleep infinity',
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
        ?string $bootstrap = null,
        string $containerWorkdir = '/app',
        ?string $bootstrapWorkdir = null
    ): ApplicationRuntime {
        $innerCommand = $this->sanitizeInnerCommand($innerCommand);
        $prefix = '';
        $startDir = $this->sanitizeContainerWorkdir($containerWorkdir);
        $bootDir = $this->sanitizeContainerWorkdir($bootstrapWorkdir ?? $containerWorkdir);

        if ($bootstrap !== null && trim($bootstrap) !== '') {
            $prefix = trim($bootstrap).' && ';
        }

        $script = $bootDir !== $startDir
            ? 'cd '.$bootDir.' && export PORT=${PORT:-'.$defaultPort.'} && '.$prefix.'cd '.$startDir.' && exec '.$innerCommand
            : 'cd '.$startDir.' && export PORT=${PORT:-'.$defaultPort.'} && '.$prefix.'exec '.$innerCommand;

        return new ApplicationRuntime(
            ['sh', '-lc', $script],
            $source,
            $label,
            $startDir
        );
    }

    private function withWorkdir(ApplicationRuntime $runtime, string $relativeRoot): ApplicationRuntime
    {
        $workdir = $this->sanitizeContainerWorkdir('/app/'.$relativeRoot);
        $command = $runtime->command;
        if (($command[0] ?? null) === 'sh' && ($command[1] ?? null) === '-lc' && isset($command[2])) {
            $command[2] = preg_replace('/^cd \/app && /', 'cd '.$workdir.' && ', (string) $command[2], 1)
                ?? $command[2];
        }

        return new ApplicationRuntime($command, $runtime->source, $runtime->label, $workdir);
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
     * Rewrite framework start commands that hardcode a listen port/hostname.
     *
     * Customer apps often ship:
     * - `next start -p 3001` → 502 behind our PORT mapping
     * - bare `vite` (dev CLI) → rewrite to `vite preview`
     *
     * Vite middleware / `node dist/server.cjs` starts are NOT rewritten: those servers
     * usually own `/api/*`. We keep their start command and install Vite at runtime.
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

        if ($this->commandLooksLikeExpoStart($command)
            && $this->packageJsonHasExpo($packageJson)
            && ! $this->packageJsonHasVite($packageJson)) {
            return 'npx --yes serve@14 dist -s --listen tcp://0.0.0.0:${PORT:-'.$defaultPort.'}';
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

    public function packageJsonHasExpo(?string $packageJson): bool
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

        return isset($dependencies['expo']) || isset($dependencies['react-native-web']);
    }

    public function commandLooksLikeExpoStart(string $command): bool
    {
        $normalized = strtolower(trim($command));
        if ($normalized === '') {
            return false;
        }

        return (bool) preg_match('/\bexpo\s+start\b/', $normalized)
            || (bool) preg_match('/\bexpo\s+serve\b/', $normalized);
    }

    public function packageJsonNeedsExpoWebExport(?string $packageJson): bool
    {
        if (! $this->packageJsonHasExpo($packageJson)) {
            return false;
        }

        $data = json_decode((string) $packageJson, true);
        if (! is_array($data)) {
            return false;
        }

        $scripts = is_array($data['scripts'] ?? null) ? $data['scripts'] : [];
        $build = strtolower(trim((string) ($scripts['build'] ?? '')));
        if ($build !== '' && ! str_contains($build, 'expo export')) {
            return false;
        }

        return true;
    }

    /**
     * True when the start script is a Vite middleware / tsx-dev entry that imports Vite at runtime,
     * or a Vite template custom server under dist/ that still requires Vite after build.
     * Those apps keep their start command; Talksasa installs the Vite tree so they do not crash.
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

        if ($this->commandLooksLikeBareViteCli($normalized)) {
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
     * `require('vite')` in the bundle and also expose /api routes. Keep that server; install Vite.
     */
    public function commandLooksLikeViteBundledCustomServer(string $command): bool
    {
        $normalized = strtolower(trim($command));

        return (bool) preg_match(
            '/\bnode\b(?:\s+[^\s]+)*\s+[\'"]?(?:\.\/)?(?:dist|build)\/server(?:\.[cm]?js)?[\'"]?(?:\s|$)/',
            $normalized
        );
    }

    /**
     * Bare Vite CLI (`vite`, `npx vite --host`) with no preview/build — SPA-only, safe to preview.
     */
    public function commandLooksLikeBareViteCli(string $command): bool
    {
        $normalized = strtolower(trim($command));
        if ($normalized === '' || $this->commandLooksLikeVitePreview($normalized)) {
            return false;
        }

        if (preg_match('/\bvite\s+build\b/', $normalized)) {
            return false;
        }

        if (preg_match('/\b(tsx|ts-node|ts-node-esm|node)\b/', $normalized)) {
            return false;
        }

        return (bool) preg_match('/^(?:npx\s+)?vite(?:\s|$)/', $normalized);
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

        // Only rewrite the bare Vite CLI. Preserve custom servers that own /api routes.
        return $this->commandLooksLikeBareViteCli($command);
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

        if ($this->commandLooksLikeVitePreview($start)
            || $this->commandLooksLikeViteDevServer($start)) {
            return true;
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

    private function platformNodeRuntime(
        string $platformCommand,
        int $defaultPort,
        ?string $packageJson,
        string $containerWorkdir = '/app',
        ?string $source = null,
        ?string $label = null,
        ?string $workspaceRootPackageJson = null,
        ?string $bootstrapWorkdir = null,
        bool $includeBootstrap = true,
    ): ApplicationRuntime {
        $isNext = str_contains($platformCommand, 'next start');
        $startDir = $this->sanitizeContainerWorkdir($containerWorkdir);
        $bootDir = $this->sanitizeContainerWorkdir($bootstrapWorkdir ?? $containerWorkdir);
        $artifactRel = $bootDir !== $startDir ? $this->relativeDirUnderApp($startDir) : '';
        $preStart = $includeBootstrap
            ? $this->nodeBootstrap($packageJson, $workspaceRootPackageJson, $artifactRel)
            : ($isNext ? $this->nodeProductionArtifactGuard($packageJson, $artifactRel) : null);

        return $this->shellRuntime(
            $platformCommand,
            $defaultPort,
            $source ?? ($isNext ? 'next' : 'vite'),
            $label ?? ($isNext ? 'Next.js server' : 'Vite production preview'),
            $preStart,
            $startDir,
            $bootDir
        );
    }

    public function nodeProductionArtifactGuard(?string $packageJson, string $relativeDir = ''): string
    {
        $missing = $this->packageJsonBuildArtifactMissingCheck($packageJson, $relativeDir);

        return 'if '.$missing.'; then echo production-start-no-build-id >&2; exit 78; fi';
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

        if ($this->packageJsonNeedsExpoWebExport($packageJson)) {
            return true;
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
            'turbo',
            'expo',
        ];

        foreach ($buildPackages as $package) {
            if (isset($dependencies[$package])) {
                return true;
            }
        }

        $build = strtolower((string) ($scripts['build'] ?? ''));
        if (str_contains($build, 'turbo')) {
            return true;
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
    public function packageJsonBuildArtifactMissingCheck(?string $packageJson, string $relativeDir = ''): string
    {
        $prefix = $this->sanitizeArtifactRelativeDir($relativeDir);
        $base = $prefix === '' ? '' : $prefix.'/';

        if ($packageJson === null || trim($packageJson) === '') {
            return '[ ! -d '.$base.'dist ]';
        }

        $data = json_decode($packageJson, true);
        if (! is_array($data)) {
            return '[ ! -d '.$base.'dist ]';
        }

        $dependencies = array_merge(
            is_array($data['dependencies'] ?? null) ? $data['dependencies'] : [],
            is_array($data['devDependencies'] ?? null) ? $data['devDependencies'] : []
        );

        if (isset($dependencies['next'])) {
            return '[ ! -f '.$base.'.next/BUILD_ID ]';
        }

        if (isset($dependencies['nuxt'])) {
            return '[ ! -d '.$base.'.output/server ]';
        }

        $artifactDir = $this->packageJsonBuildOutputDir($packageJson);

        return '[ ! -d '.$base.$artifactDir.' ]';
    }

    private const NODE_NPM_BIN = '/usr/local/bin/npm';

    private const NODE_CLEAN_ENV = 'HOME=/tmp NPM_CONFIG_CACHE=/tmp/.npm PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin npm_config_omit=';

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

    /**
     * @return array<string, string>
     */
    public function corepackEnvironment(): array
    {
        return [
            'COREPACK_HOME' => '/tmp/.corepack',
            'COREPACK_ENABLE_DOWNLOAD_PROMPT' => '0',
            // package.json packageManager: npm must not crash-loop leftover yarn/pnpm shims.
            'COREPACK_ENABLE_STRICT' => '0',
        ];
    }

    public function pnpmInstallShellCommand(bool $preferDevDependencies, bool $frozenLockfile, bool $viaNpx = false): string
    {
        $args = 'install';
        if ($frozenLockfile) {
            $args .= ' --frozen-lockfile';
        }
        if (! $preferDevDependencies) {
            $args .= ' --prod';
        }

        $binary = $viaNpx
            ? '/usr/local/bin/npx --yes pnpm@9'
            : '/usr/local/bin/corepack pnpm';

        return $this->nodeCleanCommand(
            $binary.' '.$args,
            $preferDevDependencies ? 'development' : 'production',
            $this->corepackEnvironment(),
        );
    }

    public function yarnInstallShellCommand(bool $preferDevDependencies, string $mode = 'immutable'): string
    {
        $args = match ($mode) {
            'frozen' => 'install --frozen-lockfile',
            'loose' => 'install',
            default => 'install --immutable',
        };

        if (! $preferDevDependencies) {
            $args .= ' --production';
        }

        return $this->nodeCleanCommand(
            '/usr/local/bin/corepack yarn '.$args,
            $preferDevDependencies ? 'development' : 'production',
            $this->corepackEnvironment(),
        );
    }

    public function nodePruneShellCommand(string $packageManager = 'npm'): string
    {
        return match ($packageManager) {
            'pnpm' => $this->nodeCleanCommand(
                '/usr/local/bin/corepack pnpm prune --prod',
                'production',
                $this->corepackEnvironment(),
            ),
            'yarn' => $this->yarnInstallShellCommand(preferDevDependencies: false, mode: 'loose'),
            default => $this->npmPruneShellCommand(),
        };
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

    /**
     * Corepack / pnpm refuse to run when this field names a different manager.
     */
    public function declaredNodePackageManagerFromPackageJson(?string $packageJson): ?string
    {
        if ($packageJson === null || trim($packageJson) === '') {
            return null;
        }

        $data = json_decode($packageJson, true);
        if (! is_array($data)) {
            return null;
        }

        $declared = strtolower(trim((string) ($data['packageManager'] ?? '')));
        if ($declared === '') {
            return null;
        }

        return preg_match(
            '/^(npm|pnpm|yarn)(?:@\d+\.\d+\.\d+(?:-[0-9a-z.-]+)?(?:\+[0-9a-z.-]+)?)?$/i',
            $declared,
            $matches,
        ) === 1
            ? strtolower($matches[1])
            : null;
    }

    public function malformedNodePackageManagerFromPackageJson(?string $packageJson): ?string
    {
        if ($packageJson === null || trim($packageJson) === '') {
            return null;
        }

        $data = json_decode($packageJson, true);
        if (! is_array($data)) {
            return null;
        }

        $declared = trim((string) ($data['packageManager'] ?? ''));

        return $declared !== '' && $this->declaredNodePackageManagerFromPackageJson($packageJson) === null
            ? $declared
            : null;
    }

    public function detectNodePackageManagerFromPackageJson(?string $packageJson): string
    {
        return $this->declaredNodePackageManagerFromPackageJson($packageJson)
            ?? ($this->packageJsonUsesWorkspaceProtocol($packageJson) ? 'pnpm' : 'npm');
    }

    public function resolveNodePackageManager(?string $packageJson, ?string $workspaceRootPackageJson = null): string
    {
        $declaredRoot = $this->declaredNodePackageManagerFromPackageJson($workspaceRootPackageJson);
        if ($declaredRoot !== null) {
            return $declaredRoot;
        }

        $declaredApp = $this->declaredNodePackageManagerFromPackageJson($packageJson);
        if ($declaredApp !== null) {
            return $declaredApp;
        }

        if ($this->packageJsonUsesWorkspaceProtocol($packageJson)
            || $this->packageJsonUsesWorkspaceProtocol($workspaceRootPackageJson)) {
            return 'pnpm';
        }

        return 'npm';
    }

    public function packageJsonUsesTurbo(?string $packageJson): bool
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
        if (isset($dependencies['turbo'])) {
            return true;
        }

        $build = strtolower((string) (($data['scripts'] ?? [])['build'] ?? ''));

        return str_contains($build, 'turbo');
    }

    public function npmBuildShellCommand(
        ?int $containerMemoryLimitMb = null,
        bool $withoutContainerMemoryLimit = false,
        ?string $packageJson = null,
        array $extraEnv = [],
        ?string $packageManager = null,
    ): string {
        $extra = $this->corepackEnvironment();
        $extra['TURBO_TELEMETRY_DISABLED'] = '1';

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

        $manager = $packageManager ?? $this->detectNodePackageManagerFromPackageJson($packageJson);

        // Turborepo resolves the repo package manager from lockfile / packageManager.
        // A root Next dependency must not skip `turbo run build` in a monorepo.
        if ($this->packageJsonUsesNext($packageJson) && ! $this->packageJsonUsesTurbo($packageJson)) {
            return $this->nodeCleanCommand('node ./node_modules/next/dist/bin/next build', 'production', $extra);
        }

        return $this->nodeCleanCommand($this->nodePackageManagerRunBuildBinary($manager), 'production', $extra);
    }

    public function nodeProductionBuildShellCommand(
        ?string $projectPackageJson,
        ?string $workspaceRootPackageJson,
        string $packageManager,
        string $applicationRelativeDir = '',
        array $extraEnv = [],
    ): string {
        $relativeDir = $this->sanitizeArtifactRelativeDir($applicationRelativeDir);
        $usesRootTurbo = $workspaceRootPackageJson !== null
            && $this->packageJsonUsesTurbo($workspaceRootPackageJson);

        if ($this->packageJsonNeedsExpoWebExport($projectPackageJson)
            && ! $this->packageJsonHasBuildScript($projectPackageJson)) {
            $export = 'npx --yes expo export --platform web --output-dir dist';
            $binary = $relativeDir !== ''
                ? 'cd '.escapeshellarg($relativeDir).' && '.$export
                : $export;

            return $this->nodeCleanCommand(
                $binary,
                'production',
                array_merge(
                    $this->corepackEnvironment(),
                    ['EXPO_NO_TELEMETRY' => '1'],
                    $extraEnv,
                ),
            );
        }

        if ($relativeDir !== '' && ! $usesRootTurbo) {
            $binary = match ($packageManager) {
                'yarn' => '/usr/local/bin/corepack yarn --cwd '.$relativeDir.' run build',
                'pnpm' => '/usr/local/bin/corepack pnpm --dir '.$relativeDir.' run build',
                default => self::NODE_NPM_BIN.' --prefix '.$relativeDir.' run build',
            };

            return $this->nodeCleanCommand(
                $binary,
                'production',
                array_merge(
                    $this->corepackEnvironment(),
                    ['TURBO_TELEMETRY_DISABLED' => '1'],
                    $extraEnv,
                ),
            );
        }

        return $this->npmBuildShellCommand(
            null,
            true,
            $usesRootTurbo ? $workspaceRootPackageJson : $projectPackageJson,
            $extraEnv,
            $packageManager,
        );
    }

    public function nodePackageManagerRunBuildBinary(string $packageManager): string
    {
        return match ($packageManager) {
            'pnpm' => '/usr/local/bin/corepack pnpm run build',
            'yarn' => '/usr/local/bin/corepack yarn run build',
            default => self::NODE_NPM_BIN.' run build',
        };
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

        foreach (['NEXT_PUBLIC_', 'VITE_', 'NUXT_PUBLIC_', 'REACT_APP_', 'PUBLIC_', 'EXPO_PUBLIC_'] as $prefix) {
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
        return [];
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

    public function nodeBootstrap(
        ?string $packageJson = null,
        ?string $workspaceRootPackageJson = null,
        string $artifactRelativeDir = ''
    ): string {
        $isWorkspace = $workspaceRootPackageJson !== null
            || $this->packageJsonUsesWorkspaceProtocol($packageJson);
        $packageManager = $this->resolveNodePackageManager($packageJson, $workspaceRootPackageJson);

        $openssl = 'if command -v apk >/dev/null 2>&1; then apk add --no-cache openssl libc6-compat >/dev/null 2>&1 || true; fi && ';
        $binFix = $isWorkspace
            ? 'find node_modules/.bin apps/*/node_modules/.bin packages/*/node_modules/.bin node_modules/next/dist/bin apps/*/node_modules/next/dist/bin node_modules/vite/bin apps/*/node_modules/vite/bin -type f -exec chmod u+x {} + 2>/dev/null || true'
            : 'find node_modules/.bin node_modules/next/dist/bin node_modules/vite/bin -type f -exec chmod u+x {} + 2>/dev/null || true';

        $installForBuild = match ($packageManager) {
            'pnpm' => $this->pnpmInstallShellCommand(preferDevDependencies: true, frozenLockfile: false),
            'yarn' => $this->yarnInstallShellCommand(preferDevDependencies: true, mode: 'loose'),
            default => $this->npmInstallShellCommand(),
        };
        $artifactRel = $this->sanitizeArtifactRelativeDir($artifactRelativeDir);
        $buildCommand = $this->nodeProductionBuildShellCommand(
            $packageJson,
            $workspaceRootPackageJson,
            $packageManager,
            $artifactRel,
        );
        $pruneCommand = $this->nodePruneShellCommand($packageManager);
        $prepareStep = $this->nodeBuildPrepareEnabled()
            ? '{ [ ! -f .talksasa/prepare-build.cjs ] || node .talksasa/prepare-build.cjs; } && '
            : '';
        // Workspace links and Vite preview both need the full tree. Pruning
        // `workspace:*` packages (or Vite) crash-loops the container.
        $keepDevDependencies = $isWorkspace
            || $this->productionStartRequiresVite($packageJson)
            || $this->packageJsonNeedsExpoWebExport($packageJson);
        $steadyStateInstall = $isWorkspace
            ? $installForBuild
            : ($keepDevDependencies
                ? $this->npmDevInstallShellCommand($packageJson)
                : $this->npmOmitDevInstallCommand($packageJson));

        if (! $this->packageJsonRequiresProductionBuild($packageJson)
            && ! ($isWorkspace && $this->packageJsonRequiresProductionBuild($workspaceRootPackageJson))) {
            return $openssl.'[ -f package.json ] && '.$steadyStateInstall.' && '.$binFix;
        }

        $artifactMissingCheck = $this->packageJsonBuildArtifactMissingCheck($packageJson, $artifactRel);
        $pruneStep = $keepDevDependencies ? '' : $pruneCommand;
        $buildBranch = $installForBuild.' && '.$binFix.' && '.$prepareStep.$buildCommand
            .($pruneStep !== '' ? ' && '.$pruneStep : '');

        return $openssl.'[ -f package.json ] && { if '.$artifactMissingCheck.'; then rm -rf node_modules && '.$buildBranch.'; else '.$steadyStateInstall.' && '.$binFix.'; fi; }';
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
