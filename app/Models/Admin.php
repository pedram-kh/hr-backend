<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class Admin extends Model
{
    use HasApiTokens, HasRoles;

    /**
     * Roles are matched against this guard. We keep the default 'web' guard so
     * spatie role checks ($admin->hasRole(...)) work on the model instance,
     * independent of the Sanctum token guard used for HTTP auth.
     */
    protected string $guard_name = 'web';

    protected $fillable = [
        'uuid', 'email', 'full_name', 'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (Admin $admin) {
            if (empty($admin->uuid)) {
                $admin->uuid = (string) Str::uuid();
            }
        });
    }
}
