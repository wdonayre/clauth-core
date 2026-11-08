<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SigningKey extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['private_pem'];

    protected function casts(): array
    {
        return [
            'public_jwk' => 'array',
            'private_pem' => 'encrypted',
            'activates_at' => 'datetime',
            'retires_at' => 'datetime',
        ];
    }
}
