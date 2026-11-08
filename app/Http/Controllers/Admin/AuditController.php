<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditEvent;
use App\Services\Audit;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AuditController
{
    use Scope;

    private function query(Request $request)
    {
        $ids = $this->orgIds();

        return AuditEvent::with(['actor', 'app', 'organization'])
            ->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))
            ->when(in_array($request->query('cat'), ['signin', 'security', 'role', 'admin', 'app'], true), fn ($q) => $q->where('category', $request->query('cat')))
            ->when($request->query('from'), fn ($q, $d) => $q->where('at', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->where('at', '<', Carbon::parse($d)->addDay()))
            ->when(trim((string) $request->query('q')), fn ($q, $s) => $q->where(fn ($w) => $w->where('event', 'like', "%$s%")->orWhere('ip', 'like', "%$s%")
                ->orWhereHas('actor', fn ($a) => $a->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%"))))
            ->latest('at');
    }

    public function index(Request $request)
    {
        return view('admin.audit', ['events' => $this->query($request)->paginate(50)->withQueryString(), 'cat' => $request->query('cat', 'all')]);
    }

    public function export(Request $request)
    {
        $rows = $this->query($request)->limit(20000)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['time_utc', 'event', 'label', 'category', 'actor', 'actor_type', 'subject', 'app', 'org', 'ip', 'before', 'after']);
            foreach ($rows as $e) {
                fputcsv($out, [$e->at->toIso8601String(), $e->event, Audit::label($e->event), $e->category, $e->actor?->email, $e->actor_type,
                    $e->subject_type ? "$e->subject_type:$e->subject_id" : '', $e->app?->key, $e->organization?->slug, $e->ip,
                    $e->before ? json_encode($e->before) : '', $e->after ? json_encode($e->after) : '']);
            }
            fclose($out);
        }, 'clauth-audit-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
