<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDeploymentEvent;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Read a project's own migration command out of the repository, and run it.
 *
 * Every migration path the platform had was `php artisan migrate`, so a Node,
 * Python or Ruby API arrived with credentials, an empty schema, and no way to
 * create tables except the Terminal tab. Nothing here grants new access: the
 * customer can already run these commands in that terminal. What it adds is a
 * command chosen from evidence instead of memory, a table count either side of
 * the run, and a refusal to run the destructive variants.
 *
 * Laravel keeps Doctor's own `run_migrations` treatment, which knows about
 * config caching and migrate:fresh. This is the Database tab's path, and it
 * covers Laravel too so the tab behaves the same way on every stack.
 */
class ContainerDatabaseMigrationService
{
    /** Long enough for a real migration set, short enough to hold a request. */
    public const TIMEOUT_SECONDS = 300;

    /**
     * package.json entries that mean "migrate", most explicit first.
     *
     * @var list<string>
     */
    private const SCRIPT_NAMES = ['migrate', 'db:migrate', 'migrate:deploy', 'prisma:migrate', 'migration:run'];

    /**
     * Forms that reset, roll back, or stop for an answer nobody can type.
     * `prisma migrate dev` is the dangerous one in practice: it is the script
     * most projects declare, and it offers to reset the database on drift.
     */
    private const UNSAFE_SCRIPT = '/\bmigrate\s+dev\b|\bmigrate\s+reset\b|\bmigrate:fresh\b|\bmigrate:rollback\b'
        .'|\bdb:drop\b|\bdb:wipe\b|\bdrop-schema\b|--accept-data-loss|--force-reset/i';

    /**
     * Run the repository's migrations before anything judges the application
     * healthy.
     *
     * An application whose health route touches its own tables answers 500
     * until its schema exists, and a readiness check that runs before the
     * migration can never pass. So this waits only for the process to be
     * listening, which is the moment the tool can be run inside it, and lets
     * the caller's readiness check come after. Null when the repository
     * declares no migration tool, which is not a fault.
     *
     * @return array{plan: ContainerMigrationPlan, output: string, tables_before: ?int, tables_after: ?int}|null
     */
    public function runBeforeReadiness(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh,
        ContainerDeploymentService $deployments,
        bool $operationAlreadyLocked,
    ): ?array {
        $plan = $this->plan($service, $deployment, $ssh);
        if ($plan === null) {
            return null;
        }

        $deployments->waitForApplicationListening($ssh, $deployment, $this->listeningPath($service));

        return ['plan' => $plan] + $this->run($service, $deployment, $ssh, $plan, $operationAlreadyLocked);
    }

    /**
     * The route that proves the backend process is up. A split stack is
     * reached through its edge, which serves the frontend at "/" whether or
     * not the backend is there, so the backend is asked directly.
     */
    public function listeningPath(Service $service): string
    {
        return data_get($service->service_meta, 'node_workloads.topology') === 'split_web_api'
            ? '/api/health'
            : '/';
    }

    public function plan(Service $service, ContainerDeployment $deployment, SSHService $ssh): ?ContainerMigrationPlan
    {
        $hostAppPath = rtrim(app(ContainerAppDirectoryService::class)->hostAppPath($deployment), '/');

        // Every later probe reads a missing file as "tool not used". Confirm the
        // mount is readable first, or an unreachable node would be reported to
        // the customer as an application with no migration step.
        if (trim($ssh->exec('test -d '.escapeshellarg($hostAppPath).' && echo yes || echo no', 20)) !== 'yes') {
            throw new \RuntimeException('The application directory is not readable on the node.');
        }

        $root = trim((string) data_get($service->service_meta, 'node_workloads.backend.root', ''), '/');
        $hostRoot = $root === '' ? $hostAppPath : $hostAppPath.'/'.$root;
        $workDir = $root === '' ? '/app' : '/app/'.$root;

        return match (app(ContainerDoctorService::class)->resolveStackSlug($service)) {
            'laravel', 'php' => $this->laravelPlan($ssh, $hostAppPath, $hostRoot, $root),
            'nodejs' => $this->nodePlan($ssh, $hostAppPath, $hostRoot, $workDir),
            'python' => $this->pythonPlan($ssh, $hostRoot, $workDir),
            'ruby' => $this->rubyPlan($ssh, $hostRoot, $workDir),
            default => null,
        };
    }

    /**
     * @return array{output: string, tables_before: ?int, tables_after: ?int}
     */
    public function run(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh,
        ContainerMigrationPlan $plan,
        bool $operationAlreadyLocked = false,
    ): array {
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $commands = app(ContainerStackCommandService::class);

        // A deploy rewrites the same files and can restart the stack underneath
        // a running migration, so the two take the same lock. A pull runs this
        // as one of its own steps and already holds that lock, and blocking on
        // a lock this call's own caller owns waits out the timeout and fails a
        // migration that nothing was competing for. So the caller says so.
        $lock = $operationAlreadyLocked
            ? null
            : Cache::lock(
                app(ContainerNodeBuildService::class)->lockName($service),
                self::TIMEOUT_SECONDS + 60,
            );
        $lock?->block(10);

        $before = $this->countTables($service, $deployment, $ssh);

        $composeService = $commands->resolveAppComposeService($deployment);

        try {
            try {
                $output = $this->executeWithRetry($service, $deployment, $ssh, $plan, $containerPath, $composeService);
            } catch (\Throwable $e) {
                // A migration that stops because Postgres lacks an extension is
                // not wrong; the database is. Supply the extension and let the
                // migration say what it thinks a second time.
                $extension = $this->extensionTheDatabaseLacks($service, $deployment, $e);
                if ($extension === null) {
                    throw $e;
                }

                $enabled = app(ContainerPostgresExtensionService::class)
                    ->enableExtension($service, $deployment, $ssh, $extension);
                app(ContainerDeploymentEventRecorder::class)->record($service, $deployment, 'database_extension_enabled', [
                    'extension' => $extension,
                    'tool' => $plan->tool,
                    'message' => $enabled,
                ]);
                Log::info('Database extension enabled for a migration', [
                    'service_id' => $service->id,
                    'deployment_id' => $deployment->id,
                    'extension' => $extension,
                ]);

                $output = $enabled."\n"
                    .$this->executeWithRetry($service, $deployment, $ssh, $plan, $containerPath, $composeService);
            }
        } finally {
            $lock?->release();
        }

        $after = $this->countTables($service, $deployment, $ssh);

        $this->record($service, $deployment, $plan, $before, $after);

        return [
            'output' => $output,
            'tables_before' => $before,
            'tables_after' => $after,
        ];
    }

    /**
     * A first deploy races its own database sidecar: the application container
     * is up seconds before Postgres finishes initialising, and a migration that
     * arrives in that window fails on a refused connection rather than on
     * anything wrong with the migration.
     *
     * Only connection-shaped failures are retried. Bad SQL fails once, because
     * running it five more times tells nobody anything and costs five minutes
     * of a customer's deploy.
     */
    private function executeWithRetry(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh,
        ContainerMigrationPlan $plan,
        string $containerPath,
        string $composeService,
    ): string {
        $attempts = max(1, (int) config('containers.redeploy.migrate_max_attempts', 6));
        $delay = max(1, (int) config('containers.redeploy.migrate_retry_delay_seconds', 10));

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->execute($ssh, $deployment, $plan, $containerPath, $composeService);
            } catch (\Throwable $e) {
                if ($attempt >= $attempts || ! $this->databaseIsNotReadyYet($e->getMessage())) {
                    throw $e;
                }

                Log::info('Migration retried while the database finished starting', [
                    'service_id' => $service->id,
                    'deployment_id' => $deployment->id,
                    'tool' => $plan->tool,
                    'attempt' => $attempt,
                    'attempts' => $attempts,
                ]);

                sleep($delay);
            }
        }
    }

    private function execute(
        SSHService $ssh,
        ContainerDeployment $deployment,
        ContainerMigrationPlan $plan,
        string $containerPath,
        string $composeService,
    ): string {
        $commands = app(ContainerStackCommandService::class);

        // Prefer the container that is already up: it is the environment the
        // application itself runs in, and it spares the stack a second copy
        // of the app plus Compose's start-up narration. A stopped stack is
        // exactly the case a missing schema causes, so `run --rm` remains
        // the fallback — it starts the database sidecar on its own.
        // The live container, not the row. A crash-looping container is
        // recorded as running, because Docker reports it running for the
        // second between restarts, and `docker compose exec` into one
        // refuses with "is restarting, wait until the container is
        // running". Migrations are exactly what somebody reaches for when
        // their application will not boot, so the one state where they were
        // refused was the state that needed them.
        return $this->appContainerAcceptsExec($ssh, $deployment)
            ? $commands->execInContainer(
                $ssh,
                $containerPath,
                $composeService,
                $this->commandWithEnvironment($plan),
                $plan->workDir,
                self::TIMEOUT_SECONDS,
            )
            : $commands->runOneOffInContainer(
                $ssh,
                $containerPath,
                $composeService,
                $plan->command,
                $plan->workDir,
                self::TIMEOUT_SECONDS,
                $plan->environment,
            );
    }

    /**
     * The Postgres extension a failed migration named, when the sidecar is
     * Postgres. Null for every other kind of failure, and for MySQL, where the
     * same words never appear.
     */
    private function extensionTheDatabaseLacks(Service $service, ContainerDeployment $deployment, \Throwable $e): ?string
    {
        $extension = app(ContainerPostgresExtensionService::class)->extensionRequiredByError($e->getMessage());
        if ($extension === null) {
            return null;
        }

        $type = app(ContainerDatabaseSidecarResolver::class)->typeForService(
            $service,
            (string) ($deployment->docker_compose_content ?? ''),
        );

        return $type === 'postgresql' ? $extension : null;
    }

    /**
     * A database that has not finished starting, as opposed to a migration that
     * is wrong. Every phrase here is one a client library emits before it has
     * spoken to the server at all.
     */
    private function databaseIsNotReadyYet(string $message): bool
    {
        return preg_match(
            '/could not connect|connection refused|could not translate host name'
            .'|is starting up|OperationalError|Name or service not known|Temporary failure in name resolution'
            .'|server closed the connection unexpectedly|Can\'t connect to (MySQL|MariaDB)|\\[2002\\]/i',
            $message
        ) === 1;
    }

    /**
     * Whether the application container will accept `docker compose exec`.
     *
     * Restarting counts as no. The daemon refuses an exec into a container that
     * is between restarts, and a one-off container does the same work without
     * needing the application to stay up.
     */
    private function appContainerAcceptsExec(SSHService $ssh, ContainerDeployment $deployment): bool
    {
        try {
            $inspect = app(ContainerRuntimeInspector::class)->inspect($ssh, (string) $deployment->container_name);
        } catch (\Throwable) {
            // Unknown is treated as running, which is what the platform assumed
            // before it could ask. A wrong guess here costs one clear error.
            return $deployment->isRunning();
        }

        if (($inspect['missing'] ?? false) === true) {
            return false;
        }

        return ($inspect['running'] ?? false) === true
            && ($inspect['restarting'] ?? false) !== true;
    }

    /**
     * `docker compose exec` inherits the running container's environment but
     * takes no per-run overrides through this helper, so a plan that needs one
     * carries it in the command itself. `env` keeps that a single command, and
     * the values are the same restricted shape Compose flags accept.
     */
    public function commandWithEnvironment(ContainerMigrationPlan $plan): string
    {
        if ($plan->environment === []) {
            return $plan->command;
        }

        $prefix = 'env';
        foreach ($plan->environment as $key => $value) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key) !== 1
                || preg_match('/^[A-Za-z0-9._-]+$/', (string) $value) !== 1) {
                throw new \InvalidArgumentException('Invalid migration environment value.');
            }

            $prefix .= ' '.$key.'='.$value;
        }

        return $prefix.' '.$plan->command;
    }

    /**
     * The command a project declares for itself, when it is one we can run
     * unattended. A declared script wins over a tool we recognise, because the
     * author chose it — unless it is one of the forms that resets a database.
     */
    public function declaredScriptPlan(?string $packageJson, string $packageManager, string $workDir): ?ContainerMigrationPlan
    {
        $scripts = $this->scripts($packageJson);

        foreach (self::SCRIPT_NAMES as $name) {
            $script = trim((string) ($scripts[$name] ?? ''));
            if ($script === '' || $this->isUnsafeScript($script)) {
                continue;
            }

            return new ContainerMigrationPlan(
                tool: 'npm script',
                command: $this->runScriptCommand($packageManager, $name),
                workDir: $workDir,
                source: 'package.json scripts.'.$name.': '.$script,
            );
        }

        return null;
    }

    public function isUnsafeScript(string $script): bool
    {
        return preg_match(self::UNSAFE_SCRIPT, $script) === 1;
    }

    public function runScriptCommand(string $packageManager, string $script): string
    {
        return match ($packageManager) {
            'pnpm' => 'pnpm run '.$script,
            'yarn' => 'yarn run '.$script,
            default => 'npm run '.$script,
        };
    }

    private function nodePlan(SSHService $ssh, string $hostAppPath, string $hostRoot, string $workDir): ?ContainerMigrationPlan
    {
        $projectPackageJson = $this->read($ssh, $hostRoot.'/package.json');
        $workspacePackageJson = $hostRoot === $hostAppPath
            ? null
            : $this->read($ssh, $hostAppPath.'/package.json');

        $packageManager = app(ContainerApplicationRuntimeService::class)
            ->resolveNodePackageManager($projectPackageJson, $workspacePackageJson);

        $declared = $this->declaredScriptPlan($projectPackageJson, $packageManager, $workDir);
        if ($declared !== null) {
            return $declared;
        }

        if ($this->exists($ssh, $hostRoot.'/prisma/schema.prisma')) {
            // `migrate deploy` applies committed migrations and refuses to
            // create any. With no migrations directory it would fail on a
            // schema that has never been versioned, where `db push` is the
            // form that matches: it stops rather than discard data.
            $versioned = $this->exists($ssh, $hostRoot.'/prisma/migrations', 'd');

            return new ContainerMigrationPlan(
                tool: 'Prisma',
                command: $versioned ? 'npx --yes prisma migrate deploy' : 'npx --yes prisma db push',
                workDir: $workDir,
                source: $versioned
                    ? 'prisma/schema.prisma with prisma/migrations'
                    : 'prisma/schema.prisma without a migrations directory',
            );
        }

        foreach (['knexfile.js', 'knexfile.ts', 'knexfile.cjs'] as $knexfile) {
            if ($this->exists($ssh, $hostRoot.'/'.$knexfile)) {
                return new ContainerMigrationPlan(
                    tool: 'Knex',
                    command: 'npx --yes knex migrate:latest',
                    workDir: $workDir,
                    source: $knexfile,
                );
            }
        }

        foreach (['drizzle.config.ts', 'drizzle.config.js', 'drizzle.config.mjs'] as $drizzle) {
            if ($this->exists($ssh, $hostRoot.'/'.$drizzle)) {
                return new ContainerMigrationPlan(
                    tool: 'Drizzle',
                    command: 'npx --yes drizzle-kit migrate',
                    workDir: $workDir,
                    source: $drizzle,
                );
            }
        }

        if ($this->exists($ssh, $hostRoot.'/.sequelizerc') || $this->dependsOn($projectPackageJson, 'sequelize-cli')) {
            return new ContainerMigrationPlan(
                tool: 'Sequelize',
                command: 'npx --yes sequelize-cli db:migrate',
                workDir: $workDir,
                source: $this->exists($ssh, $hostRoot.'/.sequelizerc') ? '.sequelizerc' : 'sequelize-cli in package.json',
            );
        }

        return null;
    }

    private function pythonPlan(SSHService $ssh, string $hostRoot, string $workDir): ?ContainerMigrationPlan
    {
        if ($this->exists($ssh, $hostRoot.'/alembic.ini')) {
            return new ContainerMigrationPlan(
                tool: 'Alembic',
                command: 'alembic upgrade head',
                workDir: $workDir,
                source: 'alembic.ini',
            );
        }

        if ($this->exists($ssh, $hostRoot.'/manage.py')) {
            return new ContainerMigrationPlan(
                tool: 'Django',
                command: 'python manage.py migrate --noinput',
                workDir: $workDir,
                source: 'manage.py',
            );
        }

        return null;
    }

    private function rubyPlan(SSHService $ssh, string $hostRoot, string $workDir): ?ContainerMigrationPlan
    {
        if (! $this->exists($ssh, $hostRoot.'/bin/rails') || ! $this->exists($ssh, $hostRoot.'/db/migrate', 'd')) {
            return null;
        }

        return new ContainerMigrationPlan(
            tool: 'Rails',
            command: 'bin/rails db:migrate',
            workDir: $workDir,
            source: 'bin/rails with db/migrate',
            environment: ['RAILS_ENV' => 'production'],
        );
    }

    /**
     * Laravel keeps artisan wherever the project root ended up, which is not
     * always the mount root: a repository with the API under backend/ is
     * common enough that Doctor probes for it too.
     */
    private function laravelPlan(SSHService $ssh, string $hostAppPath, string $hostRoot, string $root): ?ContainerMigrationPlan
    {
        $candidates = [$hostRoot => $root, $hostAppPath => '', $hostAppPath.'/backend' => 'backend'];

        foreach ($candidates as $path => $relative) {
            if (! $this->exists($ssh, $path.'/artisan')) {
                continue;
            }

            return new ContainerMigrationPlan(
                tool: 'Laravel',
                command: 'php artisan migrate --force',
                workDir: $relative === '' ? '/app' : '/app/'.$relative,
                source: ($relative === '' ? '' : $relative.'/').'artisan',
            );
        }

        return null;
    }

    private function countTables(Service $service, ContainerDeployment $deployment, SSHService $ssh): ?int
    {
        $deployments = app(ContainerDeploymentService::class);
        $type = $deployments->resolveDatabaseTemplateForService($service)?->type;
        if (! is_string($type) || $type === '') {
            return null;
        }

        $env = is_array($deployment->env_values) ? $deployment->env_values : [];

        try {
            return $deployments->countApplicationDatabaseTables(
                $ssh,
                (string) $deployment->container_name,
                $type,
                app(ContainerDoctorService::class)->envForRuntimeDatabaseProbe($env, $type),
                app(ContainerDoctorService::class)->resolveStackSlug($service),
                ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name,
            );
        } catch (\Throwable) {
            // Evidence, not a gate. A count we cannot take must not stop a run.
            return null;
        }
    }

    private function record(
        Service $service,
        ContainerDeployment $deployment,
        ContainerMigrationPlan $plan,
        ?int $before,
        ?int $after,
    ): void {
        ContainerDeploymentEvent::create([
            'service_id' => $service->id,
            'container_deployment_id' => $deployment->id,
            'event' => 'database_migration_ran',
            'payload' => $plan->toArray() + [
                'tables_before' => $before,
                'tables_after' => $after,
                'ran_in' => $deployment->isRunning() ? 'running container' : 'one-off container',
                'actor_id' => auth()->id(),
            ],
            'recorded_at' => now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function scripts(?string $packageJson): array
    {
        $data = json_decode((string) $packageJson, true);
        $scripts = is_array($data) && is_array($data['scripts'] ?? null) ? $data['scripts'] : [];

        return array_filter($scripts, 'is_string');
    }

    private function dependsOn(?string $packageJson, string $package): bool
    {
        $data = json_decode((string) $packageJson, true);
        if (! is_array($data)) {
            return false;
        }

        return isset($data['dependencies'][$package]) || isset($data['devDependencies'][$package]);
    }

    private function read(SSHService $ssh, string $path): ?string
    {
        $contents = $this->probe($ssh, 'head -c 65536 '.escapeshellarg($path).' 2>/dev/null || true');

        return trim($contents) === '' ? null : $contents;
    }

    private function exists(SSHService $ssh, string $path, string $type = 'f'): bool
    {
        return $this->probe($ssh, 'test -'.$type.' '.escapeshellarg($path).' && echo yes || echo no') === 'yes';
    }

    /**
     * A probe that cannot answer means "not found". Detection must never fail
     * the tab over one unreadable path.
     */
    private function probe(SSHService $ssh, string $command): string
    {
        try {
            return trim($ssh->exec($command, 20));
        } catch (\Throwable) {
            return '';
        }
    }
}
