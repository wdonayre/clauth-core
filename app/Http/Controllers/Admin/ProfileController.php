<?php

namespace App\Http\Controllers\Admin;

use App\Models\AccessProfile;
use App\Models\AppRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Limits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Organization › Access profiles (SPEC-roles §9.1). Org owners and admins; owner-tier roles need an org owner. */
class ProfileController
{
    use Scope;

    private function org(string $org): Organization
    {
        abort_unless($this->canManageOrg($org), 404);

        return Organization::findOrFail($org);
    }

    private function profile(Organization $org, string $profile): AccessProfile
    {
        abort_unless(ctype_digit($profile), 404);

        return AccessProfile::where('organization_id', $org->id)->findOrFail((int) $profile);
    }

    public function index(string $org)
    {
        $org = $this->org($org);
        $apps = $org->apps()->get();
        $profiles = $org->accessProfiles()->withCount('users')->get();
        $mapped = DB::table('access_profile_roles')->whereIn('access_profile_id', $profiles->pluck('id'))
            ->select('access_profile_id', DB::raw('count(*) as n'))->groupBy('access_profile_id')->pluck('n', 'access_profile_id');

        return view('admin.profiles.index', ['org' => $org, 'profiles' => $profiles, 'apps' => $apps, 'mapped' => $mapped,
            'unmapped' => Access::unmappedApps($org), 'suggestionCount' => count(Access::suggestions($org, auth()->user()))]);
    }

    public function store(Request $request, string $org)
    {
        $org = $this->org($org);
        $data = $request->validate(['name' => 'required|string|max:80', 'description' => 'nullable|string|max:190']);
        if ($msg = Limits::exceeded($org, 'profiles')) {
            return back()->withErrors(['name' => $msg])->withInput();
        }
        $base = Str::slug($data['name']) ?: 'profile';
        $key = $base;
        for ($i = 2; AccessProfile::where('organization_id', $org->id)->where('key', $key)->exists(); $i++) {
            $key = "$base-$i";
        }
        $p = AccessProfile::create($data + ['organization_id' => $org->id, 'key' => $key, 'created_by' => $request->user()->id,
            'position' => AccessProfile::where('organization_id', $org->id)->count()]);
        Audit::log('profile.created', 'role', $p, after: $p->only('key', 'name'), orgId: $org->id);

        return redirect()->route('admin.profiles.show', [$org->id, $p->id])->with('status', "Profile “{$p->name}” created. Pick a role for each app.");
    }

    public function show(string $org, string $profile)
    {
        $org = $this->org($org);
        $p = $this->profile($org, $profile);
        $me = auth()->user();
        $holders = $p->users()->orderBy('name')->get();

        return view('admin.profiles.show', [
            'org' => $org, 'profile' => $p, 'mapping' => $p->mapping(),
            'apps' => $org->apps()->with(['roles' => fn ($q) => $q->orderBy('position')])->orderBy('name')->get(),
            'holders' => $holders,
            'members' => $org->users()->whereNotIn('users.id', $holders->pluck('id'))->where('status', '!=', 'disabled')->orderBy('name')->get(),
            'canOwner' => Access::canTouchOwnerTier($me, $org->id),
            'tiers' => AppRole::TIERS,
        ]);
    }

    /** Name, description and the role per app. A select that isn't sent (disabled owner-tier one) stays as it is. */
    public function update(Request $request, string $org, string $profile)
    {
        $org = $this->org($org);
        $p = $this->profile($org, $profile);
        $data = $request->validate(['name' => 'required|string|max:80', 'description' => 'nullable|string|max:190', 'map' => 'array']);
        $me = $request->user();
        $changes = [];
        foreach ($org->apps()->with('roles')->get() as $app) {
            if (array_key_exists($app->id, $data['map'] ?? []) && ($c = Access::mapApp($p, $app, (string) $data['map'][$app->id], $me))) {
                $changes[$app->key] = $c;
            }
        }
        $p->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);
        if ($changes) {
            Audit::log('profile.mapping_changed', 'role', $p, null, $changes, orgId: $org->id);
            $n = Access::resolveHolders($p, "profile {$p->key} changed", $me);
        }

        return back()->with('status', 'Profile saved.'.($changes ? ' '.($n ?? 0).' access change(s) for the people who hold it; apps pick them up within 15 minutes.' : ''));
    }

    public function destroy(Request $request, string $org, string $profile)
    {
        $org = $this->org($org);
        $p = $this->profile($org, $profile);
        abort_if($p->hasOwnerTier() && ! Access::canTouchOwnerTier($request->user(), $org->id), 403, "Only an owner of {$org->name} can delete a profile with an Owner-tier role.");
        $holders = $p->users()->get();
        $p->delete();
        foreach ($holders as $u) {
            Access::resolve($u, $org, "profile {$p->key} deleted", $request->user());
        }
        Audit::log('profile.deleted', 'role', $p, before: $p->only('key', 'name') + ['holders' => $holders->count()], orgId: $org->id);

        return redirect()->route('admin.profiles.index', $org->id)->with('status', "Profile “{$p->name}” deleted.");
    }

    public function addPerson(Request $request, string $org, string $profile)
    {
        $org = $this->org($org);
        $p = $this->profile($org, $profile);
        $user = $org->users()->where('users.id', $request->input('user_id'))->firstOrFail();
        $ids = $user->accessProfiles()->where('organization_id', $org->id)->pluck('access_profiles.id')->push($p->id)->all();
        Access::setProfiles($user, $org, $ids, $request->user());

        return back()->with('status', "{$user->name} now has the {$p->name} profile.");
    }

    public function removePerson(Request $request, string $org, string $profile, string $user)
    {
        $org = $this->org($org);
        $p = $this->profile($org, $profile);
        $u = User::findOrFail($user);
        $ids = $u->accessProfiles()->where('organization_id', $org->id)->pluck('access_profiles.id')->reject(fn ($id) => $id === $p->id)->all();
        Access::setProfiles($u, $org, $ids, $request->user());

        return back()->with('status', "{$u->name} no longer has the {$p->name} profile.");
    }

    /** One app × every profile, pre-filled with suggestions for the profiles that haven't set it (SPEC-roles §9.2). */
    public function app(string $org, string $app)
    {
        $org = $this->org($org);
        $a = $org->apps()->with(['roles' => fn ($q) => $q->orderBy('position')])->where('apps.key', $app)->firstOrFail();
        $rows = $org->accessProfiles()->get()->map(function (AccessProfile $p) use ($a) {
            $m = $p->mapping();
            $set = array_key_exists($a->id, $m);

            return ['profile' => $p, 'set' => $set, 'value' => $set ? (string) ($m[$a->id] ?? 'none') : (string) (Access::suggestFor($p, $a)?->id ?? '')];
        });

        return view('admin.profiles.app', ['org' => $org, 'app' => $a, 'rows' => $rows, 'canOwner' => Access::canTouchOwnerTier(auth()->user(), $org->id), 'tiers' => AppRole::TIERS]);
    }

    public function saveApp(Request $request, string $org, string $app)
    {
        $org = $this->org($org);
        $a = $org->apps()->with('roles')->where('apps.key', $app)->firstOrFail();
        $request->validate(['map' => 'array']);
        $n = 0;
        foreach ($org->accessProfiles()->get() as $p) {
            if (! array_key_exists($p->id, $request->input('map', []))) {
                continue;
            }
            if ($c = Access::mapApp($p, $a, (string) $request->input("map.{$p->id}"), $request->user())) {
                Audit::log('profile.mapping_changed', 'role', $p, null, [$a->key => $c], orgId: $org->id);
                $n += Access::resolveHolders($p, "profile {$p->key} changed", $request->user());
            }
        }

        return redirect()->route('admin.profiles.index', $org->id)->with('status', "{$a->name} saved for every profile. {$n} access change(s).");
    }

    /** Suggest profiles from current access (SPEC-roles §11 step 3). */
    public function suggest(string $org)
    {
        $org = $this->org($org);

        return view('admin.profiles.suggest', ['org' => $org, 'suggestions' => Access::suggestions($org, auth()->user()), 'tiers' => AppRole::TIERS]);
    }

    public function acceptSuggestion(Request $request, string $org)
    {
        $org = $this->org($org);
        $data = $request->validate(['key' => 'required|string|size:40', 'name' => 'required|string|max:80']);
        if ($msg = Limits::exceeded($org, 'profiles')) {
            return back()->withErrors(['name' => $msg]);
        }
        $p = Access::acceptSuggestion($org, $data['key'], $data['name'], $request->user());

        return back()->with('status', "Profile “{$p->name}” created and given to {$p->users()->count()} ".Str::plural('person', $p->users()->count()).'. Their access is unchanged; the matching exceptions are gone.');
    }

    /** People × apps, with where each role comes from (SPEC-roles §9.4). */
    public function alignment(Request $request, string $org)
    {
        $org = $this->org($org);
        [$apps, $rows] = $this->matrix($org);
        $filter = $request->query('filter', 'all');
        $rows = $rows->filter(fn ($r) => match ($filter) {
            'exceptions' => collect($r['cells'])->contains(fn ($c) => $c['override']),
            'conflicts' => collect($r['cells'])->contains(fn ($c) => $c['conflict']),
            'notset' => collect($r['cells'])->contains(fn ($c) => $c['unmapped']),
            'noprofile' => $r['profiles']->isEmpty(),
            default => true,
        })->values();

        return view('admin.profiles.alignment', ['org' => $org, 'apps' => $apps, 'rows' => $rows, 'filter' => $filter]);
    }

    public function alignmentCsv(string $org)
    {
        $org = $this->org($org);
        [$apps, $rows] = $this->matrix($org);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_merge(['Name', 'Email', 'Profiles'], $apps->pluck('name')->all()));
        foreach ($rows as $r) {
            fputcsv($out, array_merge([$r['user']->name, $r['user']->email, $r['profiles']->pluck('name')->implode(', ')],
                $apps->map(fn ($a) => ($r['cells'][$a->id]['role']?->name ?? 'No access').($r['cells'][$a->id]['override'] ? ' (exception)' : ''))->all()));
        }
        rewind($out);

        return response(stream_get_contents($out), 200, ['Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$org->slug.'-access-'.now()->format('Y-m-d').'.csv"']);
    }

    private function matrix(Organization $org): array
    {
        $apps = $org->apps()->orderBy('name')->get();
        $rows = $org->users()->where('status', '!=', 'disabled')->orderBy('name')->get()->map(fn (User $u) => [
            'user' => $u,
            'profiles' => $u->accessProfiles()->where('organization_id', $org->id)->orderBy('name')->get(),
            'cells' => collect(Access::explain($u, $org))->map(fn ($e) => [
                'role' => $e['role'], 'override' => $e['override'] !== null, 'conflict' => $e['conflict'], 'unmapped' => $e['unmapped'] !== [],
                'via' => $e['via'], 'reason' => $e['override']?->reason,
            ])->all(),
        ]);

        return [$apps, $rows];
    }
}
