<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Services\ResellerScopeService;
use Illuminate\Http\Request;

class CustomerPaymentController extends Controller
{
    public function __construct(
        private ResellerScopeService $scope,
    ) {}

    public function index(Request $request)
    {
        $reseller = auth()->user();

        $query = $this->scope->managedPaymentsQuery($reseller)
            ->with(['invoice.user'])
            ->latest();

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($builder) use ($search) {
                $builder->where('transaction_reference', 'like', "%{$search}%")
                    ->orWhereHas('invoice', fn ($invoice) => $invoice
                        ->where('invoice_number', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($user) => $user
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")));
            });
        }

        $payments = $query->paginate(25)->withQueryString();

        $totalsQuery = $this->scope->managedPaymentsQuery($reseller)->where('status', 'completed');

        return view('reseller.customer-payments.index', [
            'payments' => $payments,
            'totalCollected' => (float) (clone $totalsQuery)->sum('amount'),
            'collected30d' => (float) (clone $totalsQuery)->where('created_at', '>=', now()->subDays(30))->sum('amount'),
        ]);
    }
}
