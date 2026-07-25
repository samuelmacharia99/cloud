<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Opening webmail…</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: #0f172a; color: #e2e8f0; display: flex; min-height: 100vh; align-items: center; justify-content: center; margin: 0; }
        .card { max-width: 28rem; padding: 2rem; border-radius: 1rem; background: #1e293b; border: 1px solid #334155; text-align: center; }
        .muted { color: #94a3b8; font-size: 0.875rem; margin-top: 0.75rem; }
        code { font-family: ui-monospace, monospace; font-size: 0.85rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1 style="margin:0 0 0.5rem; font-size:1.25rem;">Opening webmail</h1>
        <p class="muted">Signing you into <code>{{ $mailbox }}</code> without your mailbox password…</p>
        <p class="muted">If nothing happens, use the button below.</p>
        <form id="sogo-login" method="POST" action="{{ $connectUrl }}" style="margin-top:1.5rem;">
            <input type="hidden" name="userName" value="{{ $mailbox }}">
            <input type="hidden" name="password" value="{{ $password }}">
            <button type="submit" style="background:#0d9488;color:#fff;border:0;border-radius:0.5rem;padding:0.65rem 1.25rem;font-weight:600;cursor:pointer;">
                Continue to SOGo
            </button>
        </form>
    </div>
    <script>
        document.getElementById('sogo-login').submit();
    </script>
</body>
</html>
