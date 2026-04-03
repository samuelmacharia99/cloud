<?php

namespace App\Http\Controllers\Customer;

use App\Models\Payment;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $payments = Payment::where('user_id', auth()->id())
            ->latest()
            ->paginate(10);

        return view('customer.payments.index', compact('payments'));
    }

    public function show(Payment $payment)
    {
        abort_if($payment->user_id !== auth()->id(), 403);

        $payment->load('invoice');
        return view('customer.payments.show', compact('payment'));
    }
}
