<?php

namespace App\Console\Commands;

use App\Models\SigningKey;
use App\Services\Audit;
use App\Services\SigningKeys;
use Illuminate\Console\Command;

/** php artisan clauth:keys {status|init|stage|activate|export-public} */
class Keys extends Command
{
    protected $signature = 'clauth:keys {action=status} {--kid=}';

    protected $description = 'Inspect and rotate token signing keys';

    public function handle(): int
    {
        switch ($this->argument('action')) {
            case 'init':
                $k = SigningKeys::active();
                $this->info("Active key: {$k->kid}");
                break;
            case 'stage':
                $k = SigningKeys::generate('staged');
                Audit::log('security.key_staged', 'security', $k, after: ['kid' => $k->kid]);
                $this->info("Staged {$k->kid}; activates {$k->activates_at}.");
                break;
            case 'activate':
                $k = SigningKey::where('kid', $this->option('kid'))->where('status', 'staged')->firstOrFail();
                SigningKeys::activate($k);
                Audit::log('security.key_activated', 'security', $k, after: ['kid' => $k->kid]);
                $this->info("Activated {$k->kid}.");
                break;
            default:
                $this->table(['kid', 'status', 'activates', 'retires'], SigningKey::orderByDesc('id')->get()
                    ->map(fn ($k) => [$k->kid, $k->status, $k->activates_at, $k->retires_at]));
        }

        return self::SUCCESS;
    }
}
