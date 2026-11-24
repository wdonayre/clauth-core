<?php

namespace App\Http\Controllers\Admin;

use App\Models\Application;
use App\Models\User;
use Illuminate\Http\Request;

/** ⌘K search in the console: people, apps and organizations this admin may see. */
class SearchController
{
    use Scope;

    public function __invoke(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $me = $request->user();
        $out = ['people' => [], 'apps' => [], 'orgs' => []];
        if (mb_strlen($q) < 1) {
            return response()->json($out);
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

        if ($me->isOwner() || $me->adminOrgIds() !== []) {
            $out['people'] = $this->scopeUsers(User::query())
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like))
                ->orderBy('name')->limit(6)->get()
                ->map(fn ($u) => ['title' => $u->name, 'sub' => $u->email, 'initials' => $u->initials(), 'href' => route('admin.users.show', $u->id)])->all();
            $out['orgs'] = $this->orgs()->filter(fn ($o) => mb_stripos($o->name, $q) !== false || mb_stripos((string) $o->slug, $q) !== false)
                ->take(5)->map(fn ($o) => ['title' => $o->name, 'sub' => $o->slug, 'initials' => mb_strtoupper(mb_substr($o->name, 0, 2)),
                    'href' => \Illuminate\Support\Facades\Route::has('admin.orgs.show') ? route('admin.orgs.show', $o->id) : route('admin.orgs.index').'#org-'.$o->id])->values()->all();
        }

        $ids = $this->orgIds();
        $out['apps'] = Application::query()
            ->when($ids !== null, fn ($q2) => $q2->where(fn ($w) => $w->whereIn('owner_organization_id', $me->devOrgIds())
                ->orWhereHas('organizations', fn ($o) => $o->whereIn('organizations.id', $ids))))
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('key', 'like', $like))
            ->orderBy('name')->limit(5)->get()
            ->map(fn ($a) => ['title' => $a->name, 'sub' => $a->key, 'initials' => $a->letter(), 'color' => $a->color, 'href' => route('admin.apps.show', $a->key)])->all();

        return response()->json($out);
    }
}
