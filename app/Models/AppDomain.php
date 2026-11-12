<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A custom sign-in address (e.g. auth.alabapparel.com). Owned by the organization that verified it and shared by
 * one or more of that organization's apps. "trusted" = people signed in on the main domain are signed in here
 * silently; on an untrusted domain they confirm first. Lifecycle:
 *   pending   — waiting for the TXT record (_clauth.<host>)
 *   dns_ok    — TXT verified; the server job adds the host to nginx
 *   reachable — Clauth answered its own proof URL through the host; the server job requests a certificate
 *   active    — valid HTTPS: sign-in pages are served here
 *   problem   — the daily check failed twice (DNS gone or certificate broken); not served until it passes again
 */
class AppDomain extends Model
{
    public const SERVED = ['active'];

    public const IN_NGINX = ['dns_ok', 'reachable', 'active', 'problem'];

    public const IN_CERT = ['reachable', 'active', 'problem'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'trusted' => 'boolean', 'verified_at' => 'datetime', 'reachable_at' => 'datetime', 'active_at' => 'datetime', 'last_checked_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** The apps that sign people in here (oldest link first). */
    public function apps(): BelongsToMany
    {
        return $this->belongsToMany(Application::class, 'app_domain_app', 'app_domain_id', 'app_id')
            ->withPivot('created_by')->withTimestamps()->orderBy('app_domain_app.created_at')->orderBy('apps.name');
    }

    /** Silent single sign-on from the main domain: always for William's own (platform) organizations, else only by override. */
    public function isTrusted(): bool
    {
        return (bool) $this->organization?->is_platform || $this->trusted;
    }

    public function serves(?Application $app): bool
    {
        return $app && $this->apps->contains('id', $app->id);
    }

    /** The app whose look is used when the page isn't for a specific app: the only app, else the first one linked. */
    public function brandApp(): ?Application
    {
        return $this->apps->first();
    }

    /** What the page says when it isn't for a specific app ("Continue to Alab Apparel"). */
    public function brandName(): string
    {
        return $this->display_name ?: ($this->apps->count() === 1 ? $this->apps->first()->name : ($this->organization?->name ?? $this->host));
    }

    /** First linked app, for audit rows. */
    public function firstAppId(): ?string
    {
        return $this->apps()->value('apps.id');
    }

    public function txtName(): string
    {
        return '_clauth.'.$this->host;
    }

    public function txtValue(): string
    {
        return 'clauth-verify='.$this->verify_token;
    }

    public function proofPath(): string
    {
        return '/.well-known/clauth-domain/'.$this->verify_token;
    }

    /** What Clauth answers on the proof path — only the real server can produce it. */
    public function proofBody(): string
    {
        return hash_hmac('sha256', 'clauth-domain:'.$this->host.':'.$this->verify_token, (string) config('app.key'));
    }

    public function isServed(): bool
    {
        return in_array($this->status, self::SERVED, true);
    }

    public function label(): string
    {
        return [
            'pending' => 'Waiting for DNS', 'dns_ok' => 'DNS verified — connecting', 'reachable' => 'Getting certificate',
            'active' => 'Active', 'problem' => 'Problem',
        ][$this->status] ?? $this->status;
    }
}
