<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DirectAdminPackage;
use App\Models\Node;
use App\Models\NodeMonitoring;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DirectAdminService;
use App\Services\SSH\SSHService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
                'name' => 'required|string|max:255',
                'hostname' => 'required|string|unique:nodes,hostname',
                'ip_address' => 'required|ip|unique:nodes,ip_address',
                'type' => 'required|in:directadmin',
                'da_port' => 'required|string|max:10',
                'ssh_username' => 'nullable|string|max:100',
                'ssh_password' => 'nullable|string',
                'da_admin_username' => 'required|string|max:255',
                'da_login_key' => 'required|string',
                'nameserver_1' => 'required|string|max:255',
                'nameserver_2' => 'nullable|string|max:255',
                'nameserver_3' => 'nullable|string|max:255',
                'nameserver_4' => 'nullable|string|max:255',
                'region' => 'nullable|string|max:50',
                'datacenter' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'is_active' => 'nullable|boolean',
            ]);
            $validated['status'] = 'offline';
            $validated['cpu_cores'] = 0;
            $validated['ram_gb'] = 0;
            $validated['storage_gb'] = 0;
            $validated['ssh_port'] = $validated['da_port'];
            // DirectAdmin API endpoints are accessed directly on the control panel port (e.g., https://hostname:2222/CMD_SELECT_USERS)
            // Do NOT add /api suffix - DirectAdmin doesn't use /api in the path
            $validated['api_url'] = "https://{$validated['hostname']}:{$validated['da_port']}";
        } elseif ($type === 'container_host') {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'hostname' => 'required|string|unique:nodes,hostname',
                'ip_address' => 'required|ip|unique:nodes,ip_address',
                'type' => 'required|in:container_host',
                'ssh_port' => 'required|string|max:10',
                'ssh_username' => 'required|string|max:100',
                'ssh_password' => 'nullable|string',
                'cpu_cores' => 'required|integer|min:1',
                'ram_gb' => 'required|integer|min:1',
                'storage_gb' => 'required|integer|min:1',
                'nameserver_1' => 'nullable|string|max:255',
                'nameserver_2' => 'nullable|string|max:255',
                'nameserver_3' => 'nullable|string|max:255',
                'nameserver_4' => 'nullable|string|max:255',
                'region' => 'nullable|string|max:50',
                'datacenter' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'is_active' => 'nullable|boolean',
            ]);
            $validated['status'] = 'offline';
            foreach (['nameserver_1', 'nameserver_2', 'nameserver_3', 'nameserver_4'] as $field) {
                $validated[$field] = ! empty($validated[$field]) ? trim((string) $validated[$field]) : null;
            }
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

    public function show(Request $request, Node $node)
    {
        $node->load('services.product', 'services.user', 'latestMonitoring');

        // For DirectAdmin nodes, load the locally-cached package list and a
        // count of sibling DA nodes so the consistency link shows itself only
        // when there's something to compare against.
        $packages = collect();
        $directAdminPeerCount = 0;
        $resellerPackages = [];
        $resellerPackagesError = null;
        $nodeResellers = collect();

        if ($node->type === 'directadmin') {
            $packages = $node->directAdminPackages()
                ->orderBy('disk_quota')
                ->orderBy('name')
                ->get();
            $directAdminPeerCount = Node::where('type', 'directadmin')
                ->where('id', '!=', $node->id)
                ->count();

            $nodeResellers = $this->directAdminResellersForNode($node);
            [$resellerPackages, $resellerPackagesError] = $this->fetchDirectAdminResellerPackages(
                $node,
                $request->boolean('refresh_reseller_packages'),
            );
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
            'directAdminPeerCount',
            'resellerPackages',
            'resellerPackagesError',
            'nodeResellers',
        ));
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    private function fetchDirectAdminResellerPackages(Node $node, bool $refresh = false): array
    {
        $cacheKey = "directadmin:node:{$node->id}:reseller-packages";

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $service = new DirectAdminService($node);

        if (! $service->isConfigured()) {
            return [[], 'DirectAdmin API is not configured for this node.'];
        }

        try {
            $packages = Cache::remember($cacheKey, now()->addMinutes(5), fn () => $service->getAdminResellerPackages());

            return [$packages, null];
        } catch (\Throwable $e) {
            return [[], 'Could not fetch reseller packages: '.$e->getMessage()];
        }
    }

    /**
     * Resellers assigned to this node or with hosting services on it.
     */
    private function directAdminResellersForNode(Node $node)
    {
        $assignedIds = User::query()
            ->where('is_reseller', true)
            ->where('reseller_node_id', $node->id)
            ->pluck('id');

        $serviceResellerIds = Service::query()
            ->where('node_id', $node->id)
            ->whereNotNull('reseller_id')
            ->distinct()
            ->pluck('reseller_id');

        $ids = $assignedIds->merge($serviceResellerIds)->unique()->filter();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $ids)
            ->with('resellerPackage')
            ->withCount([
                'managedServices as node_services_count' => fn ($query) => $query->where('node_id', $node->id),
            ])
            ->orderBy('name')
            ->get()
            ->each(function (User $reseller) use ($assignedIds) {
                $reseller->setAttribute(
                    'node_binding',
                    $assignedIds->contains($reseller->id) ? 'assigned' : 'services_only',
                );
            });
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
            'hostname' => 'required|string|unique:nodes,hostname,'.$node->id,
            'ip_address' => 'required|ip|unique:nodes,ip_address,'.$node->id,
            'type' => 'required|in:dedicated_server,container_host,load_balancer,database_server,directadmin',
            'status' => 'required|in:online,offline,degraded,maintenance',
            'cpu_cores' => 'required|integer|min:1',
            'ram_gb' => 'required|integer|min:1',
            'storage_gb' => 'required|integer|min:1',
            'region' => 'nullable|string|max:50',
            'datacenter' => 'nullable|string|max:255',
            'ssh_port' => 'required|string|max:10',
            'ssh_username' => 'nullable|string|max:100',
            'ssh_password' => 'nullable|string',
            'api_url' => 'nullable|url',
            'api_token' => 'nullable|string',
            'da_admin_username' => 'nullable|string|max:255',
            'da_login_key' => 'nullable|string',
            'nameserver_1' => 'nullable|string|max:255',
            'nameserver_2' => 'nullable|string|max:255',
            'nameserver_3' => 'nullable|string|max:255',
            'nameserver_4' => 'nullable|string|max:255',
            'verify_ssl' => 'nullable|boolean',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['verify_ssl'] = $request->has('verify_ssl');
        $validated['is_active'] = $request->has('is_active');

        if (($validated['type'] ?? $node->type) === 'directadmin') {
            $request->validate([
                'nameserver_1' => 'required|string|max:255',
                'nameserver_2' => 'nullable|string|max:255',
                'nameserver_3' => 'nullable|string|max:255',
                'nameserver_4' => 'nullable|string|max:255',
            ]);
            $validated['nameserver_1'] = trim((string) $request->input('nameserver_1'));
            $validated['nameserver_2'] = $request->filled('nameserver_2') ? trim((string) $request->input('nameserver_2')) : null;
            $validated['nameserver_3'] = $request->filled('nameserver_3') ? trim((string) $request->input('nameserver_3')) : null;
            $validated['nameserver_4'] = $request->filled('nameserver_4') ? trim((string) $request->input('nameserver_4')) : null;
        } elseif (($validated['type'] ?? $node->type) === 'container_host') {
            $validated['nameserver_1'] = $request->filled('nameserver_1') ? trim((string) $request->input('nameserver_1')) : null;
            $validated['nameserver_2'] = $request->filled('nameserver_2') ? trim((string) $request->input('nameserver_2')) : null;
            $validated['nameserver_3'] = $request->filled('nameserver_3') ? trim((string) $request->input('nameserver_3')) : null;
            $validated['nameserver_4'] = $request->filled('nameserver_4') ? trim((string) $request->input('nameserver_4')) : null;
        }

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

        $catalogPackages = DirectAdminPackage::with('node')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('admin.nodes.package-consistency', array_merge($report, [
            'catalogPackages' => $catalogPackages,
        ]));
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
                $service = new DirectAdminService($node);

                if (! $service->isConfigured()) {
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

                if (! $found) {
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
            $service = new DirectAdminService($node);

            if (! $service->isConfigured()) {
                return back()->with('error', 'DirectAdmin API credentials are not configured for this node. Check hostname, API URL, SSH username, and login key/password.');
            }

            $packages = $service->getPackages();

            if (empty($packages) && $packages !== []) {
                return back()->with('error', 'API connection failed or no packages found. Verify credentials and API URL.');
            }

            $node->update(['status' => 'online']);

            $message = 'Connection successful! Node is now online.';
            if (! empty($packages)) {
                $message .= ' Found '.count($packages).' packages.';
            }

            return back()->with('success', $message);
        } catch (\Exception $e) {
            return back()->with('error', 'Connection test failed: '.$e->getMessage());
        }
    }

    public function testHealth(Node $node)
    {
        if (! in_array($node->type, ['container_host', 'database_server', 'directadmin'])) {
            return back()->with('error', 'Node health tests are only available for container hosts, database servers, and DirectAdmin nodes.');
        }

        // Validate SSH credentials are configured
        if (! $node->ssh_username) {
            return back()->with('error', 'SSH username is not configured. Please edit the node and set the SSH username.');
        }

        if (! $node->ssh_password && ! $node->da_login_key) {
            return back()->with('error', 'SSH credentials are not configured. Please edit the node and set either an SSH password or login key.');
        }

        try {
            $ssh = SSHService::forNode($node);

            // Test 1: SSH connectivity
            $ssh->exec('echo "SSH connection OK"', 5);
            $node->recordHeartbeat();

            // Test 2: Collect system metrics
            $uptime = $ssh->exec('uptime -p', 5);
            $freeOutput = $ssh->exec('free -b | grep Mem', 5);
            $dfOutput = $ssh->exec('df /opt/talksasa/containers -B1 | tail -1', 5);
            $cpuOutput = $ssh->exec('grep -c ^processor /proc/cpuinfo', 5);
            $loadOutput = $ssh->exec('cat /proc/loadavg | awk \'{print $1, $2, $3}\'', 5);

            // Parse memory (free -b output: Mem: total used free shared buff/cache available)
            preg_match('/Mem:\s+(\d+)\s+(\d+)\s+(\d+)/', $freeOutput, $memMatches);
            $ramTotalBytes = $memMatches[1] ?? 0;
            $ramUsedBytes = $memMatches[2] ?? 0;
            $ramTotalGb = intval($ramTotalBytes / (1024 * 1024 * 1024));
            $ramUsedGb = intval($ramUsedBytes / (1024 * 1024 * 1024));

            // Parse disk (df output: filesystem 1B-blocks used available use% mounted)
            $dfParts = preg_split('/\s+/', trim($dfOutput));
            $diskTotalBytes = intval($dfParts[1] ?? 0);
            $diskUsedBytes = intval($dfParts[2] ?? 0);
            $diskTotalGb = intval($diskTotalBytes / (1024 * 1024 * 1024));
            $diskUsedGb = intval($diskUsedBytes / (1024 * 1024 * 1024));

            // Get CPU load average (1, 5, 15 min)
            $loads = array_map('floatval', explode(' ', trim($loadOutput)));
            $loadAverage = $loads[0] ?? 0;
            $cpuCores = intval(trim($cpuOutput));
            $cpuPercent = $cpuCores > 0 ? intval(($loadAverage / $cpuCores) * 100) : 0;
            $cpuPercent = min(100, max(0, $cpuPercent));

            // Determine uptime percentage (estimate: 99% if running, 0% if just booted)
            $uptimePercent = strpos($uptime, 'minute') !== false || strpos($uptime, 'hour') !== false ? 99 : 95;

            // Record monitoring data
            NodeMonitoring::create([
                'node_id' => $node->id,
                'uptime_percentage' => $uptimePercent,
                'ram_used_gb' => $ramUsedGb,
                'ram_total_gb' => $ramTotalGb,
                'storage_used_gb' => $diskUsedGb,
                'storage_total_gb' => $diskTotalGb,
                'cpu_percentage' => $cpuPercent,
                'recorded_at' => now(),
            ]);

            // Update node resource utilization and enable monitoring
            $node->update([
                'is_active' => true,
                'ram_gb' => $ramTotalGb,
                'storage_gb' => $diskTotalGb,
                'cpu_cores' => $cpuCores,
                'ram_used_gb' => $ramUsedGb,
                'storage_used_gb' => $diskUsedGb,
                'cpu_used' => $cpuPercent,
            ]);

            // If all metrics look healthy, set status to online
            if ($ramUsedGb <= ($ramTotalGb * 0.85) && $diskUsedGb <= ($diskTotalGb * 0.90)) {
                $node->update(['status' => 'online']);
            }

            $ssh->disconnect();

            $ramPercent = $ramTotalGb > 0 ? intval($ramUsedGb / $ramTotalGb * 100) : 0;
            $storagePercent = $diskTotalGb > 0 ? intval($diskUsedGb / $diskTotalGb * 100) : 0;

            $message = "Node health test passed! ✓\n\n";
            $message .= "📊 Metrics:\n";
            $message .= "  CPU: {$cpuPercent}% ({$cpuCores} cores)\n";
            $message .= "  RAM: {$ramUsedGb}/{$ramTotalGb} GB ({$ramPercent}%)\n";
            if ($diskTotalGb > 0) {
                $message .= "  Storage: {$diskUsedGb}/{$diskTotalGb} GB ({$storagePercent}%)\n";
            } else {
                $message .= "  Storage: Could not determine (path may not exist)\n";
            }
            $message .= "  Uptime: {$uptime}\n";
            $message .= "  Load Average: {$loadAverage}";

            return back()->with('success', $message);
        } catch (\Exception $e) {
            return back()->with('error', 'Node health test failed: '.$e->getMessage());
        }
    }

    public function pushPackageLimits(DirectAdminPackage $package)
    {
        $package->load('node');
        $node = $package->node;

        if (! $node || $node->type !== 'directadmin') {
            return back()->with('error', 'Package is not linked to an active DirectAdmin node.');
        }

        try {
            $service = new DirectAdminService($node);

            if (! $service->isConfigured()) {
                return back()->with('error', 'DirectAdmin API is not configured for this node.');
            }

            $result = $service->ensureUserPackage($package);

            if (! $result['success']) {
                return back()->with('error', "Failed to push limits for {$package->name}: {$result['message']}");
            }

            \Cache::forget('directadmin:package-consistency');

            return back()->with('success', "Pushed package limits for \"{$package->name}\" to {$node->name}.");
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to push package limits: '.$e->getMessage());
        }
    }

    public function syncDirectAdminPackages(Node $node)
    {
        if ($node->type !== 'directadmin') {
            return back()->with('error', 'This node is not a DirectAdmin server.');
        }

        try {
            $service = new DirectAdminService($node);

            if (! $service->isConfigured()) {
                return back()->with('error', 'DirectAdmin API is not configured for this node.');
            }

            $result = $service->syncPackages();

            // Bust the consistency report cache so the next view reflects this sync
            \Cache::forget('directadmin:package-consistency');

            // Check for errors first
            if (! empty($result['errors'])) {
                $errorMsg = implode('; ', $result['errors']);

                return back()->with('error', $errorMsg);
            }

            $message = "Synced: {$result['synced']}, Updated: {$result['updated']}";

            if (($result['deactivated'] ?? 0) > 0) {
                $message .= ", Deactivated: {$result['deactivated']}";
            }

            if ($result['failed'] > 0) {
                $message .= ", Failed: {$result['failed']}";

                return back()->with('warning', $message);
            }

            if ($result['synced'] + $result['updated'] === 0) {
                return back()->with('warning', 'No packages were synced. The DirectAdmin server may not have any packages defined, or the API returned an empty response.');
            }

            return back()->with('success', $message);
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to sync packages: '.$e->getMessage());
        }
    }

    /**
     * Return JSON list of synced packages for a DirectAdmin node
     * Used by AJAX in the Add Service modal for package selection
     */
    public function packagesJson(Node $node)
    {
        if ($node->type !== 'directadmin') {
            abort(404, 'Not a DirectAdmin node');
        }

        $packages = $node->directAdminPackages()
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'package_key',
                'disk_quota',
                'bandwidth_quota',
                'num_domains',
                'num_email_accounts',
                'num_databases',
                'num_ftp',
            ])
            ->map(function ($pkg) {
                return [
                    'id' => (int) $pkg->id,
                    'name' => (string) $pkg->name,
                    'package_key' => (string) $pkg->package_key,
                    'disk_quota' => (float) $pkg->disk_quota,
                    'bandwidth_quota' => (float) $pkg->bandwidth_quota,
                    'num_domains' => (int) $pkg->num_domains,
                    'num_email_accounts' => (int) $pkg->num_email_accounts,
                    'num_databases' => (int) $pkg->num_databases,
                    'num_ftp' => (int) $pkg->num_ftp,
                ];
            });

        return response()->json($packages);
    }

    /**
     * Get node status data for auto-polling on dashboard
     */
    public function statusJson(Request $request)
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
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by region
        if ($request->filled('region')) {
            $query->where('region', $request->region);
        }

        $nodes = $query->withCount('services')->get();

        return response()->json([
            'timestamp' => now()->toIso8601String(),
            'nodes' => $nodes->map(function ($node) {
                return [
                    'id' => $node->id,
                    'name' => $node->name,
                    'hostname' => $node->hostname,
                    'type' => $node->type,
                    'status' => $node->status,
                    'cpu_cores' => $node->cpu_cores,
                    'cpu_used' => $node->cpu_used,
                    'cpu_usage_percentage' => $node->getCpuUsagePercentage(),
                    'ram_gb' => $node->ram_gb,
                    'ram_used_gb' => $node->ram_used_gb,
                    'ram_usage_percentage' => $node->getRamUsagePercentage(),
                    'storage_gb' => $node->storage_gb,
                    'storage_used_gb' => $node->storage_used_gb,
                    'storage_usage_percentage' => $node->getStorageUsagePercentage(),
                    'region' => $node->region,
                    'services_count' => $node->services_count ?? 0,
                    'last_heartbeat_at' => $node->last_heartbeat_at?->toIso8601String(),
                    'last_heartbeat_diff' => $node->last_heartbeat_at?->diffForHumans() ?? 'Never',
                ];
            }),
        ]);
    }
}
