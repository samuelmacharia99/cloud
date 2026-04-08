@extends('emails._layout')

@section('content')
<h1>Order Confirmed</h1>

<p>Hello {{ $user->name }},</p>

<div class="alert alert-success">
    <strong>✓ Thank you for your order!</strong> Your order has been received and is being processed.
</div>

<h2>Order Summary</h2>
<table>
    <tr>
        <td><strong>Order Number:</strong></td>
        <td>{{ $order->order_number }}</td>
    </tr>
    <tr>
        <td><strong>Order Date:</strong></td>
        <td>{{ $order->created_at->format('F d, Y H:i') }}</td>
    </tr>
    <tr>
        <td><strong>Order Status:</strong></td>
        <td>{{ ucfirst($order->status->value) }}</td>
    </tr>
</table>

<h2>Ordered Items</h2>
<table>
    <thead>
        <tr>
            <th>Product</th>
            <th style="text-align: center;">Qty</th>
            <th style="text-align: right;">Unit Price</th>
            <th style="text-align: right;">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach($items as $item)
            <tr>
                <td>
                    <strong>{{ $item->product->name }}</strong>
                    @if($item->description)
                        <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                            {{ $item->description }}
                        </div>
                    @endif
                </td>
                <td style="text-align: center;">{{ $item->quantity }}</td>
                <td style="text-align: right;">Ksh {{ number_format($item->unit_price, 2) }}</td>
                <td style="text-align: right; font-weight: bold;">Ksh {{ number_format($item->amount, 2) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<h2>Order Total</h2>
<table style="margin-top: 20px;">
    <tr>
        <td style="text-align: right; padding-right: 20px;"><strong>Subtotal:</strong></td>
        <td style="text-align: right;">Ksh {{ number_format($order->subtotal ?? 0, 2) }}</td>
    </tr>
    @if(($order->tax ?? 0) > 0)
        <tr>
            <td style="text-align: right; padding-right: 20px;"><strong>Tax ({{ $order->tax_rate ?? 0 }}%):</strong></td>
            <td style="text-align: right;">Ksh {{ number_format($order->tax ?? 0, 2) }}</td>
        </tr>
    @endif
    <tr style="border-top: 2px solid #e5e7eb; font-size: 16px; font-weight: bold;">
        <td style="text-align: right; padding-right: 20px; padding-top: 10px;">Total Amount:</td>
        <td style="text-align: right; padding-top: 10px;">Ksh {{ number_format($order->total, 2) }}</td>
    </tr>
</table>

<h2>Next Steps</h2>
<p>An invoice has been generated for this order. You can:</p>
<ul style="margin: 15px 0; padding-left: 20px;">
    <li>Review your invoice and itemized details</li>
    <li>Proceed to payment whenever you're ready</li>
    <li>Once payment is received, your services will be activated automatically</li>
</ul>

<p style="text-align: center; margin: 30px 0;">
    <a href="{{ route('customer.orders.show', $order) }}" class="cta-button">View Order Details</a>
</p>

<div class="alert alert-info">
    <strong>Payment Methods Available:</strong> M-Pesa, Stripe, PayPal
</div>

<h2>Questions?</h2>
<p>If you have any questions about your order or need help getting started, please don't hesitate to contact our support team. We're here to help!</p>

<p>
    Best regards,<br>
    <strong>{{ \App\Models\Setting::getValue('mail_from_name', 'Talksasa Cloud') }}</strong><br>
    Support Team
</p>
@endsection
