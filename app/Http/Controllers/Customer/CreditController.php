<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Credit;
use App\Services\CreditService;
use Illuminate\Http\Request;

class CreditController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Credit::forUser($user)->with('payment', 'invoice')->latest();

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $credits = $query->paginate(15)->withQueryString();

        return view('customer.credits.index', [
            'credits' => $credits,
            'availableBalance' => CreditService::getAvailableBalance($user),
            'activeCredits' => CreditService::getActiveCredits($user),
        ]);
    }
}
