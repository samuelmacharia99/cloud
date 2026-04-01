<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\Product;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $user = request()->user();
        $services = $user->isAdmin()
            ? Service::with('user', 'product')->paginate(20)
            : $user->services()->with('product')->paginate(20);

        return view('services.index', compact('services'));
    }

    public function show(Service $service)
    {
        $this->authorize('view', $service);

        return view('services.show', compact('service'));
    }

    public function create()
    {
        $products = Product::where('is_active', true)->get();
        $users = \App\Models\User::where('is_admin', false)->get();

        return view('services.create', compact('products', 'users'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'product_id' => 'required|exists:products,id',
            'name' => 'required|string|max:255',
            'billing_cycle' => 'required|in:monthly,quarterly,semi-annual,annual',
            'next_due_date' => 'required|date',
        ]);

        Service::create($validated);

        return redirect()->route('services.index')
            ->with('success', 'Service created successfully.');
    }

    public function edit(Service $service)
    {
        $this->authorize('edit', $service);
        $products = Product::all();

        return view('services.edit', compact('service', 'products'));
    }

    public function update(Request $request, Service $service)
    {
        $this->authorize('update', $service);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'status' => 'required|in:active,suspended,terminated,cancelled',
            'billing_cycle' => 'required|in:monthly,quarterly,semi-annual,annual',
            'next_due_date' => 'required|date',
        ]);

        $service->update($validated);

        return redirect()->route('services.show', $service)
            ->with('success', 'Service updated successfully.');
    }

    public function destroy(Service $service)
    {
        $this->authorize('delete', $service);
        $service->delete();

        return redirect()->route('services.index')
            ->with('success', 'Service deleted successfully.');
    }
}
