<?php

namespace App\Http\Controllers\Admin;

use App\Models\Node;
use App\Models\Service;
use App\Models\NodeMonitoring;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class NodeController extends Controller
{
    public function index(Request $request)
    {
        $query = Node::query();

        // Search by name or hostname
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('hostname', 'like', "%{$request->search}%")
                  ->orWhere('ip_address', 'like', "%{$request->search}%");
            });
        }

        // Filter by type
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Filter by status
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by region
        if ($request->filled('region')) {
            $query->where('region', $request->region);
        }

        // Filter by active status
        if ($request->filled('active')) {
            if ($request->active === 'active') {
                $query->where('is_active', true);
            } elseif ($request->active === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $nodes = $query->withCount('services')
                      ->latest()
                      ->paginate(15)
                      ->withQueryString();

        // Get distinct regions for filter
        $regions = Node::distinct()->pluck('region')->filter()->sort();
        $types = ['dedicated_server', 'container_host', 'load_balancer', 'database_server'];
        $statuses = ['online', 'offline', 'degraded', 'maintenance'];

        // Calculate summary stats
        $stats = [
            'total' => Node::count(),
            'online' => Node::where('status', 'online')->count(),
            'offline' => Node::where('status', 'offline')->count(),
            'container_hosts' => Node::where('type', 'container_host')->count(),
        ];

        return view('admin.nodes.index', compact('nodes', 'regions', 'types', 'statuses', 'stats'));
    }

    public function create()
    {
        $regions = Node::distinct()->pluck('region')->filter()->sort();
        $types = [
            'dedicated_server' => 'Dedicated Server',
            'container_host' => 'Container Host',
            'load_balancer' => 'Load Balancer',
            'database_server' => 'Database Server',
            'directadmin' => 'DirectAdmin Server',
        ];
        $type = request('type', '');

        return view('admin.nodes.create', compact('regions', 'types', 'type'));
    }

    public function store(Request $request)
    {
        $type = $request->input('type');

        if ($type === 'directadmin') {
            $validated = $request->validate([
                'name'           => 'required|string|max:255',
                'hostname'       => 'required|string|unique:nodes,hostname',
                'ip_address'     => 'required|ip|unique:nodes,ip_address',
                'type'           => 'required|in:directadmin',
                'da_port'        => 'required|string|max:10',
                'ssh_username'   => 'required|string|max:100',
                'ssh_password'   => 'nullable|string',
                'da_login_key'   => 'nullable|string',
                'region'         => 'nullable|string|max:50',
                'datacenter'     => 'nullable|string|max:255',
                'description'    => 'nullable|string',
                'is_active'      => 'nullable|boolean',
            ]);
            $validated['status'] = 'offline';
            $validated['cpu_cores'] = 0;
            $validated['ram_gb'] = 0;
            $validated['storage_gb'] = 0;
            $validated['ssh_port'] = $validated['da_port'];
        } elseif ($type === 'container_host') {
            $validated = $request->validate([
                'name'           => 'required|string|max:255',
                'hostname'       => 'required|string|unique:nodes,hostname',
                'ip_address'     => 'required|ip|unique:nodes,ip_address',
                'type'           => 'required|in:container_host',
                'ssh_port'       => 'required|string|max:10',
                'ssh_username'   => 'required|string|max:100',
                'ssh_password'   => 'nullable|string',
                'cpu_cores'      => 'required|integer|min:1',
                'ram_gb'         => 'required|integer|min:1',
                'storage_gb'     => 'required|integer|min:1',
                'region'         => 'nullable|string|max:50',
                'datacenter'     => 'nullable|string|max:255',
                'description'    => 'nullable|string',
                'is_active'      => 'nullable|boolean',
            ]);
            $validated['status'] = 'offline';
        } else {
            // Fallback: generic node type (existing behavior)
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'hostname' => 'required|string|unique:nodes,hostname',
                'ip_address' => 'required|ip|unique:nodes,ip_address',
                'type' => 'required|in:dedicated_server,container_host,load_balancer,database_server,directadmin',
                'status' => 'required|in:online,offline,degraded,maintenance',
                'cpu_cores' => 'required|integer|min:1',
                'ram_gb' => 'required|integer|min:1',
                'storage_gb' => 'required|integer|min:1',
                'region' => 'nullable|string|max:50',
                'datacenter' => 'nullable|string|max:255',
                'ssh_port' => 'required|string|max:10',
                'api_url' => 'nullable|url',
                'api_token' => 'nullable|string',
                'verify_ssl' => 'nullable|boolean',
                'description' => 'nullable|string',
                'is_active' => 'nullable|boolean',
            ]);
            $validated['verify_ssl'] = $request->has('verify_ssl');
        }

        $validated['is_active'] = $request->has('is_active');

        Node::create($validated);

        return redirect()->route('admin.nodes.index')
                       ->with('success', 'Node created successfully.');
    }

    public function show(Node $node)
    {
        $node->load('services.product', 'services.user', 'latestMonitoring');

        // For DirectAdmin nodes, load the locally-cached package list and a
        // count of sibling DA nodes so the consistency link shows itself only
        // when there's something to compare against.
        $packages = collect();
        $directAdminPeerCount = 0;
        if ($node->type === 'directadmin') {
            $packages = $node->directAdminPackages()
                ->orderBy('disk_quota')
                ->orderBy('name')
                ->get();
            $directAdminPeerCount = Node::where('type', 'directadmin')
                ->where('id', '!=', $node->id)
                ->count();
        }

        // Calculate utilization percentages
        $cpuPercentage = $node->getCpuUsagePercentage();
        $ramPercentage = $node->getRamUsagePercentage();
        $storagePercentage = $node->getStorageUsagePercentage();

        return view('admin.nodes.show', compact(
            'node',
            'cpuPercentage',
            'ramPercentage',
            'storagePercentage',
            'packages',
            'directAdminPeerCount'
        ));
    }

    public function edit(Node $node)
    {
        $regions = Node::distinct()->pluck('region')->filter()->sort();
        $types = [
            'dedicated_server' => 'Dedicated Server',
            'container_host' => 'Container Host',
            'load_balancer' => 'Load Balancer',
            'database_server' => 'Database Server',
        ];

        return view('admin.nodes.edit', compact('node', 'regions', 'types'));
    }

    public function update(Request $request, Node $node)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'hostname' => 'required|string|unique:nodes,hostname,' . $node->id,
            'ip_address' => 'required|ip|unique:nodes,ip_address,' . $node->id,
            'type' => 'required|in:dedicated_server,container_host,load_balancer,database_server',
            'status' => 'required|in:online,offline,degraded,maintenance',
            'cpu_cores' => 'required|integer|min:1',
            'ram_gb' => 'required|integer|min:1',
            'storage_gb' => 'required|integer|min:1',
            'region' => 'nullable|string|max:50',
            'datacenter' => 'nullable|string|max:255',
            'ssh_port' => 'required|string|max:10',
            'api_url' => 'nullable|url',
            'api_token' => 'nullable|string',
            'verify_ssl' => 'nullable|boolean',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['verify_ssl'] = $request->has('verify_ssl');
        $validated['is_active'] = $request->has('is_active');

        $node->update($validated);

        return redirect()->route('admin.nodes.show', $node)
                       ->with('success', 'Node updated successfully.');
    }

    public function delete(Node $node)
    {
        // Check if node has active services
        if ($node->services()->count() > 0) {
            return back()->with('error', 'Cannot delete node with active services. Remove all services first.');
        }

        $name = $node->name;
        $node->delete();

        return redirect()->route('admin.nodes.index')
                       ->with('success', "Node \"$name\" deleted successfully.");
    }

    public function updateStatus(Request $request, Node $node)
    {
        $validated = $request->validate([
            'status' => 'required|in:online,offline,degraded,maintenance',
        ]);

        $node->update($validated);

        return back()->with('success', "Node status updated to {$validated['status']}.");
    }

    public function updateUtilization(Request $request, Node $node)
    {
        $validated = $request->validate([
            'cpu_used' => 'required|integer|min:0|max:100',
            'ram_used_gb' => 'required|integer|min:0',
            'storage_used_gb' => 'required|integer|min:0',
        ]);

        $node->updateUtilization(
            $validated['cpu_used'],
            $validated['ram_used_gb'],
            $validated['storage_used_gb']
        );

        return response()->json([
            'success' => true,
            'message' => 'Utilization updated successfully.',
        ]);
    }

    public function heartbeat(Request $request, Node $node)
    {
        $node->recordHeartbeat();

        // Only monitor container_host and database_server nodes
        if ($node->isMonitored()) {
            $validated = $request->validate([
                'uptime_percentage' => 'nullable|integer|min:0|max:100',
                'ram_used_gb' => 'nullable|integer|min:0',
                'ram_total_gb' => 'nullable|integer|min:1',
                'storage_used_gb' => 'nullable|integer|min:0',
                'storage_total_gb' => 'nullable|integer|min:1',
                'cpu_percentage' => 'nullable|integer|min:0|max:100',
            ]);

            // Only record if we have monitoring data
            if (array_filter($validated)) {
                $monitoring = NodeMonitoring::create([
                    'node_id' => $node->id,
                    'uptime_percentage' => $validated['uptime_percentage'] ?? 100,
                    'ram_used_gb' => $validated['ram_used_gb'] ?? 0,
                    'ram_total_gb' => $validated['ram_total_gb'] ?? $node->ram_gb,
                    'storage_used_gb' => $validated['storage_used_gb'] ?? 0,
                    'storage_total_gb' => $validated['storage_total_gb'] ?? $node->storage_gb,
                    'cpu_percentage' => $validated['cpu_percentage'] ?? null,
                ]);

                // Auto-degrade status if thresholds exceeded
                $ram_pct = $monitoring->getRamUsagePercentage();
                $storage_pct = $monitoring->getStorageUsagePercentage();
                $uptime = $monitoring->uptime_percentage;

                $should_degrade = $ram_pct > 85 || $storage_pct > 90 || $uptime < 95;

                if ($should_degrade && $node->status !== 'degraded') {
                    $node->update(['status' => 'degraded']);
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Heartbeat recorded.',
        ]);
    }

    /**
     * Live cross-node consistency report for DirectAdmin packages.
     *
     * Fetches packages from each active DA node's API in real time so the
     * matrix isn't poisoned by the local cache (which today has unique
     * constraints on package_key — see DirectAdminService::syncPackages).
     *
     * Result is cached for 5 minutes; ?refresh=1 busts the cache.
     */
    public function packageConsistency(Request $request)
    {
        $refresh = $request->boolean('refresh');
        $cacheKey = 'directadmin:package-consistency';

        if ($refresh) {
            \Cache::forget($cacheKey);
        }

        $report = \Cache::remember($cacheKey, now()->addMinutes(5), function () {
            return $this->buildConsistencyReport();
        });

        return view('admin.nodes.package-consistency', $report);
    }

    /**
     * Build the consistency report by hitting every DA node's API.
     *
     * Returns: [
     *   'nodes' => Collection<Node>,
     *   'rows'  => array of ['key' => string, 'name' => string, 'cells' => [node_id => cell]],
     *   'errors' => [node_id => string],
     *   'generated_at' => Carbon,
     * ]
     */
    private function buildConsistencyReport(): array
    {
        $nodes = Node::where('type', 'directadmin')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $packagesByNode = [];
        $errors = [];

        foreach ($nodes as $node) {
            try {
                $service = new \App\Services\Provisioning\DirectAdminService($node);

                if (!$service->isConfigured()) {
                    $errors[$node->id] = 'DirectAdmin API is not configured for this node.';
                    $packagesByNode[$node->id] = null;
                    continue;
                }

                $fetched = $service->getPackages();
                $packagesByNode[$node->id] = collect($fetched)
                    ->keyBy('package_key')
                    ->all();
            } catch (\Throwable $e) {
                $errors[$node->id] = $e->getMessage();
                $packagesByNode[$node->id] = null;
            }
        }

        // Collect every package_key seen across all nodes
        $allKeys = collect($packagesByNode)
            ->filter()
            ->flatMap(fn ($pkgs) => array_keys($pkgs))
            ->unique()
            ->sort()
            ->values()
            ->all();

        // Fields whose mismatch means the package is "different" between nodes
        $compareFields = [
            'disk_quota',
            'bandwidth_quota',
            'num_domains',
            'num_ftp',
            'num_email_accounts',
            'num_databases',
            'num_subdomains',
        ];

        $rows = [];
        foreach ($allKeys as $key) {
            $row = ['key' => $key, 'name' => null, 'cells' => []];
            $reference = null;

            foreach ($nodes as $node) {
                $pkgs = $packagesByNode[$node->id];

                if ($pkgs === null) {
                    $row['cells'][$node->id] = ['status' => 'unknown'];
                    continue;
                }

                $found = $pkgs[$key] ?? null;

                if (!$found) {
                    $row['cells'][$node->id] = ['status' => 'missing'];
                    continue;
                }

                $row['name'] = $row['name'] ?? ($found['name'] ?? $key);

                if ($reference === null) {
                    $reference = $found;
                    $row['cells'][$node->id] = ['status' => 'ok', 'pkg' => $found];
                    continue;
                }

                $diffs = [];
                foreach ($compareFields as $field) {
                    $a = $reference[$field] ?? null;
                    $b = $found[$field] ?? null;

                    if (is_numeric($a) && is_numeric($b)) {
                        if ((float) $a !== (float) $b) {
                            $diffs[$field] = ['ref' => $a, 'this' => $b];
                        }
                    } elseif ($a !== $b) {
                        $diffs[$field] = ['ref' => $a, 'this' => $b];
                    }
                }

                $row['cells'][$node->id] = empty($diffs)
                    ? ['status' => 'ok', 'pkg' => $found]
                    : ['status' => 'diff', 'pkg' => $found, 'diffs' => $diffs];
            }

            $rows[] = $row;
        }

        return [
            'nodes' => $nodes,
            'rows' => $rows,
            'errors' => $errors,
            'generated_at' => now(),
        ];
    }

    public function testConnection(Node $node)
    {
        if ($node->type !== 'directadmin') {
            return back()->with('error', 'This node is not a DirectAdmin server.');
        }

        try {
            $service = new \App\Services\Provisioning\DirectAdminService($node);

            if (!$service->isConfigured()) {
                return back()->with('error', 'DirectAdmin API credentials are not configured for this node. Check hostname, API URL, SSH username, and login key/password.');
            }

            $packages = $service->getPackages();

            if (empty($packages) && $packages !== []) {
                return back()->with('error', 'API connection failed or no packages found. Verify credentials and API URL.');
            }

            $node->update(['status' => 'online']);

            $message = "Connection successful! Node is now online.";
            if (!empty($packages)) {
                $message .= " Found " . count($packages) . " packages.";
            }

            return back()->with('success', $message);
        } catch (\Exception $e) {
            return back()->with('error', 'Connection test failed: ' . $e->getMessage());
        }
    }

    public function syncDirectAdminPackages(Node $node)
    {
        if ($node->type !== 'directadmin') {
            return back()->with('error', 'This node is not a DirectAdmin server.');
        }

        try {
            $service = new \App\Services\Provisioning\DirectAdminService($node);

            if (!$service->isConfigured()) {
                return back()->with('error', 'DirectAdmin API is not configured for this node.');
            }

            $result = $service->syncPackages();

            // Bust the consistency report cache so the next view reflects this sync
            \Cache::forget('directadmin:package-consistency');

            $message = "Synced: {$result['synced']}, Updated: {$result['updated']}";

            if ($result['failed'] > 0) {
                $message .= ", Failed: {$result['failed']}";
                return back()->with('warning', $message);
            }

            if ($result['synced'] + $result['updated'] === 0) {
                return back()->with('info', 'No packages to sync.');
            }

            return back()->with('success', $message);
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to sync packages: ' . $e->getMessage());
        }
    }
}
