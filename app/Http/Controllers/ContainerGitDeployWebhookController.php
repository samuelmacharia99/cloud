<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Services\Provisioning\ContainerAutoDeployService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ContainerGitDeployWebhookController extends Controller
{
    public function __invoke(Request $request, Service $service, ContainerAutoDeployService $autoDeploy): JsonResponse
    {
        try {
            $result = $autoDeploy->handleWebhook($service, $request);

            return response()->json($result, $result['queued'] ? 202 : 200);
        } catch (\InvalidArgumentException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'invalid deploy webhook') ? 401 : 422;

            return response()->json(['error' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            Log::error('Container git deploy webhook failed', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Deploy webhook failed.'], 500);
        }
    }
}
