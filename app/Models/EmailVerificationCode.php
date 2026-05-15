<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailVerificationCode extends Model
{
    protected $table = 'email_verification_codes';
    protected $guarded = ['id'];
    public $timestamps = false;
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
}
