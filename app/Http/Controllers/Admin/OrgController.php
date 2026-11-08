<?php

namespace App\Http\Controllers\Admin;

use App\Models\Application;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrgController
{
    use Scope;

    public function index()
    {
        return view('admin.orgs.index', [
            'orgs' => Organization::withCount('users')->with(['apps', 'users' => fn ($q) => $q->wherePivot('is_org_admin', true)])
                ->when($this->orgIds() !== null, fn ($q) => $q->whereIn('id', $this->orgIds()))->orderBy('name')->get(),
            'apps' => Application::orderBy('name')->get(),
            'canEdit' => auth()->user()->isOwner(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'slug' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{1,60}$/', Rule::unique('organizations', 'slug')]]);
        $org = Organization::create($data);
        Audit::log('org.created', 'admin', $org, after: $data, orgId: $org->id);

        return back()->with('status', "{$org->name} created.");
    }

    public function update(Request $request, Organization $org)
    {
        $before = $org->only('name', 'status') + ['apps' => $org->apps()->pluck('apps.id')->all()];
        $data = $request->validate(['name' => 'required|string|max:120', 'status' => 'required|in:active,disabled', 'apps' => 'array', 'apps.*' => 'exists:apps,id']);
        $org->update(['name' => $data['name'], 'status' => $data['status']]);
        $org->apps()->sync($data['apps'] ?? []);
        Audit::log('org.updated', 'admin', $org, $before, $org->only('name', 'status') + ['apps' => $data['apps'] ?? []], orgId: $org->id);

        return back()->with('status', "{$org->name} saved.");
    }

    public function admin(Request $request, Organization $org)
    {
        $data = $request->validate(['email' => 'required|email', 'is_org_admin' => 'boolean']);
        $user = User::where('email', mb_strtolower($data['email']))->first();
        if (! $user || ! $user->organizations->contains('id', $org->id)) {
            return back()->withErrors(['email' => 'That person isn’t a member of '.$org->name.'. Invite them first.']);
        }
        $user->organizations()->updateExistingPivot($org->id, ['is_org_admin' => $request->boolean('is_org_admin', true)]);
        Audit::log('user.updated', 'admin', $user, after: ['org_admin' => $request->boolean('is_org_admin', true), 'org' => $org->slug], orgId: $org->id);

        return back()->with('status', 'Org admins updated.');
    }
}
