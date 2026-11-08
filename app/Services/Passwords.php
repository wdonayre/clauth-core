<?php

namespace App\Services;

use Illuminate\Validation\Rules\Password;

class Passwords
{
    /** Validation rules for a new password (min length setting + breached check). */
    public static function rules(): array
    {
        $rule = Password::min((int) Settings::get('password_min_length'));
        if (Settings::get('breached_password_check') && ! app()->runningUnitTests()) {
            $rule = $rule->uncompromised();
        }

        return ['required', 'string', 'confirmed', $rule];
    }
}
