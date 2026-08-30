<?php

namespace App\Services\Provisioning;

use App\Models\ContainerGitPull;

class ContainerGitPullErrorPresenter
{
    /**
     * @return array{title: string, guidance: string, details: string}|null
     */
    public function present(ContainerGitPull $pull): ?array
    {
        if (! in_array($pull->status, [
            ContainerGitPull::STATUS_FAILED,
            ContainerGitPull::STATUS_CANCELLED,
        ], true)) {
            return null;
        }

        $details = trim((string) $pull->error_message);
        if ($details === '') {
            $details = 'No technical error details were recorded.';
        }

        if ($pull->status === ContainerGitPull::STATUS_CANCELLED) {
            return $this->result(
                'Git pull cancelled.',
                'Restart the pull when you are ready to try again.',
                $details,
            );
        }

        $message = strtolower($details);
        $step = $this->failedStepKey($pull);

        if ($this->contains($message, [
            'authentication failed',
            'could not read username',
            'could not read password',
            'invalid username or password',
            'access denied',
            'http 401',
            'http 403',
            'returned error: 401',
            'returned error: 403',
            'terminal prompts disabled',
            'requires authentication',
            'no github access token',
        ]) || ($step === 'sync' && str_contains($message, 'permission denied'))) {
            $guidance = str_contains($message, 'no github access token')
                || str_contains($message, 'requires authentication, but no')
                ? 'Add a GitHub personal access token with read access to this repository on the Git settings form, then retry the pull.'
                : 'Update the personal access token and make sure it can read this repository, then try again.';

            return $this->result(
                'Couldn’t authenticate with the Git host.',
                $guidance,
                $details,
            );
        }

        if ($this->contains($message, [
            'repository not found',
            'does not appear to be a git repository',
        ])) {
            return $this->result(
                'Couldn’t find the Git repository.',
                'Check the repository URL and confirm that the connected account or token has access.',
                $details,
            );
        }

        if ($this->contains($message, [
            'couldn\'t find remote ref',
            'could not find remote ref',
            'remote branch',
            'pathspec',
            'invalid branch',
        ])) {
            return $this->result(
                'Couldn’t find the selected branch.',
                'Check the branch name in the repository settings and try again.',
                $details,
            );
        }

        if ($this->contains($message, [
            'could not resolve host',
            'connection timed out',
            'connection reset',
            'unable to connect',
            'failed to connect',
            'network is unreachable',
            'temporary failure in name resolution',
        ])) {
            return $this->result(
                'Couldn’t reach the Git host.',
                'Check the repository host and try again in a few minutes.',
                $details,
            );
        }

        if ($this->contains($message, ['no space left on device', 'disk quota exceeded'])) {
            return $this->result(
                'The server ran out of storage during the pull.',
                'Free some storage or increase the service capacity, then restart the pull.',
                $details,
            );
        }

        if ($step === 'composer' || $this->contains($message, ['composer install', 'composer dependencies'])) {
            return $this->result(
                'PHP dependencies could not be installed.',
                'Review the Composer error below, fix composer.json or its credentials, then restart the pull.',
                $details,
            );
        }

        if ($step === 'migrations' || $this->contains($message, ['migration failed', 'artisan migrate'])) {
            return $this->result(
                'Database migrations failed.',
                'Review the migration error below, correct the application or database configuration, then restart the pull.',
                $details,
            );
        }

        if (in_array($step, ['frontend', 'post_pull'], true)
            || $this->contains($message, ['npm run build', 'npm install', 'npm ci', 'yarn build', 'pnpm', 'post-pull step failed'])) {
            if ($this->contains($message, [
                'ebadengine',
                'unsupported engine',
                "required: { node: '>=22",
                'required: { node: ">=22',
                'node >= 22',
                "node: '>=22",
            ])) {
                return $this->result(
                    'The application needs a newer Node.js version.',
                    'This service is running Node 20, but a dependency requires Node 22 or newer. Redeploy the app with Node 22 selected, then restart the pull.',
                    $details,
                );
            }

            $hasUseServer = $this->contains($message, ['use server', '"use server"'])
                && $this->contains($message, ['can only export async functions', 'invalid-use-server-value']);
            $hasPrismaMusl = $this->contains($message, ['prismaclientinitializationerror', 'prisma client could not locate'])
                && $this->contains($message, ['linux-musl', 'binarytargets']);

            if ($hasUseServer && $hasPrismaMusl) {
                $route = $this->nextPageDataRoute($details);

                return $this->result(
                    'The Next.js build failed on Prisma and a server action.',
                    'Retry the pull so Alpine OpenSSL is installed before Prisma generate. Also fix the "use server" file'
                    .($route !== null ? ' used by '.$route : '')
                    .': it exported an object, but Next.js only allows async function exports. Push that fix, then restart the pull.',
                    $details,
                );
            }

            if ($hasUseServer) {
                $route = $this->nextPageDataRoute($details);

                return $this->result(
                    'Next.js rejected a server action export.',
                    'A file marked "use server"'
                    .($route !== null ? ' (used by '.$route.')' : '')
                    .' exported an object instead of async functions. Export only async functions from that file, push the fix, then restart the pull.',
                    $details,
                );
            }

            if ($this->contains($message, ['unknown binarytarget'])) {
                return $this->result(
                    'Prisma generate rejected an invalid engine target.',
                    'Retry the pull. Talksasa regenerates Prisma Client from schema.prisma after installing Alpine OpenSSL, without passing "native" (that keyword is only valid inside the schema). If it still fails, add both "linux-musl" and "linux-musl-openssl-3.0.x" to binaryTargets, commit, and retry.',
                    $details,
                );
            }

            if ($hasPrismaMusl) {
                return $this->result(
                    'Prisma could not load its query engine on Alpine.',
                    'Retry the pull. Talksasa now installs OpenSSL in the Alpine build sidecar and regenerates Prisma Client so linux-musl-openssl-3.0.x is available. If it still fails, add both "linux-musl" and "linux-musl-openssl-3.0.x" to binaryTargets in schema.prisma, commit, and retry.',
                    $details,
                );
            }

            return $this->result(
                'The application build failed.',
                'Review the build output below, fix the dependency or build error, then restart the pull.',
                $details,
            );
        }

        if ($step === 'health' || $this->contains($message, ['health check', 'container is not running'])) {
            return $this->result(
                'The updated application did not become healthy.',
                'Review the runtime output below and check the application’s startup configuration before trying again.',
                $details,
            );
        }

        $label = $this->failedStepLabel($pull);

        return $this->result(
            $label ? "{$label} did not complete." : 'The Git pull did not complete.',
            'Review the technical details below, correct the reported problem, then restart the pull.',
            $details,
        );
    }

    /**
     * @return array{title: string, guidance: string, details: string}
     */
    private function result(string $title, string $guidance, string $details): array
    {
        return compact('title', 'guidance', 'details');
    }

    /**
     * @param  list<string>  $needles
     */
    private function contains(string $message, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function nextPageDataRoute(string $details): ?string
    {
        if (preg_match('/Failed to collect page data for (\/[^\s]+)/', $details, $matches) !== 1) {
            return null;
        }

        $route = rtrim((string) $matches[1], '.');

        return $route !== '' ? $route : null;
    }

    private function failedStepKey(ContainerGitPull $pull): ?string
    {
        return $this->failedStep($pull)['key'] ?? null;
    }

    private function failedStepLabel(ContainerGitPull $pull): ?string
    {
        return $this->failedStep($pull)['label'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function failedStep(ContainerGitPull $pull): array
    {
        foreach (is_array($pull->steps) ? $pull->steps : [] as $step) {
            if (($step['status'] ?? null) === 'failed') {
                return $step;
            }
        }

        return [];
    }
}
