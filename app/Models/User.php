<?php

namespace App\Models;

use App\Mail\VerifyEmailOtpMail;
use App\Notifications\ResetPasswordNotification;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'notification_phones',
        'company',
        'country',
        'address',
        'city',
        'postal_code',
        'vat_number',
        'notes',
        // is_admin and is_reseller are intentionally excluded from $fillable
        // to prevent privilege escalation via mass assignment.
        'reseller_id',
        'status',
        'email_verified_at',
        'reseller_package_id',
        'commission_rate',
        'package_subscribed_at',
        'package_expires_at',
        'two_factor_enabled',
        'two_factor_code',
        'two_factor_code_expires_at',
        'two_factor_recovery_codes',
        'settings',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_code',
        'two_factor_recovery_codes',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_reseller' => 'boolean',
            'notification_phones' => 'array',
            'package_subscribed_at' => 'datetime',
            'package_expires_at' => 'datetime',
            'two_factor_enabled' => 'boolean',
            'two_factor_code_expires_at' => 'datetime',
            'two_factor_recovery_codes' => 'array',
            'settings' => 'array',
            'commission_rate' => 'decimal:2',
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

    public function managedDomains()
    {
        return $this->hasMany(Domain::class, 'reseller_id');
    }

    public function resellerPackage()
    {
        return $this->belongsTo(ResellerPackage::class, 'reseller_package_id');
    }

    public function reseller()
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function customers()
    {
        return $this->hasMany(User::class, 'reseller_id');
    }

    public function resellerProducts()
    {
        return $this->hasMany(ResellerProduct::class, 'reseller_id');
    }

    public function resellerDomainPricing()
    {
        return $this->hasMany(ResellerDomainPricing::class, 'reseller_id');
    }

    public function credits()
    {
        return $this->hasMany(Credit::class);
    }

    public function wallet()
    {
        return $this->hasOne(ResellerWallet::class, 'reseller_id');
    }

    public function domainOrders()
    {
        return $this->hasMany(ResellerDomainOrder::class, 'reseller_id');
    }

    public function notificationPreferences()
    {
        return $this->hasMany(UserNotificationPreference::class);
    }

    public function domainRenewalOrders()
    {
        return $this->hasMany(DomainRenewalOrder::class);
    }

    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    public function isReseller(): bool
    {
        return $this->is_reseller;
    }

    public function isCustomer(): bool
    {
        return ! $this->is_admin && ! $this->is_reseller;
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

    /**
     * Count of services this reseller manages, regardless of status.
     */
    public function getManagedServicesCount(): int
    {
        return Service::where('reseller_id', $this->id)->count();
    }

    /**
     * Count of active services managed by this reseller (used as storage proxy).
     */
    public function getManagedActiveServicesCount(): int
    {
        return Service::where('reseller_id', $this->id)
            ->where('status', 'active')
            ->count();
    }

    /**
     * Distinct customers (users with at least one service managed by this reseller).
     */
    public function getManagedCustomersCount(): int
    {
        return $this->customers()->count();
    }

    /**
     * Returns true if reseller has a package assigned.
     */
    public function hasResellerPackage(): bool
    {
        return ! is_null($this->reseller_package_id);
    }

    /**
     * Returns true if the reseller has hit or exceeded the user limit.
     */
    public function isAtUserLimit(): bool
    {
        if (! $this->resellerPackage) {
            return true;
        }

        return $this->getManagedCustomersCount() >= $this->resellerPackage->max_users;
    }

    /**
     * Returns true if the reseller has hit or exceeded the service (storage proxy) limit.
     */
    public function isAtServiceLimit(): bool
    {
        if (! $this->resellerPackage) {
            return true;
        }

        return $this->getManagedActiveServicesCount() >= $this->resellerPackage->storage_space;
    }

    /**
     * True when either limit is exceeded — used by middleware.
     */
    public function isOverPackageLimits(): bool
    {
        return $this->isAtUserLimit() || $this->isAtServiceLimit();
    }

    public function getNextInvoiceDateAttribute(): ?Carbon
    {
        return $this->package_expires_at?->subDays(5);
    }

    public function getPackageSuspendDateAttribute(): ?Carbon
    {
        return $this->package_expires_at;
    }

    public function sendEmailVerificationNotification(): void
    {
        EmailVerificationCode::where('user_id', $this->id)->delete();

        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        EmailVerificationCode::create([
            'user_id' => $this->id,
            'code' => $code,
            'expires_at' => now()->addMinutes(15),
        ]);

        Mail::mailer('smtp')->to($this->email)->send(new VerifyEmailOtpMail($this, $code));
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
