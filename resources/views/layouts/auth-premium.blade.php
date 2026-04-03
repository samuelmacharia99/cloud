<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Authentication') — {{ config('app.name', 'Talksasa Cloud') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    <!-- Scripts & Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        * {
            font-feature-settings: "rlig" 1, "calt" 1;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            width: 100%;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: white;
            color: #1e293b;
        }

        /* SPLIT SCREEN GRID - CORE LAYOUT */
        body > .auth-shell {
            display: grid !important;
            grid-template-columns: 1fr !important;
            height: 100vh !important;
            width: 100vw !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            overflow: hidden !important;
        }

        @media (min-width: 1024px) {
            body > .auth-shell {
                grid-template-columns: 1fr 1fr !important;
            }
        }

        /* LEFT PANEL - AUTH FORM */
        .auth-panel {
            display: flex !important;
            flex-direction: column !important;
            height: 100vh !important;
            width: 100% !important;
            background: white !important;
            position: relative !important;
            z-index: 10 !important;
        }

        @media (prefers-color-scheme: dark) {
            .auth-panel {
                background: #0f172a !important;
            }
        }

        /* HEADER */
        .auth-header {
            flex: 0 0 auto !important;
            padding: 2rem !important;
            border-bottom: 1px solid #e2e8f0 !important;
        }

        @media (min-width: 1024px) {
            .auth-header {
                padding: 2.5rem 4rem !important;
            }
        }

        @media (prefers-color-scheme: dark) {
            .auth-header {
                border-bottom-color: rgba(30, 41, 59, 0.5) !important;
            }
        }

        /* FORM CONTAINER - VERTICALLY CENTERED */
        .auth-form-wrapper {
            flex: 1 1 auto !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            padding: 2rem !important;
            width: 100% !important;
            overflow-y: auto !important;
            z-index: 10 !important;
        }

        @media (min-width: 1024px) {
            .auth-form-wrapper {
                padding: 4rem !important;
            }
        }

        .auth-form-container {
            width: 100% !important;
            max-width: 28rem !important;
            z-index: 10 !important;
        }

        .auth-form-container * {
            color: inherit !important;
        }

        .auth-form-container h1,
        .auth-form-container h2,
        .auth-form-container p,
        .auth-form-container label,
        .auth-form-container span,
        .auth-form-container div {
            color: inherit !important;
        }

        /* FOOTER */
        .auth-footer {
            flex: 0 0 auto !important;
            padding: 1.5rem 2rem !important;
            border-top: 1px solid #e2e8f0 !important;
            background: white !important;
        }

        @media (min-width: 1024px) {
            .auth-footer {
                padding: 1.5rem 4rem !important;
            }
        }

        @media (prefers-color-scheme: dark) {
            .auth-footer {
                background: #0f172a !important;
                border-top-color: rgba(30, 41, 59, 0.5) !important;
            }
        }

        /* RIGHT PANEL - VISUAL */
        .auth-visual {
            display: none !important;
            position: relative !important;
            height: 100vh !important;
            width: 100% !important;
            overflow: hidden !important;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #3d1f47 100%) !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: center !important;
            z-index: 1 !important;
        }

        @media (min-width: 1024px) {
            .auth-visual {
                display: flex !important;
            }
        }

        /* GRID BACKGROUND */
        .grid-bg {
            position: absolute !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            background-image:
                linear-gradient(to right, rgba(255, 255, 255, 0.03) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(255, 255, 255, 0.03) 1px, transparent 1px) !important;
            background-size: 50px 50px !important;
            z-index: 1 !important;
        }

        /* GLOW ORBS */
        .glow-orb {
            position: absolute !important;
            border-radius: 50% !important;
            filter: blur(50px) !important;
            opacity: 0.25 !important;
        }

        .glow-purple {
            background: linear-gradient(135deg, #c084fc 0%, #a855f7 100%) !important;
        }

        .glow-blue {
            background: linear-gradient(135deg, #38bdf8 0%, #0284c7 100%) !important;
        }

        /* ANIMATION */
        @keyframes float {
            0%, 100% { transform: translateY(0px) translateX(0px); }
            50% { transform: translateY(-24px) translateX(12px); }
        }

        .float-animation {
            animation: float 7s ease-in-out infinite !important;
        }

        /* VISUAL CONTENT */
        .auth-visual-content {
            position: relative !important;
            z-index: 20 !important;
            width: 100% !important;
            height: 100% !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            padding: 4rem !important;
        }

        /* INPUT STYLES */
        .auth-input {
            width: 100% !important;
            background: white !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 0.375rem !important;
            padding: 0.75rem 1rem !important;
            font-size: 0.875rem !important;
            transition: all 0.2s !important;
            color: #1e293b !important;
            font-family: inherit !important;
        }

        /* Hide autofill styling completely */
        .auth-input:-webkit-autofill,
        .auth-input:-webkit-autofill:hover,
        .auth-input:-webkit-autofill:focus,
        .auth-input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 30px white inset !important;
            box-shadow: 0 0 0 30px white inset !important;
        }

        /* Hide password manager and autofill icons */
        input[type="password"]::-webkit-password-strong-password-button,
        input[type="password"]::-webkit-password-weak-password-button,
        input[type="password"]::-webkit-password-password-button {
            display: none !important;
        }

        /* Hide ALL password manager icons - aggressive approach */
        input[type="password"]::-webkit-credentials-auto-fill-button,
        input[type="password"]::-webkit-password-disclosure-button,
        input[type="password"]::-webkit-autofill-strong-password-button,
        input[type="password"]::-webkit-password-dropdown-button,
        input[type="password"]::-webkit-password-weak-password-button,
        input[type="password"]::-webkit-password-strong-password-button {
            display: none !important;
            visibility: hidden !important;
            width: 0 !important;
            height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            border: none !important;
            -webkit-appearance: none !important;
            appearance: none !important;
            pointer-events: none !important;
        }

        /* Hide password manager background images */
        input[type="password"] {
            background-image: none !important;
            background-clip: content-box !important;
            -webkit-appearance: none !important;
            -moz-appearance: none !important;
            appearance: none !important;
        }

        /* Override any browser-injected styles */
        input[type="password"]::after,
        input[type="password"]::before {
            display: none !important;
        }

        @media (prefers-color-scheme: dark) {
            .auth-input {
                background: rgba(71, 85, 105, 0.3) !important;
                border-color: rgba(51, 65, 85, 0.5) !important;
                color: white !important;
            }
        }

        .auth-input::placeholder {
            color: #a1a5b0 !important;
        }

        @media (prefers-color-scheme: dark) {
            .auth-input::placeholder {
                color: #64748b !important;
            }
        }

        .auth-input:focus {
            outline: none !important;
            border-color: rgba(168, 85, 244, 0.5) !important;
            box-shadow: inset 0 0 0 1px rgba(168, 85, 244, 0.1) !important;
        }

        /* Password field specific - hide all icons */
        input[type="password"] {
            padding-right: 2.75rem !important;
        }

        /* Mask any password manager UI that might appear */
        input[type="password"]::-webkit-caps-lock-indicator {
            display: none !important;
        }

        /* Hide browser password manager icons */
        .auth-input::-webkit-credentials-auto-fill-button,
        .auth-input::-webkit-autofill-strong-password-button {
            display: none !important;
            visibility: hidden !important;
            width: 0 !important;
            height: 0 !important;
        }

        /* Hide password manager icons in password fields */
        input[type="password"]::-webkit-outer-spin-button,
        input[type="password"]::-webkit-inner-spin-button {
            -webkit-appearance: none !important;
            margin: 0 !important;
        }

        /* BUTTON STYLES */
        .auth-btn-primary {
            width: 100% !important;
            padding: 0.75rem 1rem !important;
            background: #0f172a !important;
            color: white !important;
            border: none !important;
            border-radius: 0.375rem !important;
            font-weight: 600 !important;
            font-size: 0.875rem !important;
            cursor: pointer !important;
            transition: all 0.2s !important;
            font-family: inherit !important;
        }

        @media (prefers-color-scheme: dark) {
            .auth-btn-primary {
                background: white !important;
                color: #0f172a !important;
            }
        }

        .auth-btn-primary:hover {
            background: #1e293b !important;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1) !important;
        }

        @media (prefers-color-scheme: dark) {
            .auth-btn-primary:hover {
                background: #f1f5f9 !important;
                box-shadow: 0 10px 15px -3px rgba(168, 85, 244, 0.05) !important;
            }
        }

        .auth-btn-primary:active {
            transform: scale(0.98) !important;
        }

        .auth-btn-secondary {
            width: 100% !important;
            padding: 0.75rem 1rem !important;
            background: white !important;
            border: 1px solid #e2e8f0 !important;
            color: #0f172a !important;
            border-radius: 0.375rem !important;
            font-weight: 500 !important;
            font-size: 0.875rem !important;
            cursor: pointer !important;
            transition: all 0.2s !important;
            font-family: inherit !important;
        }

        @media (prefers-color-scheme: dark) {
            .auth-btn-secondary {
                background: rgba(71, 85, 105, 0.3) !important;
                border-color: rgba(51, 65, 85, 0.6) !important;
                color: white !important;
            }
        }

        .auth-btn-secondary:hover {
            background: #f8fafc !important;
            border-color: #cbd5e1 !important;
        }

        @media (prefers-color-scheme: dark) {
            .auth-btn-secondary:hover {
                background: rgba(51, 65, 85, 0.4) !important;
                border-color: rgba(51, 65, 85, 0.8) !important;
            }
        }

        .auth-btn-secondary:active {
            transform: scale(0.98) !important;
        }

        /* Typography overrides */
        .auth-form-container {
            color: #1e293b !important;
        }

        @media (prefers-color-scheme: dark) {
            .auth-form-container {
                color: white !important;
            }
        }

        .auth-form-container h1,
        .auth-form-container h2,
        .auth-form-container h3,
        .auth-form-container label,
        .auth-form-container p,
        .auth-form-container span,
        .auth-form-container div {
            color: inherit !important;
            font-family: inherit !important;
        }

        .auth-form-container a {
            text-decoration: none !important;
            color: #a855f7 !important;
            font-weight: 600 !important;
        }

        .auth-form-container a:hover {
            color: #9333ea !important;
        }

        @media (prefers-color-scheme: dark) {
            .auth-form-container a {
                color: #d8b4fe !important;
            }

            .auth-form-container a:hover {
                color: #c084fc !important;
            }
        }
    </style>
</head>
<body>
    <!-- Auth Shell: Grid-based split-screen -->
    <div class="auth-shell">
        <!-- Left Panel: Auth Form -->
        <div class="auth-panel">
            <!-- Header: Logo -->
            <div class="auth-header">
                <a href="/" class="inline-flex items-center gap-3 group">
                    <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-purple-600 to-purple-700 flex items-center justify-center text-white font-bold text-lg shadow-lg group-hover:shadow-xl group-hover:shadow-purple-500/20 transition-shadow">
                        T
                    </div>
                    <div>
                        <div class="font-bold text-base leading-tight tracking-tight text-slate-900 dark:text-white">Talksasa</div>
                        <div class="text-xs font-medium text-slate-500 dark:text-slate-400 tracking-wide">CLOUD</div>
                    </div>
                </a>
            </div>

            <!-- Form Container: Vertically Centered -->
            <div class="auth-form-wrapper">
                <div class="auth-form-container">
                    @yield('content')
                </div>
            </div>

            <!-- Footer: Links -->
            <div class="auth-footer">
                <div class="flex flex-col sm:flex-row items-center justify-between gap-4 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <div class="flex items-center gap-5">
                        <a href="#" class="hover:text-slate-700 dark:hover:text-slate-300 transition">Terms</a>
                        <a href="#" class="hover:text-slate-700 dark:hover:text-slate-300 transition">Privacy</a>
                        <a href="#" class="hover:text-slate-700 dark:hover:text-slate-300 transition">Contact</a>
                    </div>
                    <div class="hidden sm:block text-slate-400 dark:text-slate-500">© 2026 Talksasa</div>
                </div>
            </div>
        </div>

        <!-- Right Panel: Visual Decoration -->
        <div class="auth-visual">
            <!-- Background grid -->
            <div class="grid-bg"></div>

            <!-- Glow elements -->
            <div class="glow-orb glow-purple w-[500px] h-[500px] -top-40 -right-40"></div>
            <div class="glow-orb glow-blue w-96 h-96 -bottom-20 -left-32"></div>
            <div class="glow-orb glow-purple w-80 h-80 top-1/3 right-1/3"></div>

            <!-- Content: Floating cards -->
            <div class="auth-visual-content">
                <div class="relative w-full max-w-lg">
                    <!-- Central card -->
                    <div class="float-animation bg-white/8 border border-white/15 rounded-2xl p-8 backdrop-blur-xl shadow-2xl">
                        <div class="space-y-6">
                            <div class="flex items-center justify-between">
                                <div class="text-sm font-semibold text-white/90 tracking-wider">INFRASTRUCTURE</div>
                                <div class="w-2.5 h-2.5 rounded-full bg-gradient-to-r from-purple-400 to-blue-400 animate-pulse shadow-lg shadow-purple-500/50"></div>
                            </div>
                            <div class="space-y-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-2 h-2 rounded-full bg-emerald-400/80 shadow-lg shadow-emerald-500/30"></div>
                                    <div class="text-xs text-white/70 font-medium">Services: 3 running</div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <div class="w-2 h-2 rounded-full bg-blue-400/80 shadow-lg shadow-blue-500/30"></div>
                                    <div class="text-xs text-white/70 font-medium">Status: All systems healthy</div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <div class="w-2 h-2 rounded-full bg-purple-400/80 shadow-lg shadow-purple-500/30"></div>
                                    <div class="text-xs text-white/70 font-medium">Uptime: 99.98%</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top-right card -->
                    <div class="absolute -top-16 -right-16 w-56 h-40 float-animation" style="animation-delay: 1.2s;">
                        <div class="bg-white/8 border border-white/15 rounded-2xl p-4 backdrop-blur-xl h-full shadow-lg">
                            <div class="text-xs font-semibold text-white/80 mb-3 tracking-wider">DEPLOYMENT</div>
                            <div class="space-y-2">
                                <div class="flex items-center gap-2">
                                    <div class="h-1 bg-white/30 rounded-full flex-1"></div>
                                    <span class="text-xs text-white/50 font-medium">78%</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <div class="h-1 bg-gradient-to-r from-purple-400 to-blue-400 rounded-full flex-1" style="width: 85%"></div>
                                    <span class="text-xs text-white/50 font-medium">85%</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <div class="h-1 bg-white/20 rounded-full flex-1" style="width: 60%"></div>
                                    <span class="text-xs text-white/50 font-medium">60%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bottom-left card -->
                    <div class="absolute -bottom-12 -left-20 w-48 h-32 float-animation" style="animation-delay: 2.4s;">
                        <div class="bg-white/8 border border-white/15 rounded-2xl p-4 backdrop-blur-xl h-full flex items-center shadow-lg">
                            <div>
                                <div class="text-xs font-semibold text-white/70 mb-2 tracking-wider">LATENCY</div>
                                <div class="text-3xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-purple-300 to-blue-300">42ms</div>
                                <div class="text-xs text-white/50 mt-1 font-medium">avg response</div>
                            </div>
                        </div>
                    </div>

                    <!-- Connecting lines -->
                    <svg class="absolute inset-0 w-full h-full opacity-30" style="filter: drop-shadow(0 0 30px rgba(168, 85, 247, 0.2))">
                        <line x1="50%" y1="10%" x2="75%" y2="25%" stroke="url(#grad1)" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                        <line x1="50%" y1="90%" x2="25%" y2="75%" stroke="url(#grad2)" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                        <defs>
                            <linearGradient id="grad1" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" style="stop-color:#c084fc;stop-opacity:0.8" />
                                <stop offset="100%" style="stop-color:#38bdf8;stop-opacity:0" />
                            </linearGradient>
                            <linearGradient id="grad2" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" style="stop-color:#38bdf8;stop-opacity:0.8" />
                                <stop offset="100%" style="stop-color:#c084fc;stop-opacity:0" />
                            </linearGradient>
                        </defs>
                    </svg>
                </div>
            </div>

            <!-- Bottom accent -->
            <div class="absolute bottom-0 right-0 w-[600px] h-[600px] rounded-full bg-gradient-to-t from-purple-600/15 via-transparent to-transparent blur-3xl"></div>
        </div>
    </div>
</body>
</html>
