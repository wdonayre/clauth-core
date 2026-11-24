<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiContext;
use App\Api\Present;
use App\Models\AuditEvent;
use App\Models\SigningKey;
use App\Services\Audit;
use App\Services\Settings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** /api/v1 capabilities, audit, settings (SPEC-platform §5.1, §6.1). */
class MiscController
{
    use UsesApi;

    /** What this token may do — for display only; every endpoint still checks. */
    public function capabilities()
    {
        $c = $this->api()->client;

        return response()->json([
            'client' => ['id' => $c->id, 'name' => $c->name, 'kind' => $this->api()->isPlatform() ? 'platform' : 'organization'],
            'acts_as' => $this->api()->actor->sub(),
            'scopes' => $this->api()->scopes,
            'orgs' => $this->api()->isPlatform() ? '*' : [$c->organization?->slug],
            'read_only' => (bool) $c->organization?->isSuspended(),
        ]);
    }

    public function audit(Request $request)
    {
        $request->validate(['category' => 'nullable|in:signin,security,role,admin,app', 'since' => 'nullable|date', 'until' => 'nullable|date']);
        $ids = $this->api()->orgIds();
        $q = AuditEvent::with(['app', 'organization'])
            ->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($request->query('event'), fn ($q, $e) => $q->where('event', $e))
            ->when($request->query('org'), fn ($q, $o) => $q->where('organization_id', $this->api()->org($o)->id))
            ->when($request->query('user'), fn ($q, $u) => $q->where(fn ($w) => $w->where('actor_user_id', $id = $this->api()->user($u)->id)
                ->orWhere(fn ($s) => $s->where('subject_type', 'user')->where('subject_id', $id))))
            ->when($request->query('since'), fn ($q, $d) => $q->where('at', '>=', Carbon::parse($d)))
            ->when($request->query('until'), fn ($q, $d) => $q->where('at', '<', Carbon::parse($d)));

        return Present::page($q, fn (AuditEvent $e) => Present::audit($e), $request);
    }

    public function settings()
    {
        abort_unless($this->api()->isPlatform(), 403, 'Settings are for platform clients only.');

        return response()->json(Settings::all());
    }

    public function updateSettings(Request $request)
    {
        abort_unless($this->api()->isPlatform(), 403, 'Settings are for platform clients only.');
        $data = $request->validate([
            'require_mfa_everyone' => 'sometimes|boolean', 'require_mfa_admin_tiers' => 'sometimes|boolean',
            'password_min_length' => 'sometimes|integer|min:8|max:64', 'breached_password_check' => 'sometimes|boolean',
            'lockout_attempts' => 'sometimes|integer|min:3|max:20', 'lockout_minutes' => 'sometimes|integer|min:1|max:1440',
            'session_idle_hours' => 'sometimes|integer|min:1|max:72', 'trusted_device_days' => 'sometimes|integer|min:0|max:90',
            'new_device_email' => 'sometimes|boolean', 'support_email' => 'sometimes|email',
        ]);
        // legal_reviewed stays a console-only switch: it opens Clauth to outside organizations.
        $before = array_intersect_key(Settings::all(), $data);
        Settings::set($data);
        Audit::log('settings.updated', 'admin', null, $before, $data);

        return response()->json(Settings::all());
    }

    /** GET /health/live (no auth) — the process answers. */
    public static function live()
    {
        return response()->json(['status' => 'ok'], 200, ['Cache-Control' => 'no-store']);
    }

    /** GET /health/ready (no auth) — database, cache and an active signing key. Details stay vague in public. */
    public static function ready()
    {
        $checks = [
            'database' => rescue(fn () => DB::select('select 1') !== null, false, report: false),
            'cache' => rescue(fn () => cache()->put('health:ping', 1, 10) && cache()->get('health:ping') === 1, false, report: false),
            'signing_key' => rescue(fn () => SigningKey::where('status', 'active')->exists(), false, report: false),
        ];
        $ok = ! in_array(false, $checks, true);

        return response()->json(['status' => $ok ? 'ok' : 'fail', 'checks' => array_map(fn ($v) => $v ? 'ok' : 'fail', $checks)], $ok ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}
