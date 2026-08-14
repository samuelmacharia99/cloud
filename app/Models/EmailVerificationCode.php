<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class EmailVerificationCode extends Model
{
    protected $table = 'email_verification_codes';

    protected $guarded = ['id'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function matches(string $plainCode): bool
    {
        if (str_starts_with($this->code, '$2y$') || str_starts_with($this->code, '$argon')) {
            return Hash::check($plainCode, $this->code);
        }

        return hash_equals((string) $this->code, $plainCode);
    }

    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', now());
    }
}
