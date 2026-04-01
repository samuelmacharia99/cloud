<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Talksasa Cloud') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased">
    <div class="min-h-screen bg-gradient-to-br from-blue-600 to-blue-800 flex items-center justify-center p-4">
        <div class="text-center text-white">
            <h1 class="text-5xl font-bold mb-4">Talksasa Cloud</h1>
            <p class="text-xl mb-8 opacity-90">Modern Web Hosting Billing Platform</p>
            <div class="flex gap-4 justify-center">
                <a href="{{ route('login') }}" class="px-8 py-3 bg-white text-blue-600 rounded-lg font-semibold hover:bg-gray-100">Login</a>
                <a href="{{ route('register') }}" class="px-8 py-3 border-2 border-white rounded-lg font-semibold hover:bg-white hover:text-blue-600">Register</a>
            </div>
        </div>
    </div>
</body>
</html>
