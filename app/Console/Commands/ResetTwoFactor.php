<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit;
use App\Services\Sessions;
use Illuminate\Console\Command;

/**
 * Break-glass: removes a user's 2-step setup from the server (for an owner who lost their phone AND their
 * recovery codes — nobody above them can reset it in the console). Needs SSH to the server, not just a password.
 *   php artisan clauth:reset-2fa william@thecakelabs.com
 * They sign in with their password and set up 2-step again on the new phone (owners must).
 */
class ResetTwoFactor extends Command
{
    protected $signature = 'clauth:reset-2fa {email} {--force : Skip the confirmation question}';

    protected $description = 'Break-glass: reset 2-step verification for one user and sign them out everywhere';

    public function handle(): int
    {
        $user = User::where('email', mb_strtolower(trim($this->argument('email'))))->first();
        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }
        if (! $user->hasTwoFactor()) {
            $this->info("{$user->email} has no 2-step set up. Nothing to reset.");

            return self::SUCCESS;
        }
        $this->line("{$user->name} <{$user->email}>".($user->isOwner() ? ' — Clauth owner' : ''));
        $this->warn('Only do this after confirming it really is them (call, video or in person).');
        if (! $this->option('force') && ! $this->confirm('Reset their 2-step verification and sign them out everywhere?')) {
            $this->line('Nothing changed.');

            return self::FAILURE;
        }
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
        Sessions::revokeAll($user);
        Audit::log('security.mfa_reset', 'security', $user, after: ['via' => 'server command'], actorType: 'system');
        $this->info('Done. At next sign-in they set up 2-step again with their new phone.');

        return self::SUCCESS;
    }
}
