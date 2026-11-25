<?php

namespace App\Http\Controllers\Admin;

use App\Models\AppRole;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Audit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class AuditController
{
    use Scope;

    /** Activity "kind" filter → audit category. Keys are the query-string values (?cat=). */
    public const KINDS = ['all' => 'Everything', 'signin' => 'Sign-ins', 'role' => 'Access changes', 'admin' => 'People & settings', 'security' => 'Security', 'app' => 'Apps'];

    public const RANGES = ['' => 'Any time', 'today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', 'custom' => 'Custom dates'];

    /** Apps this admin may see (same rule as the overview). */
    private function visibleApps()
    {
        $ids = $this->orgIds();
        $me = auth()->user();

        return Application::query()->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('owner_organization_id', $me->devOrgIds())
            ->orWhereHas('organizations', fn ($o) => $o->whereIn('organizations.id', $ids))))->orderBy('name');
    }

    private function query(Request $request)
    {
        $ids = $this->orgIds();
        [$from, $to] = $this->dates($request);
        $app = (string) $request->query('app');

        return AuditEvent::with(['actor', 'app', 'organization'])
            ->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))
            ->when(in_array($request->query('cat'), ['signin', 'security', 'role', 'admin', 'app'], true), fn ($q) => $q->where('category', $request->query('cat')))
            ->when($app !== '', fn ($q) => $q->whereIn('app_id', $this->visibleApps()->where('key', $app)->pluck('id')))
            ->when($from, fn ($q, $d) => $q->where('at', '>=', $d))
            ->when($to, fn ($q, $d) => $q->where('at', '<', $d))
            ->when(trim((string) $request->query('q')), fn ($q, $s) => $q->where(fn ($w) => $w->where('event', 'like', "%$s%")->orWhere('ip', 'like', "%$s%")
                ->orWhereHas('actor', fn ($a) => $a->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%"))
                ->orWhereHas('app', fn ($a) => $a->where('name', 'like', "%$s%"))
                ->orWhere(fn ($u) => $u->where('subject_type', 'user')->whereIn('subject_id', User::where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%")->select('id')))))
            ->latest('at');
    }

    /** [from, to) from ?range= presets or the custom ?from= / ?to= dates. */
    private function dates(Request $request): array
    {
        $range = (string) $request->query('range');
        $parse = function ($v) {
            try {
                return $v ? Carbon::parse($v) : null;
            } catch (\Throwable) {
                return null;
            }
        };

        return match ($range) {
            'today' => [now()->startOfDay(), null],
            '7d' => [now()->subDays(7), null],
            '30d' => [now()->subDays(30), null],
            '90d' => [now()->subDays(90), null],
            default => [$parse($request->query('from'))?->startOfDay(), $parse($request->query('to'))?->startOfDay()->addDay()],
        };
    }

    public function index(Request $request)
    {
        $events = $this->query($request)->paginate(50)->withQueryString();
        self::prime($events->getCollection());
        $range = (string) $request->query('range');
        if ($range === '' && ($request->query('from') || $request->query('to'))) {
            $range = 'custom';
        }

        return view('admin.audit', [
            'events' => $events,
            'cat' => array_key_exists((string) $request->query('cat'), self::KINDS) ? $request->query('cat') : 'all',
            'apps' => $this->visibleApps()->get(['id', 'key', 'name']),
            'range' => array_key_exists($range, self::RANGES) ? $range : '',
        ]);
    }

    public function export(Request $request)
    {
        $rows = $this->query($request)->limit(20000)->get();
        self::prime($rows);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['time_utc', 'event', 'label', 'summary', 'category', 'actor', 'actor_type', 'subject', 'app', 'org', 'ip', 'before', 'after']);
            foreach ($rows as $e) {
                fputcsv($out, [$e->at->toIso8601String(), $e->event, Audit::label($e->event), html_entity_decode(strip_tags((string) self::describe($e)['text']), ENT_QUOTES),
                    $e->category, $e->actor?->email, $e->actor_type,
                    $e->subject_type ? "$e->subject_type:$e->subject_id" : '', $e->app?->key, $e->organization?->slug, $e->ip,
                    $e->before ? json_encode($e->before) : '', $e->after ? json_encode($e->after) : '']);
            }
            fclose($out);
        }, 'clauth-audit-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    // ------------------------------------------------------------------
    // Presenter: an audit event as a sentence people can read.
    // ------------------------------------------------------------------

    /** @var array<string, User> */
    private static array $users = [];

    /** @var array<string, string> "appId|key" => role name */
    private static array $roles = [];

    /** Load the people and roles the sentences mention, in two queries. */
    public static function prime(iterable $events): void
    {
        $events = collect($events);
        $ids = $events->where('subject_type', 'user')->pluck('subject_id')->filter()->unique()->diff(array_keys(self::$users));
        if ($ids->isNotEmpty()) {
            foreach (User::withTrashed()->whereIn('id', $ids->all())->get() as $u) {
                self::$users[$u->id] = $u;
            }
        }
        $pairs = $events->filter(fn ($e) => $e->app_id && (isset($e->after['role']) || isset($e->before['role'])));
        $appIds = $pairs->pluck('app_id')->unique()->all();
        if ($appIds) {
            foreach (AppRole::whereIn('app_id', $appIds)->get(['app_id', 'key', 'name']) as $r) {
                self::$roles[$r->app_id.'|'.$r->key] = $r->name;
            }
        }
    }

    /**
     * @return array{text: HtmlString, detail: ?HtmlString, icon: string, tone: string, initials: string}
     */
    public static function describe(AuditEvent $e): array
    {
        $me = auth()->user();
        $a = $e->after ?? [];
        $b = $e->before ?? [];
        $bold = fn ($s) => '<b>'.e((string) $s).'</b>';
        $badge = fn ($s, $tone = 'info') => '<span class="badge '.$tone.'">'.e((string) $s).'</span>';

        $subject = $e->subject_type === 'user' ? (self::$users[$e->subject_id] ?? null) : null;
        $selfAct = $e->actor && $subject && $e->actor->id === $subject->id;
        $actorName = match (true) {
            $e->actor && $me && $e->actor->id === $me->id => 'You',
            (bool) $e->actor => $e->actor->name ?: $e->actor->email,
            $e->actor_type === 'api' => 'An API client',
            $e->actor_type === 'app' => $e->app?->name ?? 'An app',
            default => 'Clauth',
        };
        $actor = $bold($actorName);
        $subjName = $subject ? ($me && $subject->id === $me->id ? 'you' : ($subject->name ?: $subject->email)) : null;
        $who = $subject ? $bold($subjName === 'you' ? 'You' : $subjName) : $actor;
        $subj = $subject ? $bold($subjName) : 'someone';
        $subjPoss = $subject ? $bold($subjName === 'you' ? 'your' : $subjName.'’s') : 'someone’s';
        $app = $e->app ? $bold($e->app->name) : (isset($a['app']) ? $bold($a['app']) : 'an app');
        $inApp = $e->app ? ' in '.e($e->app->name) : '';
        $org = $e->organization ? ' · '.e($e->organization->name) : '';
        $inOrg = $e->organization ? ' in '.e($e->organization->name) : '';
        $role = fn ($k) => $k === null ? null : (self::$roles[$e->app_id.'|'.$k] ?? Str::headline((string) $k));
        $name = $a['name'] ?? $a['key'] ?? $a['created'] ?? $b['name'] ?? $b['key'] ?? $b['deleted'] ?? null;
        $device = self::device($e);
        $join = fn (...$parts) => implode(' · ', array_filter($parts, fn ($p) => $p !== null && $p !== ''));

        [$text, $detail] = match ($e->event) {
            'signin.success' => ["$who signed in to $app", $join(self::methods($a), $device)],
            'signin.code' => ["$who signed in with a one-time code", $join(isset($a['via']) ? 'Sent by '.e($a['via']) : null, $device)],
            'signin.failed' => ["Wrong password for $who", $join(isset($a['attempts']) ? e($a['attempts']).' failed '.Str::plural('try', (int) $a['attempts']).' in a row' : null, $device)],
            'signin.denied' => ["$who was turned away from $app", ($a['by'] ?? null) === 'policy check' ? 'Blocked by the sign-in rules' : 'No access to this app'],
            'signin.locked' => ["$who was locked out after too many wrong passwords", $join(isset($a['until']) ? 'Until '.e(Carbon::parse($a['until'])->format('H:i')) : null, $e->ip ? 'from '.e($e->ip) : null)],
            'signin.ciba_requested' => ["$app asked $subj to approve a sign-in", isset($a['message']) ? e($a['message']) : null],
            'signin.ciba_denied' => ["$who declined a sign-in request from $app", null],
            'security.mfa_enabled' => ["$who turned on 2-step verification", null],
            'security.mfa_reset' => ["$actor reset 2-step verification for $subj", null],
            'security.mfa_skipped' => ["$who skipped setting up 2-step verification", null],
            'security.mfa_exempted' => ["$actor let $subj sign in without 2-step", null],
            'security.mfa_exemption_removed' => ["$actor asked $subj to use 2-step again", null],
            'security.sms_two_step_enabled' => ["$who turned on 2-step by text message", null],
            'security.phone_verified' => ["$who confirmed their phone number", null],
            'security.recovery_code_used' => ["$who used a recovery code", $device],
            'security.password_changed' => ["$who changed their password", $device],
            'security.password_reset' => ["$who reset their password", $device],
            'security.password_reset_sent' => ["$actor sent $subj a password reset link", null],
            'security.signout_everywhere' => [$selfAct || ! $subject ? "$who signed out everywhere" : "$actor signed $subj out everywhere", null],
            'security.session_ended' => ["$actor ended a session for $subj", null],
            'security.refresh_reuse' => ["A reused sign-in token was caught for $subj", 'Their sessions were ended to be safe'],
            'security.social_linked' => ["$who linked a ".e(Str::headline((string) ($a['provider'] ?? 'social'))).' account', null],
            'security.social_unlinked' => ["$who unlinked a ".e(Str::headline((string) ($a['provider'] ?? $b['provider'] ?? 'social'))).' account', null],
            'security.key_staged' => ["$actor staged a new signing key", null],
            'security.key_activated' => ["$actor switched to a new signing key", null],
            'security.key_retired' => ["$actor retired an old signing key", null],
            'grant.resolved' => [($e->actor && ! $selfAct ? "$actor gave $subj the " : "$who now ".($subjName === 'you' ? 'have' : 'has').' the ').$badge($role($a['role'] ?? null) ?? 'No')." role$inApp".$org,
                $join(isset($b['role']) ? 'Was '.$badge($role($b['role']), 'muted').' → '.$badge($role($a['role'] ?? null) ?? 'none') : null, isset($a['cause']) ? 'Because '.e($a['cause']) : null)],
            'grant.override_set' => ["$actor set $subjPoss role$inApp to ".$badge($role($a['role'] ?? 'none')).$org, $join(isset($b['role']) ? 'Was '.$badge($role($b['role']), 'muted') : null, ! empty($a['reason']) ? '“'.e($a['reason']).'”' : null)],
            'grant.override_cleared' => ["$actor put $subjPoss role$inApp back to what their job role gives".$org, isset($b['role']) ? 'Was '.$badge($role($b['role']), 'muted') : null],
            'grant.changed' => ["$actor changed $subjPoss role$inApp".$org, null],
            'grant.removed' => ["$actor removed $subjPoss access$inApp".$org, null],
            'profile.assigned' => ["$actor gave $subj the job role ".$bold(Str::headline((string) ($a['profile'] ?? ''))).$inOrg, null],
            'profile.unassigned' => ["$actor took the job role ".$bold(Str::headline((string) ($b['profile'] ?? $a['profile'] ?? '')))." away from $subj".$inOrg, null],
            'org.role_changed' => ["$actor made $subj ".e(Str::headline((string) ($a['role'] ?? 'member'))).($e->organization ? ' of '.e($e->organization->name) : ''), isset($b['role']) && $b['role'] ? 'Was '.e(Str::headline((string) $b['role'])) : null],
            'team.membership_changed' => ["$actor changed the teams $subj is in".$inOrg, null],
            'user.invited' => ["$actor invited ".$bold($a['email'] ?? $subjName ?? 'someone').($e->organization ? ' to '.e($e->organization->name) : ''), null],
            'user.invite_accepted' => ["$who accepted their invite".($e->organization ? ' to '.e($e->organization->name) : ''), null],
            'user.invite_revoked' => ["$actor cancelled the invite to ".$bold($a['email'] ?? 'someone'), null],
            'user.signed_up' => ["$who signed up through $app".$org, null],
            'user.joined_app' => ["$who joined $app with their Clauth account".$org, null],
            'user.created_on_signin' => ["$who joined".($e->organization ? ' '.e($e->organization->name) : '').' on first sign-in', isset($a['connection']) ? 'Through '.e($a['connection']) : null],
            'user.disabled' => ["$actor turned off $subjPoss account", null],
            'user.enabled' => ["$actor turned $subjPoss account back on", null],
            'user.unlocked' => ["$actor unlocked $subjPoss account", null],
            'user.deleted' => ["$actor deleted ".$bold($b['email'] ?? 'a person'), null],
            'user.fields_changed' => [$selfAct ? "$who updated their details" : "$actor updated $subjPoss details".$inOrg, self::fields($a)],
            'user.updated' => match (true) {
                isset($a['joined_org']) => ["$actor added $subj to ".e($e->organization?->name ?? 'an organization'), null],
                isset($a['left_org']) => ["$actor removed $subj from ".e($e->organization?->name ?? 'an organization'), null],
                $selfAct => ["$who updated their details", self::fields($a)],
                default => ["$actor updated $subjPoss details", self::fields($a)],
            },
            'scim.user_provisioned' => ["$actor added $subj from the company directory".$inOrg, null],
            'scim.user_removed' => ["$actor removed $subj through the company directory".$inOrg, null],
            'consent.granted' => ["$who let $app use their details", null],
            'consent.revoked' => ["$who took back $app’s access to their details", null],
            'app.registered' => ["$actor registered $app", isset($a['type']) ? e(Str::headline((string) $a['type'])).' app' : null],
            'app.updated' => match (true) {
                ! empty($a['deleted']) => ["$actor deleted $app", null],
                isset($a['client_revoked']) => ["$actor turned off a client of $app", null],
                isset($a['client']) => ["$actor changed a client of $app", null],
                default => ["$actor updated $app", self::fields($a)],
            },
            'app.role_changed' => match (true) {
                isset($a['created']) => ["$actor added the role ".$badge($role($a['created']))." to $app", null],
                isset($b['deleted']) => ["$actor removed the role ".$badge($role($b['deleted']), 'muted')." from $app", null],
                isset($a['permission_added']) => ["$actor added the action ".$bold($a['permission_added'])." to $app", null],
                isset($a['permissions']) => ["$actor changed what a role can do in $app", self::permDiff($b['permissions'] ?? [], $a['permissions'])],
                default => ["$actor changed a role in $app", self::fields($a)],
            },
            'app.manifest_synced' => ["$app updated its permissions", self::manifest($a)],
            'app.branding_changed' => ["$actor changed $app’s sign-in page", self::fields($a)],
            'app.release_note' => [isset($b['title']) && ! isset($a['title']) ? "$actor removed a release note from $app" : (! empty($a['edited']) ? "$actor edited a release note for $app" : "$actor posted a release note for $app"),
                isset($a['title']) || isset($b['title']) ? '“'.e($a['title'] ?? $b['title']).'”' : null],
            'app.connected' => ["$app is connected — first sign-in through Clauth", null],
            'app.installed' => ["$app was added to ".e($e->organization?->name ?? ($a['org'] ?? 'an organization')), ! empty($a['approved_request']) ? 'Request approved' : null],
            'app.install_requested' => ["$app asked to be available to ".e($e->organization?->name ?? 'an organization'), null],
            'app.suspended' => ["$actor suspended $app", isset($a['reason']) ? '“'.e($a['reason']).'”' : null],
            'app.unsuspended' => ["$actor restored $app", null],
            'app.secret_rotated' => ["$actor made a new client secret for $app", null],
            'app.client_added', 'app.client_registered' => ["$actor added a client to $app", null],
            'app.client_deregistered' => ["$actor removed a client from $app", null],
            'app.domain_added', 'app.domain_removed', 'app.domain_active', 'app.domain_problem', 'app.domain_updated', 'app.domain_shared', 'app.domain_lost', 'app.domain_taken_over' => [
                "$app: ".e(Str::lcfirst(Audit::label($e->event))), isset($a['host']) || isset($b['host']) ? e($a['host'] ?? $b['host']) : null],
            'report.closed' => ["$actor closed a report about ".$bold($a['host'] ?? 'a sign-in page'), null],
            default => [self::fallback($e, $actor, $subj, $name, $bold), null],
        };

        $tone = match (true) {
            in_array($e->event, ['signin.failed', 'signin.locked', 'signin.denied', 'security.refresh_reuse', 'app.domain_problem', 'app.suspended'], true) => 'bad',
            $e->category === 'signin' => 'info',
            $e->category === 'security' => 'warn',
            default => 'muted',
        };
        $icon = match (true) {
            in_array($e->event, ['signin.locked', 'signin.failed'], true) => 'lock',
            $e->category === 'signin' => 'key',
            $e->category === 'security' => 'shield',
            $e->category === 'role' => 'edit',
            $e->event === 'app.manifest_synced' => 'refresh',
            $e->category === 'app' => 'box',
            str_starts_with($e->event, 'user.invite') => 'mail',
            default => 'user',
        };
        $initialsOf = $e->actor ?? $subject;

        return [
            'text' => new HtmlString($text),
            'detail' => $detail ? new HtmlString($detail) : null,
            'icon' => $icon,
            'tone' => $tone,
            'initials' => $initialsOf ? $initialsOf->initials() : ($tone === 'bad' ? '!' : 'C'),
        ];
    }

    private static function fallback(AuditEvent $e, string $actor, string $subj, $name, \Closure $bold): string
    {
        [$noun, $verb] = array_pad(explode('.', $e->event, 2), 2, '');
        $nouns = ['team' => 'a team', 'field' => 'a person field', 'connection' => 'a sign-in connection', 'agent' => 'an agent', 'theme' => 'a sign-in theme',
            'template' => 'a message template', 'policy' => 'the sign-in rules', 'profile' => 'a job role', 'org' => 'the organization', 'settings' => 'settings',
            'config' => 'the configuration', 'api' => 'an API client'];
        $verbs = ['created' => 'created', 'updated' => 'updated', 'deleted' => 'deleted', 'changed' => 'changed', 'reset' => 'reset', 'exported' => 'exported',
            'imported' => 'imported', 'mapping_changed' => 'changed what this gives:', 'access_changed' => 'changed the access of', 'secret_rotated' => 'made a new secret for'];
        if (isset($nouns[$noun], $verbs[$verb])) {
            $thing = $name !== null && is_scalar($name) ? $nouns[$noun].' '.$bold(Str::headline((string) $name)) : $nouns[$noun];
            if ($noun === 'org' || $noun === 'settings' || $noun === 'policy' || $noun === 'config') {
                $thing = $nouns[$noun].($e->organization && $noun !== 'org' ? ' of '.e($e->organization->name) : ($noun === 'org' && $e->organization ? ' '.$bold($e->organization->name) : ''));
            }

            return "$actor {$verbs[$verb]} $thing".(in_array($noun, ['team', 'field', 'connection', 'agent', 'profile'], true) && $e->organization ? ' in '.e($e->organization->name) : '');
        }
        $label = Audit::label($e->event);

        return "$actor · ".e($label === $e->event ? Str::headline(str_replace('.', ' ', $e->event)) : $label).($e->subject_type === 'user' && $subj !== 'someone' ? " · $subj" : '');
    }

    private static function methods(array $a): ?string
    {
        $map = ['pwd' => 'Password', 'otp' => '2-step code', 'mfa' => '2-step code', 'totp' => '2-step code', 'sms' => 'Text code', 'email' => 'Email code',
            'code' => 'One-time code', 'hwk' => 'Security key', 'swk' => 'Passkey', 'fed' => 'Company sign-in', 'google' => 'Google', 'microsoft' => 'Microsoft',
            'facebook' => 'Facebook', 'apple' => 'Apple', 'github' => 'GitHub', 'sso' => 'Already signed in'];
        $amr = array_values(array_filter((array) ($a['amr'] ?? [])));
        $parts = array_map(fn ($m) => $map[$m] ?? Str::headline((string) $m), $amr);
        $s = $parts ? implode(' + ', array_unique($parts)) : null;
        if (! empty($a['via'])) {
            $s = trim(($s ? $s.' · ' : '').'via '.$a['via']);
        }

        return $s ? e($s) : null;
    }

    /** "Chrome on Windows · 203.0.113.24" from the stored user agent and IP. */
    private static function device(AuditEvent $e): ?string
    {
        $ua = (string) $e->user_agent;
        $browser = match (true) {
            $ua === '' => null,
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => null,
        };
        $os = match (true) {
            $ua === '' => null,
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };
        $dev = $browser && $os ? "$browser on $os" : ($browser ?? $os);

        return implode(' · ', array_filter([$dev ? e($dev) : null, $e->ip ? e($e->ip) : null])) ?: null;
    }

    private static function fields(array $a): ?string
    {
        $keys = array_values(array_diff(array_keys($a), ['orgs', 'id', 'edited']));
        if (! $keys) {
            return null;
        }
        $labels = array_map(fn ($k) => Str::lower(Str::headline((string) $k)), array_slice($keys, 0, 4));

        return e('Changed '.implode(', ', $labels).(count($keys) > 4 ? ' and '.(count($keys) - 4).' more' : ''));
    }

    private static function permDiff(array $before, array $after): ?string
    {
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));
        $parts = [];
        if ($added) {
            $parts[] = count($added).' added';
        }
        if ($removed) {
            $parts[] = count($removed).' removed';
        }

        return $parts ? e(ucfirst(implode(', ', $parts)).' '.Str::plural('action', count($added) + count($removed))) : null;
    }

    private static function manifest(array $a): ?string
    {
        $parts = [];
        foreach (['permissions_added' => 'new action', 'roles_created' => 'new role', 'permissions_deprecated' => 'retired action'] as $k => $word) {
            if (! empty($a[$k]) && is_array($a[$k])) {
                $n = count($a[$k]);
                $parts[] = "$n ".Str::plural($word, $n);
            }
        }

        return $parts ? e(implode(' · ', $parts)) : null;
    }

    /** "Today", "Yesterday" or "Mon 23 Nov" — the day headings on Activity. */
    public static function day(Carbon $at): string
    {
        return match (true) {
            $at->isToday() => 'Today',
            $at->isYesterday() => 'Yesterday',
            $at->isCurrentYear() => $at->format('D j M'),
            default => $at->format('D j M Y'),
        };
    }
}
