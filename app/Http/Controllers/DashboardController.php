<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return $this->adminDashboard();
        }

        return $this->customerDashboard($user);
    }

    private function adminDashboard()
    {
        $data = [
            'totalCustomers' => \App\Models\User::where('is_admin', false)->count(),
            'activeServices' => \App\Models\Service::where('status', 'active')->count(),
            'unpaidInvoices' => \App\Models\Invoice::where('status', 'unpaid')->sum('total'),
            'totalRevenue' => \App\Models\Payment::where('status', 'completed')->sum('amount'),
            'recentInvoices' => \App\Models\Invoice::latest()->take(5)->get(),
            'openTickets' => \App\Models\Ticket::where('status', 'open')->count(),
        ];

        return view('dashboard.admin', $data);
    }

    private function customerDashboard($user)
    {
        $data = [
            'activeServices' => $user->services()->where('status', 'active')->get(),
            'upcomingDueInvoices' => $user->invoices()
                ->where('status', 'unpaid')
                ->orderBy('due_date')
                ->take(5)
                ->get(),
            'outstandingBalance' => $user->getOutstandingBalance(),
            'openTickets' => $user->tickets()->where('status', '!=', 'closed')->get(),
            'domains' => $user->domains()->where('status', 'active')->get(),
        ];

        return view('dashboard.customer', $data);
    }
}
