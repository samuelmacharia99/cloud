<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ResellerMarginEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminReportsController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->get('from', now()->subDays(30)->toDateString());
        $to = $request->get('to', now()->toDateString());

        $marginByReseller = ResellerMarginEntry::query()
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->select(
                'reseller_id',
                DB::raw('SUM(retail_amount) as retail_total'),
                DB::raw('SUM(wholesale_amount) as wholesale_total'),
                DB::raw('SUM(margin_amount) as margin_total'),
                DB::raw('COUNT(*) as entry_count'),
            )
            ->groupBy('reseller_id')
            ->orderByDesc('margin_total')
            ->get()
            ->load('reseller');

        $ledgerEntries = ResellerMarginEntry::query()
            ->with(['reseller', 'customer', 'invoice', 'payment'])
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $platformCustomers = User::query()
            ->where('is_admin', false)
            ->where('is_reseller', false)
            ->whereNull('reseller_id')
            ->count();

        $managedCustomers = User::query()
            ->where('is_admin', false)
            ->where('is_reseller', false)
            ->whereNotNull('reseller_id')
            ->count();

        $resellerCount = User::where('is_reseller', true)->count();

        $revenueInPeriod = (float) Payment::query()
            ->where('status', 'completed')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->sum('amount');

        $outstandingTotal = (float) Invoice::query()
            ->whereIn('status', ['unpaid', 'overdue'])
            ->sum('total');

        $totals = [
            'retail_total' => round((float) $marginByReseller->sum('retail_total'), 2),
            'wholesale_total' => round((float) $marginByReseller->sum('wholesale_total'), 2),
            'margin_total' => round((float) $marginByReseller->sum('margin_total'), 2),
            'entry_count' => (int) $marginByReseller->sum('entry_count'),
        ];

        return view('admin.reports.index', compact(
            'from',
            'to',
            'marginByReseller',
            'ledgerEntries',
            'platformCustomers',
            'managedCustomers',
            'resellerCount',
            'revenueInPeriod',
            'outstandingTotal',
            'totals',
        ));
    }
}
