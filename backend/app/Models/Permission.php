<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Global permission catalogue (not tenant data). See App\Support\Permissions for the canonical list. */
class Permission extends Model
{
    public $timestamps = false;

    protected $fillable = ['key', 'description'];
}
