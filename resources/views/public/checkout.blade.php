<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout — {{ config('app.name', 'Talksasa Cloud') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased bg-white">
    <!-- Navigation -->
    <nav class="fixed w-full top-0 z-50 bg-white/95 backdrop-blur-md border-b border-gray-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <a href="/" class="flex items-center gap-2 hover:opacity-75 transition">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-600 to-blue-700 flex items-center justify-center">
                        <span class="text-white font-bold">TC</span>
                    </div>
                    <span class="text-xl font-bold text-gray-900">Talksasa</span>
                </a>
            </div>

            <div class="flex items-center gap-4">
                @auth
                    <span class="text-gray-600">{{ auth()->user()->name }}</span>
                @else
                    <span class="text-gray-600">Checkout</span>
                @endauth
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <section class="pt-32 pb-20 px-4 sm:px-6 lg:px-8 bg-gradient-to-br from-slate-50 via-blue-50 to-slate-50 min-h-screen">
        <div class="max-w-7xl mx-auto">
            <div class="grid md:grid-cols-3 gap-8">
                <!-- Left: Order Summary -->
                <div class="md:col-span-2">
                    <div class="bg-white rounded-xl border border-gray-200 p-8 mb-8">
                        <h2 class="text-2xl font-bold text-gray-900 mb-6">Order Summary</h2>

                        <div class="space-y-4">
                            @foreach ($cartItems as $item)
                                <div class="flex justify-between items-start border-b border-gray-200 pb-4">
                                    <div>
                                        <p class="font-semibold text-gray-900">{{ $item['name'] }}</p>
                                        <p class="text-sm text-gray-600">{{ $item['description'] ?? '' }}</p>
                                    </div>
                                    <p class="text-lg font-bold text-gray-900">KES {{ number_format($item['amount'], 0) }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- Account Creation Form (for unauthenticated users) -->
                    @guest
                        <div class="bg-white rounded-xl border border-gray-200 p-8">
                            <h2 class="text-2xl font-bold text-gray-900 mb-6">Create Your Account</h2>
                            <p class="text-gray-600 mb-6">Create an account to complete your order. Your account will give you access to manage your domains and services.</p>

                            <form action="{{ route('checkout.process.public') }}" method="POST" class="space-y-6">
                                @csrf

                                <!-- Name -->
                                <div>
                                    <label for="name" class="block text-sm font-medium text-gray-900 mb-2">Full Name</label>
                                    <input
                                        type="text"
                                        id="name"
                                        name="name"
                                        value="{{ old('name') }}"
                                        class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-blue-600 transition @error('name') border-red-500 @enderror"
                                        required
                                    >
                                    @error('name')
                                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <!-- Email -->
                                <div>
                                    <label for="email" class="block text-sm font-medium text-gray-900 mb-2">Email Address</label>
                                    <input
                                        type="email"
                                        id="email"
                                        name="email"
                                        value="{{ old('email') }}"
                                        class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-blue-600 transition @error('email') border-red-500 @enderror"
                                        required
                                    >
                                    @error('email')
                                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <!-- Password -->
                                <div>
                                    <label for="password" class="block text-sm font-medium text-gray-900 mb-2">Password</label>
                                    <input
                                        type="password"
                                        id="password"
                                        name="password"
                                        class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-blue-600 transition @error('password') border-red-500 @enderror"
                                        required
                                    >
                                    <p class="mt-1 text-sm text-gray-500">At least 8 characters</p>
                                    @error('password')
                                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <!-- Password Confirmation -->
                                <div>
                                    <label for="password_confirmation" class="block text-sm font-medium text-gray-900 mb-2">Confirm Password</label>
                                    <input
                                        type="password"
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-blue-600 transition"
                                        required
                                    >
                                </div>

                                <!-- Terms -->
                                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                    <label class="flex items-start gap-3 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            name="agree_terms"
                                            class="mt-1 @error('agree_terms') border-red-500 @enderror"
                                            required
                                        >
                                        <span class="text-sm text-gray-700">
                                            I agree to the Terms of Service and Privacy Policy. I understand that my account will be created and I authorize the charge for this order.
                                        </span>
                                    </label>
                                    @error('agree_terms')
                                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <!-- Submit Button -->
                                <button
                                    type="submit"
                                    class="w-full px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:shadow-lg transition"
                                >
                                    Create Account & Complete Order
                                </button>

                                <!-- Already have account -->
                                <p class="text-center text-gray-600">
                                    Already have an account? <a href="{{ route('login') }}" class="text-blue-600 hover:text-blue-700 font-semibold">Sign in</a>
                                </p>
                            </form>
                        </div>
                    @endguest

                    @auth
                        <!-- Authenticated user checkout -->
                        <div class="bg-white rounded-xl border border-gray-200 p-8">
                            <h2 class="text-2xl font-bold text-gray-900 mb-6">Complete Your Order</h2>

                            <form action="{{ route('customer.checkout.process') }}" method="POST">
                                @csrf

                                <!-- Terms -->
                                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                                    <label class="flex items-start gap-3 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            name="agree_terms"
                                            class="mt-1"
                                            required
                                        >
                                        <span class="text-sm text-gray-700">
                                            I agree to the Terms of Service and authorize the charge for this order.
                                        </span>
                                    </label>
                                </div>

                                <!-- Submit Button -->
                                <button
                                    type="submit"
                                    class="w-full px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:shadow-lg transition"
                                >
                                    Complete Order
                                </button>
                            </form>
                        </div>
                    @endauth
                </div>

                <!-- Right: Order Total -->
                <div>
                    <div class="bg-white rounded-xl border border-gray-200 p-8 sticky top-32">
                        <h3 class="text-xl font-bold text-gray-900 mb-6">Order Total</h3>

                        <div class="space-y-4 mb-6 pb-6 border-b border-gray-200">
                            <div class="flex justify-between text-gray-700">
                                <span>Subtotal</span>
                                <span class="font-semibold">KES {{ number_format($subtotal, 0) }}</span>
                            </div>
                            @if ($taxEnabled && $tax > 0)
                                <div class="flex justify-between text-gray-700">
                                    <span>Tax ({{ $taxRate }}%)</span>
                                    <span class="font-semibold">KES {{ number_format($tax, 0) }}</span>
                                </div>
                            @endif
                        </div>

                        <div class="flex justify-between items-center">
                            <span class="text-lg font-bold text-gray-900">Total</span>
                            <span class="text-3xl font-bold text-blue-600">KES {{ number_format($total, 0) }}</span>
                        </div>

                        <div class="mt-6 pt-6 border-t border-gray-200">
                            <div class="space-y-2 text-sm text-gray-600">
                                <p>✓ Secure checkout</p>
                                <p>✓ Invoice will be created</p>
                                <p>✓ Payment options available</p>
                            </div>
                        </div>

                        <!-- Back Link -->
                        <a href="{{ route('domains.search.public') }}" class="block mt-6 text-center text-blue-600 hover:text-blue-700 font-semibold">
                            ← Continue Shopping
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-gray-900 text-gray-400 py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-7xl mx-auto">
            <div class="flex justify-between items-center">
                <p>&copy; 2026 Talksasa Cloud. All rights reserved.</p>
                <div class="flex gap-4">
                    <a href="/" class="hover:text-white transition">Home</a>
                    <a href="#" class="hover:text-white transition">Privacy</a>
                    <a href="#" class="hover:text-white transition">Contact</a>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>
