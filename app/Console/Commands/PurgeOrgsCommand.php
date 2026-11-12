<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\OrgLifecycle;
use Illuminate\Console\Command;

/** Daily: delete organizations whose deletion was requested more than 30 days ago (SPEC-organizations §10.5). */
class PurgeOrgsCommand extends Command
{
    protected $signature = 'clauth:orgs-purge';

    protected $description = 'Hard-delete organizations past their 30-day deletion grace period';

    public function handle(): int
    {
        foreach (Organization::withTrashed()->where('is_platform', false)->whereNotNull('deletion_requested_at')
            ->where('deletion_requested_at', '<', now()->subDays(OrgLifecycle::GRACE_DAYS))->get() as $org) {
            $this->line("purging {$org->slug}");
            OrgLifecycle::purge($org);
        }

        return self::SUCCESS;
    }
}
