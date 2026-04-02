<?php

namespace App\Http\Controllers\Customer;

use App\Models\Service;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ServiceController extends Controller
{
    public function index()
    {
        $services = auth()->user()->services()->with('product')->latest()->get();
        return view('customer.services.index', compact('services'));
    }

    public function show(Service $service)
    {
        // Ensure customer can only view their own services
        if ($service->user_id !== auth()->id()) {
            abort(403);
        }

        $service->load(['product', 'invoice']);
        return view('customer.services.show', compact('service'));
    }
}
