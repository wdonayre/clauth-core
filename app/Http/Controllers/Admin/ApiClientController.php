<?php

namespace App\Http\Controllers\Admin;

use App\Models\OAuthClient;
use App\Models\Organization;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Organization › API clients (SPEC-platform §5.1). Org owners and admins make clients for their organization;
 * Clauth owners also make platform clients ({org} = "platform"). A client acts as the person who made it.
 */
class ApiClientController
{
    use Scope;

    /** null = platform. */
    private function org(string $org): ?Organization
    {
        if ($org === 'platform') {
            abort_unless(auth()->user()->isOwner(), 404);

            return null;
        }
        abort_unless($this->canManageOrg($org), 404);

        return Organization::findOrFail($org);
    }

    private function scopes(?Organization $org): array
    {
        return array_values(array_diff(config('clauth.api.scopes'), $org ? config('clauth.api.platform_only') : []));
    }

    public function index(string $org)
    {
        $o = $this->org($org);

        return view('admin.api-clients', [
            'org' => $o, 'orgKey' => $o?->id ?? 'platform',
            'clients' => OAuthClient::with('creator')->where('type', 'api')->where('organization_id', $o?->id)->latest()->get(),
            'scopes' => collect($this->scopes($o))->mapWithKeys(fn ($s) => [$s => config("clauth.scopes.$s")]),
        ]);
    }

    public function store(Request $request, string $org)
    {
        $o = $this->org($org);
        $data = $request->validate(['name' => 'required|string|max:80', 'scopes' => 'required|array|min:1', 'scopes.*' => Rule::in($this->scopes($o))]);
        $secret = Str::random(48);
        $c = new OAuthClient;
        $c->forceFill([
            'type' => 'api', 'name' => $data['name'], 'secret' => $secret, 'organization_id' => $o?->id, 'created_by' => $request->user()->id,
            'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'scopes' => array_values($data['scopes']), 'revoked' => false,
            'secret_expires_at' => now()->addDays(config('clauth.api.secret_days')),
        ])->save();
        Audit::log('api.client_created', 'admin', $c, after: ['name' => $c->name, 'scopes' => $c->scopes, 'org' => $o?->slug ?? 'platform'], orgId: $o?->id);

        return back()->with('created_client', ['id' => $c->id, 'secret' => $secret, 'name' => $c->name, 'scopes' => implode(' ', $c->scopes)]);
    }

    /**
     * SCIM provisioning (SPEC-platform §9.2): an API client with only the scim scope plus one bearer token valid for
     * a year, because HR systems and identity providers take a fixed token. Making a new one turns the old one off.
     */
    public function scim(Request $request, string $org)
    {
        $o = $this->org($org);
        abort_unless($o, 404);
        foreach (OAuthClient::where('type', 'api')->where('organization_id', $o->id)->where('name', 'SCIM provisioning')->where('revoked', false)->get() as $old) {
            $old->forceFill(['revoked' => true])->save();
            DB::table('oauth_access_tokens')->where('client_id', $old->id)->update(['revoked' => true]);
        }
        $c = new OAuthClient;
        $c->forceFill(['type' => 'api', 'name' => 'SCIM provisioning', 'secret' => Str::random(48), 'organization_id' => $o->id, 'created_by' => $request->user()->id,
            'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'scopes' => ['scim'], 'revoked' => false, 'secret_expires_at' => now()->addYear()])->save();
        [, $token] = \App\Services\PersonalTokens::mint($c, null, 'SCIM token', ['scim'], 365, 'scim');
        Audit::log('api.scim_token_created', 'admin', $c, orgId: $o->id);

        return back()->with('scim_token', ['url' => rtrim(config('clauth.issuer'), '/').'/scim/v2', 'token' => $token]);
    }

    public function rotate(string $org, string $client)
    {
        $o = $this->org($org);
        $c = $this->client($o, $client);
        $secret = Str::random(48);
        $c->forceFill(['secret' => $secret, 'secret_expires_at' => now()->addDays(config('clauth.api.secret_days'))])->save();
        DB::table('oauth_access_tokens')->where('client_id', $c->id)->update(['revoked' => true]);
        Audit::log('api.client_rotated', 'admin', $c, orgId: $o?->id);

        return back()->with('created_client', ['id' => $c->id, 'secret' => $secret, 'name' => $c->name, 'scopes' => implode(' ', $c->scopes ?? [])]);
    }

    public function revoke(string $org, string $client)
    {
        $o = $this->org($org);
        $c = $this->client($o, $client);
        $c->forceFill(['revoked' => true])->save();
        DB::table('oauth_access_tokens')->where('client_id', $c->id)->update(['revoked' => true]);
        Audit::log('api.client_revoked', 'admin', $c, orgId: $o?->id);

        return back()->with('status', "“{$c->name}” is turned off. Its tokens stopped working.");
    }

    private function client(?Organization $o, string $id): OAuthClient
    {
        return OAuthClient::where('type', 'api')->where('organization_id', $o?->id)->findOrFail($id);
    }
}
