<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An app's own sign-in address. Lifecycle:
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
            'verified_at' => 'datetime', 'reachable_at' => 'datetime', 'active_at' => 'datetime', 'last_checked_at' => 'datetime',
        ];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'app_id');
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
