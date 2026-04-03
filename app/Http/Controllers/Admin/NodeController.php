<?php

namespace App\Http\Controllers\Admin;

use App\Models\Node;
use App\Models\Service;
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
        ];

        return view('admin.nodes.create', compact('regions', 'types'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'hostname' => 'required|string|unique:nodes,hostname',
            'ip_address' => 'required|ip|unique:nodes,ip_address',
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

        Node::create($validated);

        return redirect()->route('admin.nodes.index')
                       ->with('success', 'Node created successfully.');
    }

    public function show(Node $node)
    {
        $node->load('services.product', 'services.user');

        // Calculate utilization percentages
        $cpuPercentage = $node->getCpuUsagePercentage();
        $ramPercentage = $node->getRamUsagePercentage();
        $storagePercentage = $node->getStorageUsagePercentage();

        return view('admin.nodes.show', compact('node', 'cpuPercentage', 'ramPercentage', 'storagePercentage'));
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

        return response()->json([
            'success' => true,
            'message' => 'Heartbeat recorded.',
        ]);
    }
}
