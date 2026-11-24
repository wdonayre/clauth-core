<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditEvent;
use App\Models\Report;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;

/** Clauth owners: reported sign-in pages + what outside organizations recently changed on theirs (SPEC-organizations §10.3). */
class ReportController
{
    public function index(Request $request)
    {
        $open = Report::with('app.publisher')->where('status', 'open')->latest()->get();
        $closed = Report::with('app')->where('status', 'closed')->latest('closed_at')->limit(20)->get();
        // New outside apps and branding changes: the places a fake sign-in page would start.
        $feed = AuditEvent::with(['actor', 'app.publisher'])->whereIn('event', ['app.registered', 'app.branding_changed', 'app.updated', 'app.domain_added'])
            ->whereHas('app.publisher', fn ($q) => $q->where('is_platform', false))->where('at', '>=', now()->subDays(30))->latest('at')->limit(50)->get();
        $people = User::withTrashed()->whereIn('id', $open->pluck('reporter_user_id')->merge($closed->pluck('closed_by'))->filter()->unique()->all())
            ->get(['id', 'name', 'email'])->keyBy('id');
        $show = in_array($request->query('show'), ['open', 'closed', 'changes'], true) ? $request->query('show') : 'open';

        return view('admin.reports', compact('open', 'closed', 'feed', 'people', 'show'));
    }

    public function close(Report $report)
    {
        $report->update(['status' => 'closed', 'closed_by' => auth()->id(), 'closed_at' => now()]);
        Audit::log('report.closed', 'admin', $report, after: ['host' => $report->host], appId: $report->app_id);

        return back()->with('status', 'Report marked resolved.');
    }
}
