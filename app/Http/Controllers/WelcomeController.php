<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\UserCurrencyService;

class WelcomeController extends Controller
{
    public function index(UserCurrencyService $currencies)
    {
        // Fetch hosting packages (shared_hosting type)
        $packages = Product::where('type', 'shared_hosting')
            ->where('is_active', true)
            ->orderBy('monthly_price')
            ->get();

        $currency = $currencies->model(null);
        $currencyCode = $currency->code;

        return view('welcome', [
            'packages' => $packages,
            'currency' => $currency,
            'currencyCode' => $currencyCode,
        ]);
    }
}
