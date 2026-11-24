<?php

namespace App\Api;

use App\Models\AccessProfile;
use App\Models\Application;
use App\Models\OAuthClient;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who is calling /api/v1 (SPEC-platform §5): the API client, the scopes on its token and the person it acts for
 * (the one who created it). Org clients see their own organization only; anything else answers 404.
 */
class ApiContext
{
    public function __construct(
        public readonly OAuthClient $client,
        public readonly User $actor,
        public readonly array $scopes,
    ) {}

    public function isPlatform(): bool
    {
        return $this->client->isPlatformApi();
    }

    /** Organization ids this call may touch; null = every organization (platform client). */
    public function orgIds(): ?array
    {
        return $this->isPlatform() ? null : [$this->client->organization_id];
    }

    public function has(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function canOrg(string $orgId): bool
    {
        $ids = $this->orgIds();

        return $ids === null || in_array($orgId, $ids, true);
    }

    /** By id or slug; 404 when it doesn't exist or is outside this client's reach. */
    public function org(string $ref): Organization
    {
        $org = Organization::where('id', $ref)->orWhere('slug', $ref)->first();
        abort_unless($org && $this->canOrg($org->id), 404, 'Organization not found.');

        return $org;
    }

    public function scopeUsers(Builder $q): Builder
    {
        $ids = $this->orgIds();

        return $ids === null ? $q : $q->whereHas('organizations', fn ($o) => $o->whereIn('organizations.id', $ids));
    }

    /** By sub (usr_…), id or email; 404 when not visible. */
    public function user(string $ref): User
    {
        $q = $this->scopeUsers(User::query());
        $user = str_contains($ref, '@') ? $q->where('email', mb_strtolower($ref))->first()
            : $q->whereKey(str_starts_with($ref, 'usr_') ? substr($ref, 4) : $ref)->first();
        abort_unless($user, 404, 'User not found.');

        return $user;
    }

    /** Apps this client can see: published by or installed in its organization (platform: all). */
    public function scopeApps(Builder $q): Builder
    {
        $ids = $this->orgIds();

        return $ids === null ? $q : $q->where(fn ($w) => $w->whereIn('owner_organization_id', $ids)
            ->orWhereHas('organizations', fn ($o) => $o->whereIn('organizations.id', $ids)));
    }

    public function app(string $key): Application
    {
        $app = $this->scopeApps(Application::query())->where('key', $key)->first();
        abort_unless($app, 404, 'App not found.');

        return $app;
    }

    /** Apps whose settings (clients, manifest) this client may change: the ones its organization publishes. */
    public function canManageApp(Application $app): bool
    {
        return $this->isPlatform() || $app->owner_organization_id === $this->client->organization_id;
    }

    /** Access profile by key or id inside $org. */
    public function profile(Organization $org, string $ref): AccessProfile
    {
        $p = AccessProfile::where('organization_id', $org->id)
            ->where(fn ($w) => $w->where('key', $ref)->when(ctype_digit($ref), fn ($x) => $x->orWhere('id', (int) $ref)))->first();
        abort_unless($p, 404, 'Access profile not found.');

        return $p;
    }
}
