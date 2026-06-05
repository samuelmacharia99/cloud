<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\Hosting\CustomerHostingPanelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class HostingPanelController extends Controller
{
    public function __construct(private CustomerHostingPanelService $panel) {}

    public function panelLogin(Service $service): RedirectResponse
    {
        $this->authorize('manageHostingPanel', $service);

        try {
            $result = $this->panel->createPanelLoginUrl($service);
            if (! ($result['success'] ?? false) || empty($result['url'])) {
                return redirect()
                    ->route('customer.services.show', $service)
                    ->with('error', $result['message'] ?? 'Unable to create control panel login.');
            }

            return redirect()->away($result['url']);
        } catch (RuntimeException $e) {
            return redirect()
                ->route('customer.services.show', $service)
                ->with('error', $e->getMessage());
        }
    }

    public function dashboard(Request $request, Service $service): JsonResponse
    {
        if ($request->boolean('refresh')) {
            $this->panel->flushDashboardCache($service);
        }

        return $this->jsonAction($service, fn () => [
            'success' => true,
            'message' => 'OK',
            'data' => $this->panel->dashboard($service),
        ]);
    }

    public function dnsIndex(Service $service): JsonResponse
    {
        return $this->jsonAction($service, function () use ($service) {
            $domain = $this->requireDomain($service);

            return $this->panel->api($service)->listDnsRecords($this->panel->username($service), $domain);
        });
    }

    public function dnsStore(Request $request, Service $service): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:253'],
            'type' => ['required', 'in:A,AAAA,CNAME,MX,TXT,NS,SRV,CAA'],
            'value' => ['required', 'string', 'max:2048'],
            'ttl' => ['nullable', 'integer', 'min:60', 'max:86400'],
        ]);

        return $this->jsonAction($service, function () use ($service, $data) {
            $domain = $this->requireDomain($service);

            return $this->panel->api($service)->addDnsRecord(
                $this->panel->username($service),
                $domain,
                $data['name'],
                $data['type'],
                $data['value'],
                (int) ($data['ttl'] ?? 3600),
            );
        }, true);
    }

    public function dnsDestroy(Request $request, Service $service): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:253'],
            'type' => ['required', 'string', 'max:20'],
            'value' => ['required', 'string', 'max:2048'],
        ]);

        return $this->jsonAction($service, function () use ($service, $data) {
            $domain = $this->requireDomain($service);

            return $this->panel->api($service)->deleteDnsRecord(
                $this->panel->username($service),
                $domain,
                $data['name'],
                $data['type'],
                $data['value'],
            );
        }, true);
    }

    public function emailsIndex(Service $service): JsonResponse
    {
        return $this->jsonAction($service, function () use ($service) {
            $domain = $this->requireDomain($service);

            return $this->panel->api($service)->listEmailAccounts($this->panel->username($service), $domain);
        });
    }

    public function emailsStore(Request $request, Service $service): JsonResponse
    {
        $data = $request->validate([
            'local_part' => ['required', 'regex:/^[a-z0-9._-]+$/i', 'max:64'],
            'password' => ['required', 'string', 'min:8', 'max:64'],
            'quota_mb' => ['nullable', 'integer', 'min:10', 'max:10240'],
        ]);

        return $this->jsonAction($service, function () use ($service, $data) {
            $domain = $this->requireDomain($service);

            return $this->panel->api($service)->createEmailAccount(
                $this->panel->username($service),
                $domain,
                strtolower($data['local_part']),
                $data['password'],
                (int) ($data['quota_mb'] ?? 250),
            );
        }, true);
    }

    public function emailsDestroy(Request $request, Service $service): JsonResponse
    {
        $data = $request->validate([
            'local_part' => ['required', 'regex:/^[a-z0-9._-]+$/i', 'max:64'],
        ]);

        return $this->jsonAction($service, function () use ($service, $data) {
            $domain = $this->requireDomain($service);

            return $this->panel->api($service)->deleteEmailAccount(
                $this->panel->username($service),
                $domain,
                strtolower($data['local_part']),
            );
        }, true);
    }

    public function databasesIndex(Service $service): JsonResponse
    {
        return $this->jsonAction($service, fn () => $this->panel->api($service)->listDatabases($this->panel->username($service)));
    }

    public function databasesStore(Request $request, Service $service): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'regex:/^[a-z0-9_]+$/i', 'max:48'],
            'password' => ['required', 'string', 'min:8', 'max:64'],
        ]);

        return $this->jsonAction($service, fn () => $this->panel->api($service)->createDatabase(
            $this->panel->username($service),
            strtolower($data['name']),
            $data['password'],
        ), true);
    }

    public function databasesDestroy(Request $request, Service $service): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'regex:/^[a-z0-9_]+$/i', 'max:48'],
        ]);

        return $this->jsonAction($service, fn () => $this->panel->api($service)->deleteDatabase(
            $this->panel->username($service),
            strtolower($data['name']),
        ), true);
    }

    public function subdomainsIndex(Service $service): JsonResponse
    {
        return $this->jsonAction($service, function () use ($service) {
            $domain = $this->requireDomain($service);

            return $this->panel->api($service)->listSubdomains($this->panel->username($service), $domain);
        });
    }

    public function subdomainsStore(Request $request, Service $service): JsonResponse
    {
        $data = $request->validate([
            'subdomain' => ['required', 'regex:/^[a-z0-9-]+$/i', 'max:63'],
        ]);

        return $this->jsonAction($service, function () use ($service, $data) {
            $domain = $this->requireDomain($service);

            return $this->panel->api($service)->createSubdomain(
                $this->panel->username($service),
                $domain,
                strtolower($data['subdomain']),
            );
        }, true);
    }

    public function subdomainsDestroy(Request $request, Service $service): JsonResponse
    {
        $data = $request->validate([
            'subdomain' => ['required', 'string', 'max:253'],
        ]);

        return $this->jsonAction($service, function () use ($service, $data) {
            $domain = $this->requireDomain($service);
            $api = $this->panel->api($service);
            $subdomain = $api->normalizeSubdomainLabel($data['subdomain'], $domain);

            return $api->deleteSubdomain(
                $this->panel->username($service),
                $domain,
                $subdomain,
            );
        }, true);
    }

    public function ftpIndex(Service $service): JsonResponse
    {
        return $this->jsonAction($service, function () use ($service) {
            $domain = $this->requireDomain($service);

            return $this->panel->api($service)->listFtpAccounts($this->panel->username($service), $domain);
        });
    }

    public function ftpStore(Request $request, Service $service): JsonResponse
    {
        $data = $request->validate([
            'user' => ['required', 'regex:/^[a-z0-9._-]+$/i', 'max:64'],
            'password' => ['required', 'string', 'min:8', 'max:64'],
            'path' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->jsonAction($service, function () use ($service, $data) {
            $domain = $this->requireDomain($service);

            return $this->panel->api($service)->createFtpAccount(
                $this->panel->username($service),
                $domain,
                strtolower($data['user']),
                $data['password'],
                $data['path'] ?? '/',
            );
        }, true);
    }

    public function ftpDestroy(Request $request, Service $service): JsonResponse
    {
        $data = $request->validate([
            'user' => ['required', 'string', 'max:64'],
        ]);

        return $this->jsonAction($service, function () use ($service, $data) {
            $domain = $this->requireDomain($service);

            return $this->panel->api($service)->deleteFtpAccount(
                $this->panel->username($service),
                $domain,
                $data['user'],
            );
        }, true);
    }

    public function sslShow(Service $service): JsonResponse
    {
        return $this->jsonAction($service, function () use ($service) {
            $domain = $this->requireDomain($service);

            return $this->panel->api($service)->getSslInfo($this->panel->username($service), $domain);
        });
    }

    public function sslLetsEncrypt(Service $service): JsonResponse
    {
        return $this->jsonAction($service, function () use ($service) {
            $domain = $this->requireDomain($service);

            return $this->panel->api($service)->requestLetsEncrypt($this->panel->username($service), $domain);
        }, true);
    }

    public function cronIndex(Service $service): JsonResponse
    {
        return $this->jsonAction($service, function () use ($service) {
            return $this->panel->api($service)->listCronJobs($this->panel->username($service));
        });
    }

    public function cronStore(Request $request, Service $service): JsonResponse
    {
        $data = $request->validate([
            'minute' => ['required', 'string', 'max:16'],
            'hour' => ['required', 'string', 'max:16'],
            'day' => ['required', 'string', 'max:16'],
            'month' => ['required', 'string', 'max:16'],
            'weekday' => ['required', 'string', 'max:16'],
            'command' => ['required', 'string', 'max:2048'],
        ]);

        return $this->jsonAction($service, function () use ($service, $data) {
            return $this->panel->api($service)->createCronJob(
                $this->panel->username($service),
                $data['minute'],
                $data['hour'],
                $data['day'],
                $data['month'],
                $data['weekday'],
                $data['command'],
            );
        }, true);
    }

    public function cronDestroy(Request $request, Service $service): JsonResponse
    {
        $data = $request->validate([
            'cron_id' => ['required', 'string', 'max:64'],
        ]);

        return $this->jsonAction($service, function () use ($service, $data) {
            return $this->panel->api($service)->deleteCronJob(
                $this->panel->username($service),
                $data['cron_id'],
            );
        }, true);
    }

    public function backupsIndex(Service $service): JsonResponse
    {
        return $this->jsonAction($service, function () use ($service) {
            $domain = $this->requireDomain($service);

            return $this->panel->api($service)->listBackups($this->panel->username($service), $domain);
        });
    }

    public function backupsStore(Service $service): JsonResponse
    {
        return $this->jsonAction($service, function () use ($service) {
            $domain = $this->requireDomain($service);

            return $this->panel->api($service)->createBackup($this->panel->username($service), $domain);
        }, true);
    }

    public function resetPassword(Service $service): JsonResponse
    {
        $this->authorize('manageHostingPanel', $service);

        try {
            $result = $this->panel->resetHostingPassword($service);

            return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     */
    private function jsonAction(Service $service, callable $callback, bool $flushDashboardCache = false): JsonResponse
    {
        $this->authorize('manageHostingPanel', $service);

        try {
            $result = $callback();

            if ($flushDashboardCache && ($result['success'] ?? false)) {
                $this->panel->flushDashboardCache($service);
            }

            return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    private function requireDomain(Service $service): string
    {
        $domain = $this->panel->domain($service);
        if ($domain === '') {
            throw new RuntimeException('Primary domain is not configured for this hosting account.');
        }

        return $domain;
    }
}
