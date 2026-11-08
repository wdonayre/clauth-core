<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Invites;
use Illuminate\Console\Command;

/**
 * Bootstraps a Clauth owner without anyone typing a password into a terminal or file:
 * prints a one-time account-setup link (password + 2-step) valid 7 days.
 *   php artisan clauth:owner william@thecakelabs.com --name="William Donayre" [--send]
 */
class MakeOwner extends Command
{
    protected $signature = 'clauth:owner {email} {--name=} {--send : Also email the link}';

    protected $description = 'Create (or promote) a Clauth owner and print a one-time setup link';

    public function handle(): int
    {
        $email = mb_strtolower($this->argument('email'));
        $user = User::where('email', $email)->first();
        if ($user && $user->password) {
            $user->forceFill(['clauth_role' => 'owner'])->save();
            $this->info("$email is now an owner. They sign in with their existing password + 2-step code.");

            return self::SUCCESS;
        }
        [$invite, $token] = Invites::create([
            'email' => $email, 'name' => $this->option('name') ?: ($user?->name ?? explode('@', $email)[0]),
            'clauth_role' => 'owner', 'require_mfa' => true,
        ], null);
        if ($this->option('send')) {
            Invites::send($invite, $token);
            $this->info("Setup link emailed to $email.");
        }
        $this->line(Invites::url($token));
        $this->comment('One-time link, valid 7 days. Opening it sets the password and 2-step verification.');

        return self::SUCCESS;
    }
}
