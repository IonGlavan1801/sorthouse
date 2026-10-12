<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model as Eloquent;

abstract class Model extends Eloquent
{
    use HasUuids;

    protected $guarded = [];

    /** Microsecond precision so "latest" is well defined for rows written in the same second. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    public $incrementing = false;

    protected $keyType = 'string';
}
