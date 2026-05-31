<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Talksasa App') }} — Talksasa Cloud</title>
    <style>
        :root {
            color-scheme: dark;
            --cyan: #00d9ff;
            --green: #00ff88;
            --bg: #0f172a;
            --card: #1e293b;
            --muted: #94a3b8;
            --text: #e2e8f0;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: radial-gradient(circle at top, #1e293b 0%, var(--bg) 55%);
            color: var(--text);
        }

        .wrap {
            max-width: 920px;
            margin: 0 auto;
            padding: 48px 24px 64px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 999px;
            border: 1px solid rgba(0, 217, 255, 0.35);
            background: rgba(0, 217, 255, 0.08);
            color: var(--cyan);
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        h1 {
            margin: 20px 0 12px;
            font-size: clamp(2rem, 5vw, 3rem);
            line-height: 1.1;
            background: linear-gradient(135deg, #fff 0%, var(--cyan) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .lead {
            margin: 0;
            max-width: 640px;
            color: var(--muted);
            font-size: 1.125rem;
            line-height: 1.7;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-top: 36px;
        }

        .card {
            padding: 20px;
            border-radius: 16px;
            background: rgba(30, 41, 59, 0.85);
            border: 1px solid rgba(148, 163, 184, 0.18);
        }

        .card h2 {
            margin: 0 0 8px;
            font-size: 1rem;
            color: #fff;
        }

        .card p {
            margin: 0;
            color: var(--muted);
            font-size: 0.95rem;
            line-height: 1.6;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 32px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 20px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .btn-primary {
            color: #0f172a;
            background: linear-gradient(135deg, var(--cyan) 0%, #0099cc 100%);
            box-shadow: 0 0 24px rgba(0, 217, 255, 0.25);
        }

        .btn-secondary {
            color: var(--cyan);
            border: 1px solid rgba(0, 217, 255, 0.45);
            background: rgba(0, 217, 255, 0.06);
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        footer {
            margin-top: 48px;
            padding-top: 24px;
            border-top: 1px solid rgba(148, 163, 184, 0.15);
            color: var(--muted);
            font-size: 0.875rem;
        }

        footer strong {
            color: var(--green);
        }
    </style>
</head>
<body>
    <main class="wrap">
        <span class="badge">Talksasa Cloud · Laravel {{ app()->version() }}</span>
        <h1>{{ config('app.name', 'Your Laravel App') }} is live</h1>
        <p class="lead">
            Your application is running on Talksasa Cloud. Replace this page by editing
            <code>resources/views/welcome.blade.php</code> or point your routes to your own controllers.
        </p>

        <div class="grid">
            <section class="card">
                <h2>Build your app</h2>
                <p>Use the Files tab and Terminal in your Talksasa Cloud dashboard to edit code, run Artisan commands, and deploy updates.</p>
            </section>
            <section class="card">
                <h2>Database ready</h2>
                <p>MySQL credentials are injected into your <code>.env</code> file automatically when the stack is deployed.</p>
            </section>
            <section class="card">
                <h2>Custom domain</h2>
                <p>Attach a domain from your Talksasa Cloud service page to serve this app on your own hostname with SSL.</p>
            </section>
        </div>

        <div class="actions">
            @if ($portalUrl = rtrim((string) env('TALKSASA_CLOUD_URL', ''), '/'))
                <a class="btn btn-primary" href="{{ $portalUrl }}" rel="noopener">Open Talksasa Cloud</a>
            @endif
            <a class="btn btn-secondary" href="https://laravel.com/docs" target="_blank" rel="noopener">Laravel documentation</a>
        </div>

        <footer>
            Powered by <strong>Talksasa Cloud</strong> — your digital infrastructure partner.
        </footer>
    </main>
</body>
</html>
