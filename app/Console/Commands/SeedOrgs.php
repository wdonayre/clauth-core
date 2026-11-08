<?php

namespace App\Console\Commands;

use App\Models\Organization;
use Illuminate\Console\Command;

/** Creates William's four businesses as organizations (idempotent). */
class SeedOrgs extends Command
{
    protected $signature = 'clauth:seed-orgs';

    protected $description = 'Create the standard organizations';

    public function handle(): int
    {
        foreach ([
            'alab-apparel' => 'Alab Apparel',
            'cakesbygrace' => 'CakesbyGrace',
            'cebucake' => 'CebuCake Inc.',
            'the-cake-labs' => 'The Cake Labs',
        ] as $slug => $name) {
            Organization::firstOrCreate(['slug' => $slug], ['name' => $name]);
            $this->line("✓ $name ($slug)");
        }

        return self::SUCCESS;
    }
}
