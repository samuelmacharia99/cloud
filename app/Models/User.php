<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'company',
        'country',
        'is_admin',
        'is_reseller',
        'status',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_reseller' => 'boolean',
        ];
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function domains()
    {
        return $this->hasMany(Domain::class);
    }

    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    public function isReseller(): bool
    {
        return $this->is_reseller;
    }

    public function getOutstandingBalance(): float
    {
        return $this->invoices()
            ->where('status', 'unpaid')
            ->sum('total');
    }

    public function getActiveServicesCount(): int
    {
        return $this->services()
            ->where('status', 'active')
            ->count();
    }
}
