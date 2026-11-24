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

    /** A person's own token (My account › API tokens) rather than an API client. */
    public function isPersonal(): bool
    {
        return $this->client->type === 'personal';
    }

    /** Reaches every organization and the platform settings: platform clients, and Clauth owners' personal tokens. */
    public function isPlatform(): bool
    {
        return $this->client->isPlatformApi() || ($this->isPersonal() && $this->actor->isOwner());
    }

    /** Organization ids this call may touch; null = every organization. A person's token: the orgs they run. */
    public function orgIds(): ?array
    {
        if ($this->isPlatform()) {
            return null;
        }

        return $this->isPersonal() ? $this->actor->adminOrgIds() : [$this->client->organization_id];
    }

    /** Organizations whose apps this call may change. */
    public function publisherOrgIds(): ?array
    {
        if ($this->isPlatform()) {
            return null;
        }

        return $this->isPersonal() ? $this->actor->devOrgIds() : [$this->client->organization_id];
    }

    /** Key for rate limits and idempotency: the client, or the person for personal tokens. */
    public function callerKey(): string
    {
        return $this->isPersonal() ? 'p:'.$this->actor->id : 'c:'.$this->client->id;
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
        abort_if($org->isSuspended() && ! $this->isPlatform() && ! in_array(request()->method(), ['GET', 'HEAD'], true), 403,
            'This organization is suspended. The API is read-only for it until Clauth restores it.');

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
        $ids = $this->orgIds() === null ? null : array_values(array_unique(array_merge($this->orgIds(), $this->publisherOrgIds() ?? [])));

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
        return $this->isPlatform() || in_array($app->owner_organization_id, $this->publisherOrgIds(), true);
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
