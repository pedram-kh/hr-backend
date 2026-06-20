<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Province extends Model
{
    protected $fillable = ['code', 'name', 'aliases', 'is_national'];

    protected $casts = [
        'aliases' => 'array',
        'is_national' => 'boolean',
    ];
}
