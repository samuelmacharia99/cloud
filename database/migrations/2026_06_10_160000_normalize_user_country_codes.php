<?php

use App\Models\User;
use App\Support\Countries;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        User::query()
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->select(['id', 'country'])
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    $normalized = Countries::normalize($user->country);

                    if ($normalized && $normalized !== $user->country) {
                        $user->forceFill(['country' => $normalized])->saveQuietly();
                    }
                }
            });
    }

    public function down(): void
    {
        // Irreversible data normalization.
    }
};
