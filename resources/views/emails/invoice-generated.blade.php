@extends('emails._layout')

@section('content')
<h1>Invoice Ready for Payment</h1>

<p>Hello {{ $invoice->user->name }},</p>

<p>Your invoice has been generated and is ready for payment. Please review the details below and pay at your earliest convenience.</p>

<h2>Invoice Summary</h2>
<table>
    <tr>
        <td><strong>Invoice Number:</strong></td>
        <td style="font-family: monospace; font-weight: bold;">{{ $invoice->invoice_number }}</td>
    </tr>
    <tr>
        <td><strong>Invoice Date:</strong></td>
        <td>{{ $invoice->created_at->format('F d, Y') }}</td>
    </tr>
    <tr>
        <td><strong>Due Date:</strong></td>
        <td>
            @if($invoice->due_date)
                {{ $invoice->due_date->format('F d, Y') }}
                @if($invoice->due_date < now())
                    <span style="color: #ef4444; font-weight: bold;"> [OVERDUE]</span>
                @elseif($invoice->due_date->diffInDays(now()) <= 7)
                    <span style="color: #f59e0b;"> [Due Soon]</span>
                @endif
            @else
                Upon receipt
            @endif
        </td>
    </tr>
    <tr>
        <td><strong>Amount Due:</strong></td>
        <td style="font-size: 18px; color: #1f2937;"><strong>Ksh {{ number_format($invoice->total, 2) }}</strong></td>
    </tr>
    <tr>
        <td><strong>Status:</strong></td>
        <td>
            @if($invoice->status === 'paid')
                <span style="display: inline-block; padding: 4px 12px; background-color: #dcfce7; color: #166534; border-radius: 4px; font-size: 12px; font-weight: bold;">
                    PAID
                </span>
            @elseif($invoice->status === 'overdue')
                <span style="display: inline-block; padding: 4px 12px; background-color: #fee2e2; color: #991b1b; border-radius: 4px; font-size: 12px; font-weight: bold;">
                    OVERDUE
                </span>
            @else
                <span style="display: inline-block; padding: 4px 12px; background-color: #fef3c7; color: #92400e; border-radius: 4px; font-size: 12px; font-weight: bold;">
                    UNPAID
                </span>
            @endif
        </td>
    </tr>
</table>

<h2>Invoice Items</h2>
<table>
    <thead>
        <tr style="background-color: #f9fafb;">
            <th style="text-align: left;">Description</th>
            <th style="text-align: center;">Qty</th>
            <th style="text-align: right;">Unit Price</th>
            <th style="text-align: right;">Amount</th>
        </tr>
    </thead>
    <tbody>
        @forelse($invoice->items as $item)
            <tr>
                <td>
                    <strong>{{ $item->product->name ?? 'Product' }}</strong>
                    @if($item->description)
                        <div style="font-size: 12px; color: #6b7280;">{{ $item->description }}</div>
                    @endif
                </td>
                <td style="text-align: center;">{{ $item->quantity }}</td>
                <td style="text-align: right;">Ksh {{ number_format($item->unit_price, 2) }}</td>
                <td style="text-align: right;"><strong>Ksh {{ number_format($item->amount, 2) }}</strong></td>
            </tr>
        @empty
            <tr>
                <td colspan="4" style="text-align: center; color: #6b7280;">No items</td>
            </tr>
        @endforelse
    </tbody>
</table>

<h2>Invoice Total</h2>
<table style="margin-top: 20px;">
    @if($invoice->subtotal && $invoice->subtotal != $invoice->total)
        <tr>
            <td style="text-align: right; padding-right: 20px;"><strong>Subtotal:</strong></td>
            <td style="text-align: right;">Ksh {{ number_format($invoice->subtotal, 2) }}</td>
        </tr>
        @if(($invoice->tax ?? 0) > 0)
            <tr>
                <td style="text-align: right; padding-right: 20px;"><strong>Tax:</strong></td>
                <td style="text-align: right;">Ksh {{ number_format($invoice->tax ?? 0, 2) }}</td>
            </tr>
        @endif
    @endif
    <tr style="border-top: 2px solid #e5e7eb;">
        <td style="text-align: right; padding-right: 20px; padding-top: 10px;"><strong style="font-size: 16px;">Total Due:</strong></td>
        <td style="text-align: right; padding-top: 10px;"><strong style="font-size: 18px; color: #1f2937;">Ksh {{ number_format($invoice->total, 2) }}</strong></td>
    </tr>
</table>

<h2>Payment Options</h2>
<p>You can pay this invoice using any of the following methods:</p>
<table style="margin: 20px 0; border: none;">
    <tr style="border: none;">
        <td style="padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px; background-color: #f9fafb; text-align: center;">
            <strong style="color: #22c55e;">M-Pesa</strong><br>
            <span style="font-size: 12px; color: #6b7280;">Fast & Secure</span>
        </td>
        <td style="padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px; background-color: #f9fafb; text-align: center;">
            <strong style="color: #5b21b6;">Stripe</strong><br>
            <span style="font-size: 12px; color: #6b7280;">Cards & Digital</span>
        </td>
        <td style="padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px; background-color: #f9fafb; text-align: center;">
            <strong style="color: #0055b8;">PayPal</strong><br>
            <span style="font-size: 12px; color: #6b7280;">Trusted & Easy</span>
        </td>
    </tr>
</table>

<p style="text-align: center; margin: 30px 0;">
    <a href="{{ route('customer.invoices.show', $invoice) }}" class="cta-button">Pay Invoice Now</a>
</p>

<h2>Need Help?</h2>
<p>If you have any questions about this invoice or need assistance with payment, our support team is available 24/7 to help. Feel free to reply to this email or contact us directly.</p>

<div class="alert alert-info">
    <strong>💡 Pro Tip:</strong> You can download a PDF copy of this invoice from your account dashboard for your records.
</div>

<p>
    Best regards,<br>
    <strong>{{ \App\Models\Setting::getValue('mail_from_name', 'Talksasa Cloud') }}</strong><br>
    Support Team
</p>
@endsection
