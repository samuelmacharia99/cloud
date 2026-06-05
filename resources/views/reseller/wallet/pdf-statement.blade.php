<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Wallet Statement</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
        .header h1 { margin: 0; font-size: 24px; }
        .header p { margin: 5px 0; color: #666; }
        .info-section { margin-bottom: 20px; }
        .info-section h3 { background: #f5f5f5; padding: 10px; margin: 0; font-size: 14px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin: 20px 0; }
        .info-item { }
        .info-item label { font-weight: bold; font-size: 12px; color: #666; }
        .info-item value { display: block; font-size: 16px; margin-top: 5px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #f5f5f5; padding: 12px; text-align: left; font-size: 12px; font-weight: bold; border-bottom: 1px solid #ddd; }
        td { padding: 10px 12px; font-size: 11px; border-bottom: 1px solid #eee; }
        .type-deposit { color: #10b981; }
        .type-debit { color: #ef4444; }
        .type-refund { color: #3b82f6; }
        .type-adjustment { color: #f59e0b; }
        .summary { margin-top: 30px; text-align: right; }
        .summary-row { display: flex; justify-content: flex-end; margin-top: 10px; font-weight: bold; }
        .summary-label { width: 150px; }
        .summary-value { width: 100px; text-align: right; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 10px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Wallet Statement</h1>
        <p>Talksasa Cloud</p>
        <p>Generated on {{ now()->format('M d, Y') }}</p>
    </div>

    <div class="info-grid">
        <div class="info-item">
            <label>Statement Period</label>
            <value>
                {{ $fromDate ? \Carbon\Carbon::parse($fromDate)->format('M d, Y') : 'All Time' }}
                @if($toDate)
                    to {{ \Carbon\Carbon::parse($toDate)->format('M d, Y') }}
                @endif
            </value>
        </div>
        <div class="info-item">
            <label>Current Balance</label>
            <value>{{ $wallet->getFormattedBalance() }}</value>
        </div>
        <div class="info-item">
            <label>Currency</label>
            <value>{{ $wallet->currency }}</value>
        </div>
    </div>

    <div class="info-section">
        <h3>Transaction Details</h3>
    </div>

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
                    {{ $transaction->type === 'domain_debit' ? '-' : '+' }}KSH {{ number_format($transaction->amount, 2) }}
                </td>
                <td style="text-align: right;">KSH {{ number_format($transaction->balance_after, 2) }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="5" style="text-align: center; color: #999;">No transactions found</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div class="summary">
        <div class="summary-row">
            <div class="summary-label">Current Balance:</div>
            <div class="summary-value">{{ $wallet->getFormattedBalance() }}</div>
        </div>
    </div>

    <div class="footer">
        <p>This is an automated statement from Talksasa Cloud. For support, please contact support@talksasa.com</p>
        <p>Report generated at {{ now()->format('M d, Y H:i:s') }}</p>
    </div>
</body>
</html>
