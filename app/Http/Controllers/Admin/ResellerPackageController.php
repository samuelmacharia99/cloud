<?php

namespace App\Http\Controllers\Admin;

use App\Models\ResellerPackage;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ResellerPackageController extends Controller
{
    public function index(Request $request)
    {
        $cycle = $request->get('cycle', 'monthly');
        $packages = ResellerPackage::where('billing_cycle', $cycle)
            ->orderBy('storage_space')
            ->paginate(15);

        $monthly = ResellerPackage::where('billing_cycle', 'monthly')->count();
        $annually = ResellerPackage::where('billing_cycle', 'annually')->count();

        return view('admin.reseller-packages.index', compact('packages', 'cycle', 'monthly', 'annually'));
    }

    public function create()
    {
        return view('admin.reseller-packages.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:reseller_packages,name',
            'description' => 'nullable|string|max:1000',
            'billing_cycle' => 'required|in:monthly,annually',
            'storage_space' => 'required|integer|min:1|max:10000',
            'max_users' => 'required|integer|min:1|max:1000',
            'price' => 'required|numeric|min:0|max:999999.99',
            'active' => 'boolean',
        ]);

        ResellerPackage::create($validated);

        return redirect()->route('admin.reseller-packages.index')
            ->with('success', "Package '{$validated['name']}' created successfully.");
    }

    public function edit(ResellerPackage $reseller_package)
    {
        return view('admin.reseller-packages.edit', ['package' => $reseller_package]);
    }

    public function update(Request $request, ResellerPackage $reseller_package)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:reseller_packages,name,' . $reseller_package->id,
            'description' => 'nullable|string|max:1000',
            'billing_cycle' => 'required|in:monthly,annually',
            'storage_space' => 'required|integer|min:1|max:10000',
            'max_users' => 'required|integer|min:1|max:1000',
            'price' => 'required|numeric|min:0|max:999999.99',
            'active' => 'boolean',
        ]);

        $reseller_package->update($validated);

        return redirect()->route('admin.reseller-packages.index')
            ->with('success', "Package '{$validated['name']}' updated successfully.");
    }

    public function destroy(ResellerPackage $reseller_package)
    {
        $name = $reseller_package->name;
        $reseller_package->delete();

        return redirect()->route('admin.reseller-packages.index')
            ->with('success', "Package '{$name}' deleted successfully.");
    }
}
