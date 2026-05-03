<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reseller Wallet Statement</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
        .header h1 { margin: 0; font-size: 24px; }
        .header p { margin: 5px 0; color: #666; }
        .reseller-info { background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .reseller-info h3 { margin: 0 0 10px 0; font-size: 14px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .info-item label { font-weight: bold; font-size: 12px; color: #666; }
        .info-item value { display: block; font-size: 13px; margin-top: 3px; }
        .balance-summary { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px; }
        .balance-card { background: #f0f9ff; padding: 15px; border-left: 4px solid #3b82f6; }
        .balance-card label { font-size: 12px; color: #666; }
        .balance-card value { display: block; font-size: 20px; font-weight: bold; margin-top: 5px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #f5f5f5; padding: 12px; text-align: left; font-size: 12px; font-weight: bold; border-bottom: 1px solid #ddd; }
        td { padding: 10px 12px; font-size: 11px; border-bottom: 1px solid #eee; }
        .type-deposit { color: #10b981; }
        .type-debit { color: #ef4444; }
        .type-refund { color: #3b82f6; }
        .type-adjustment { color: #f59e0b; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 10px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Reseller Wallet Statement</h1>
        <p>Talksasa Cloud Admin Report</p>
        <p>Generated on {{ now()->format('M d, Y') }}</p>
    </div>

    <div class="reseller-info">
        <h3>Reseller Information</h3>
        <div class="info-grid">
            <div class="info-item">
                <label>Name</label>
                <value>{{ $reseller->name }}</value>
            </div>
            <div class="info-item">
                <label>Email</label>
                <value>{{ $reseller->email }}</value>
            </div>
            <div class="info-item">
                <label>Phone</label>
                <value>{{ $reseller->phone ?? 'N/A' }}</value>
            </div>
            <div class="info-item">
                <label>Account Status</label>
                <value>{{ ucfirst($wallet->status) }}</value>
            </div>
        </div>
    </div>

    <div class="balance-summary">
        <div class="balance-card">
            <label>Current Balance</label>
            <value>{{ $wallet->getFormattedBalance() }}</value>
        </div>
        <div class="balance-card" style="border-left-color: #f59e0b; background: #fffbeb;">
            <label>Low Balance Threshold</label>
            <value>KES {{ number_format($wallet->low_balance_threshold, 2) }}</value>
        </div>
    </div>

    <h3 style="margin-bottom: 10px;">Transaction History</h3>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Description</th>
                <th>Amount</th>
                <th>Balance After</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transactions as $transaction)
            <tr>
                <td>{{ $transaction->created_at->format('M d, Y H:i') }}</td>
                <td class="type-{{ str_replace('_', '-', $transaction->type) }}">{{ ucfirst(str_replace('_', ' ', $transaction->type)) }}</td>
                <td>{{ $transaction->description }}</td>
                <td style="text-align: right;">
                    {{ $transaction->type === 'domain_debit' ? '-' : '+' }}KES {{ number_format($transaction->amount, 2) }}
                </td>
                <td style="text-align: right;">KES {{ number_format($transaction->balance_after, 2) }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="5" style="text-align: center; color: #999;">No transactions found</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        <p>This is an automated admin report from Talksasa Cloud system.</p>
        <p>Report generated at {{ now()->format('M d, Y H:i:s') }}</p>
    </div>
</body>
</html>
