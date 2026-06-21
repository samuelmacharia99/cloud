<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Services\ResellerDirectAdminService;
use App\Services\ResellerInfrastructureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NodeController extends Controller
{
    public function __construct(
        private ResellerDirectAdminService $resellerDirectAdmin,
        private ResellerInfrastructureService $infrastructure,
    ) {}

    public function index(Request $request): View
    {
        $reseller = $request->user();
        $refresh = $request->boolean('refresh');

        return view('reseller.nodes.index', [
            'user' => $reseller,
            'dashboard' => $this->infrastructure->buildDashboard($reseller, $refresh),
        ]);
    }

    public function test(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reseller_node_id' => 'required|integer',
            'directadmin_username' => 'required|string|max:48|regex:/^[a-z][a-z0-9_]*$/i',
        ]);

        $node = $this->resellerDirectAdmin->resolveConnectableNode((int) $validated['reseller_node_id']);

        if (! $node) {
            return response()->json([
                'success' => false,
                'message' => 'Select a valid DirectAdmin server.',
            ], 422);
        }

        $result = $this->resellerDirectAdmin->verifyBinding($node, $validated['directadmin_username']);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function connect(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'reseller_node_id' => 'required|integer',
            'directadmin_username' => 'required|string|max:48|regex:/^[a-z][a-z0-9_]*$/i',
        ]);

        $result = $this->resellerDirectAdmin->connect(
            $request->user(),
            (int) $validated['reseller_node_id'],
            $validated['directadmin_username'],
        );

        if (! $result['success']) {
            return back()
                ->withInput()
                ->with('error', $result['message']);
        }

        return redirect()
            ->route('reseller.nodes.index', ['refresh' => 1])
            ->with('success', $result['message']);
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $this->resellerDirectAdmin->disconnect($request->user());

        return redirect()
            ->route('reseller.nodes.index')
            ->with('success', 'DirectAdmin connection removed. You can reconnect anytime.');
    }
}
