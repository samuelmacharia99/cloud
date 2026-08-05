<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="color: #2563eb; margin: 0;">{{ email_company_name() }}</h1>
        </div>

        <div style="background-color: #f9fafb; padding: 30px; border-radius: 8px;">
            <p style="margin: 0 0 20px;">Hello {{ $name }},</p>

            <p style="margin: 0 0 20px;">
                Use the code below to finish signing in. If you did not try to log in, you can ignore this email.
            </p>

            <div style="background-color: #ffffff; border: 2px solid #e5e7eb; border-radius: 8px; padding: 20px; text-align: center; margin: 30px 0;">
                <p style="margin: 0 0 10px; color: #6b7280; font-size: 12px; text-transform: uppercase;">Your login code</p>
                <p style="margin: 0; font-size: 36px; font-weight: bold; letter-spacing: 4px; color: #1f2937; font-family: monospace;">{{ $code }}</p>
            </div>

            <p style="margin: 0 0 20px; color: #6b7280; font-size: 14px;">
                <strong>This code will expire in {{ $expiryMinutes }} minutes.</strong> Do not share this code with anyone.
            </p>
        </div>

        <div style="text-align: center; margin-top: 30px; color: #9ca3af; font-size: 12px;">
            <p style="margin: 0;">
                © {{ date('Y') }} {{ email_company_name() }}. All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>
