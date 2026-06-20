<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginCode extends Model
{
    protected $fillable = [
        'account_type', 'email', 'code_hash', 'expires_at', 'consumed_at', 'attempts',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    protected $hidden = [
        'code_hash',
    ];
}
