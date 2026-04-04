<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f3f4f6;
            color: #1f2937;
            margin: 0;
            padding: 20px 0;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            padding: 30px;
            text-align: center;
            color: white;
        }
        .logo {
            max-width: 150px;
            max-height: 50px;
            margin-bottom: 15px;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            margin: 0;
        }
        .content {
            padding: 30px;
        }
        .content h1 {
            color: #1f2937;
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 24px;
        }
        .content h2 {
            color: #374151;
            font-size: 18px;
            margin: 20px 0 10px 0;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 10px;
        }
        .content p {
            line-height: 1.6;
            margin: 15px 0;
            color: #4b5563;
        }
        .cta-button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #2563eb;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
            margin: 20px 0;
            text-align: center;
        }
        .cta-button:hover {
            background-color: #1d4ed8;
        }
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin: 20px 0;
        }
        .alert-warning {
            background-color: #fef3c7;
            border-left: 4px solid #f59e0b;
            color: #92400e;
        }
        .alert-danger {
            background-color: #fee2e2;
            border-left: 4px solid #ef4444;
            color: #991b1b;
        }
        .alert-info {
            background-color: #dbeafe;
            border-left: 4px solid #3b82f6;
            color: #1e40af;
        }
        .alert-success {
            background-color: #dcfce7;
            border-left: 4px solid #22c55e;
            color: #166534;
        }
        .footer {
            background-color: #f9fafb;
            padding: 30px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 12px;
            color: #6b7280;
        }
        .footer p {
            margin: 5px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        table th {
            background-color: #f3f4f6;
            padding: 12px;
            text-align: left;
            font-weight: bold;
            border-bottom: 1px solid #e5e7eb;
        }
        table td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        table tr:last-child td {
            border-bottom: none;
        }
        .highlight {
            background-color: #fef3c7;
            padding: 2px 6px;
            border-radius: 3px;
        }
        .text-muted {
            color: #6b7280;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            @php
                $logo = \App\Models\Setting::getValue('logo_url', '');
                $company = \App\Models\Setting::getValue('company_name', 'Talksasa Cloud');
            @endphp
            @if($logo)
                <img src="{{ $logo }}" alt="{{ $company }}" class="logo">
            @endif
            <p class="company-name">{{ $company }}</p>
        </div>

        <!-- Content -->
        <div class="content">
            @yield('content')
        </div>

        <!-- Footer -->
        <div class="footer">
            @php
                $footerText = \App\Models\Setting::getValue('footer_text', '');
            @endphp
            @if($footerText)
                <p>{{ $footerText }}</p>
            @else
                <p>© {{ now()->year }} {{ $company }}. All rights reserved.</p>
            @endif
            <p class="text-muted">This is an automated email from {{ $company }}. Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
