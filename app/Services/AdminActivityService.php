<?php

namespace App\Services;

use App\Models\AdminActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AdminActivityService
{
    public static function log(
        string $action,
        string $description,
        ?Model $subject = null,
        array $metadata = [],
    ): AdminActivityLog {
        return AdminActivityLog::create([
            'admin_user_id' => Auth::id(),
            'action' => $action,
            'description' => $description,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata ?: null,
            'ip_address' => Request::ip(),
        ]);
    }
}
