<?php

namespace App\Translation;

use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** lang/*.json plus the platform's own wording changes from the API (translation_overrides), SPEC-platform §8.5. */
class OverrideLoader implements Loader
{
    public function __construct(private Loader $inner) {}

    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->inner->load($locale, $group, $namespace);
        if ($group === '*' && $namespace === '*') {
            $over = rescue(fn () => Cache::remember('i18n:over:'.$locale, 300, fn () => Schema::hasTable('translation_overrides')
                ? DB::table('translation_overrides')->where('locale', $locale)->pluck('text', 'key')->all() : []), [], false);
            $lines = array_merge($lines, $over);
        }

        return $lines;
    }

    public function addNamespace($namespace, $hint)
    {
        $this->inner->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->inner->addJsonPath($path);
    }

    public function namespaces()
    {
        return $this->inner->namespaces();
    }
}
