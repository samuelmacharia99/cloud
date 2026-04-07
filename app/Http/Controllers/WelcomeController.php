<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class WelcomeController extends Controller
{
    public function index()
    {
        // Fetch hosting packages (shared_hosting type)
        $packages = Product::where('type', 'shared_hosting')
            ->where('is_active', true)
            ->orderBy('monthly_price')
            ->get();

        return view('welcome', [
            'packages' => $packages,
        ]);
    }
}
