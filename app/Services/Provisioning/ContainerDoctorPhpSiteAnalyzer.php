<?php

namespace App\Services\Provisioning;

/**
 * What a PHP or Laravel site's homepage does after its redirects. The live
 * probe stops at the first status, so a 302 counted as healthy even when
 * it led to the app's installer or a page that does not exist. This follows
 * the redirects the way the WordPress analyzer does and names the outcome.
 */
class ContainerDoctorPhpSiteAnalyzer
{
    /** Paths an application sends visitors to when it believes it is not installed. */
    public const INSTALLER_PATH_PATTERN = '#/(install|installer|setup|install\.php|installer\.php)(?:/|$|\?)#i';

    public function __construct(private ContainerDoctorWordPressAnalyzer $probe) {}

    public function bodyProbeCommand(string $url, ?string $host): string
    {
        return $this->probe->bodyProbeCommand($url, $host);
    }

    /**
     * @return array{status: ?int, final_url: string, final_path: string, redirects: int, snippet: string, installer: bool}
     */
    public function classify(string $raw): array
    {
        $body = $this->probe->classifyBody($raw);
        $finalUrl = (string) $body['url'];
        $path = (string) (parse_url($finalUrl, PHP_URL_PATH) ?? '');
        $query = (string) (parse_url($finalUrl, PHP_URL_QUERY) ?? '');

        return [
            'status' => $body['status'],
            'final_url' => $finalUrl,
            'final_path' => $path !== '' ? $path : '/',
            'redirects' => (int) $body['redirects'],
            'snippet' => (string) $body['snippet'],
            'installer' => $finalUrl !== '' && preg_match(self::INSTALLER_PATH_PATTERN, $path.($query !== '' ? '?'.$query : '')) === 1,
        ];
    }

    /**
     * @param  array{status: ?int, final_url: string, final_path: string, redirects: int, snippet: string, installer: bool}  $page
     * @param  array<string, mixed>  $checks
     * @return list<array<string, mixed>>
     */
    public function findings(array $page, array $checks, string $stack, bool $converted): array
    {
        $status = $page['status'];
        if ($status === null || $status === 0) {
            return [];
        }

        $tableCount = $checks['table_count'] ?? null;
        $dbOk = $checks['db_ok'] ?? null;
        $evidence = array_values(array_filter([
            'final URL: '.$page['final_url'],
            'final status: '.$status,
            'redirects: '.$page['redirects'],
            $tableCount !== null ? 'table_count='.(int) $tableCount : null,
            $dbOk === null ? null : ('db_ok='.($dbOk ? 'yes' : 'no')),
            $page['snippet'] !== '' ? 'page text: '.$page['snippet'] : null,
        ]));

        if ($page['redirects'] >= 5) {
            return [[
                'id' => 'php_redirect_loop',
                'severity' => 'critical',
                'title' => 'The homepage redirects endlessly',
                'summary' => 'After five redirects the site still had not answered. On a pulled site that is usually an https/http mismatch between the URL the app has stored and the proxy in front of it, or a rewrite rule copied from the old host.',
                'evidence' => $evidence,
                'treat_action' => null,
                'treat_label' => null,
                'manual_steps' => [
                    'Check APP_URL in the Environment tab matches the bound domain with https.',
                    'Look for a redirect rule in .htaccess or the app config that forces a scheme or host the container does not serve.',
                ],
                'source' => 'live',
            ]];
        }

        if ($page['installer']) {
            $missing = $status === 404;
            $empty = $tableCount !== null && (int) $tableCount === 0;
            $steps = [];
            if ($empty) {
                $steps[] = 'The database has no tables. Open the Database tab and import the site\'s SQL dump, then reload the site.';
            } elseif ($dbOk === false) {
                $steps[] = 'The app cannot reach its database. Run Repair DB credentials, then reload the site.';
            } else {
                $steps[] = 'The database has tables, so the app is failing its own "installed" check. Look for the credentials it really uses: a config file such as config.php or database.php still pointing at the old host, or a marker file or .env key (for example storage/installed or APP_INSTALLED) that did not come across.';
            }
            if ($missing) {
                $steps[] = 'The installer itself is gone (HTTP 404), which is normal for a site that was already set up on the old host. Do not try to reinstall; restore the data or the marker instead.';
            } else {
                $steps[] = 'The installer is reachable. Do not run it over customer data: it would create a fresh, empty site. Restore the data or the marker instead.';
            }
            $steps[] = 'Run Diagnose again once the site loads.';

            return [[
                'id' => 'php_install_redirect',
                'severity' => 'critical',
                'title' => $missing
                    ? 'The homepage redirects to its installer, which does not exist'
                    : 'The homepage is showing its installer',
                'summary' => 'The application decided it is not installed and sent visitors to '.$page['final_path'].'. '
                    .($missing ? 'That page answers HTTP 404. ' : 'That page is live, so anyone could run it. ')
                    .($empty
                        ? 'Its database has no tables, which is what such apps check first.'
                        : ($dbOk === false
                            ? 'The app cannot connect to its database, which such apps read as "not installed".'
                            : 'Its database has tables, so it is the app\'s own installed marker or a credentials file the platform does not rewrite.'))
                    .($converted ? ' This site was pulled from DirectAdmin; the marker or config it relied on there may not have come across.' : ''),
                'evidence' => $evidence,
                'treat_action' => null,
                'treat_label' => null,
                'manual_steps' => $steps,
                'source' => 'live',
            ]];
        }

        if ($page['redirects'] > 0 && $status === 404) {
            return [[
                'id' => 'php_redirect_to_missing_page',
                'severity' => 'warning',
                'title' => 'The homepage redirects to a page that does not exist',
                'summary' => 'The first response is a redirect, so the plain status looked fine, but it lands on '.$page['final_path'].' which answers HTTP 404. Visitors see a not-found page instead of the site.',
                'evidence' => $evidence,
                'treat_action' => null,
                'treat_label' => null,
                'manual_steps' => [
                    'Open the Logs tab: the access log shows the redirect and the path it asked for.',
                    'Check the app\'s home or default route, and any rewrite copied from the old host.',
                ],
                'source' => 'live',
            ]];
        }

        if ($page['redirects'] > 0 && $status >= 500) {
            return [[
                'id' => 'php_redirect_target_failed',
                'severity' => 'critical',
                'title' => 'The page the homepage redirects to fails with HTTP '.$status,
                'summary' => 'The first response is a redirect, which the plain status check counts as healthy, but '.$page['final_path'].' then fails with a server error. Visitors never see a working page.',
                'evidence' => $evidence,
                'treat_action' => null,
                'treat_label' => null,
                'manual_steps' => [
                    'Open the Logs tab for the PHP error behind the '.$status.'.',
                    'Run Diagnose again after fixing it; the database and runtime checks above apply to that page too.',
                ],
                'source' => 'live',
            ]];
        }

        return [];
    }
}
