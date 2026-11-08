<?php

namespace App\Http\Controllers\Admin;

use App\Models\Application;
use App\Models\ReleaseNote;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Owner: release notes ("What's new") shown on the Clauth bar's bell. */
class UpdatesController
{
    public function index(Request $request)
    {
        $notes = ReleaseNote::with('app')->latest('published_at')->paginate(30);
        $reads = DB::table('release_note_reads')->whereIn('release_note_id', $notes->pluck('id'))
            ->selectRaw('release_note_id, count(*) as n')->groupBy('release_note_id')->pluck('n', 'release_note_id');

        return view('admin.updates', [
            'notes' => $notes, 'reads' => $reads,
            'apps' => Application::orderBy('name')->get(),
            'edit' => $request->query('edit') ? ReleaseNote::find($request->query('edit')) : null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $note = ReleaseNote::create($data + ['created_by' => auth()->id(), 'source' => 'admin']);
        Audit::log('app.release_note', 'app', $note, after: $note->only('title', 'version', 'app_id'), appId: $note->app_id);

        return redirect()->route('admin.updates')->with('status', 'Update posted. It shows on the bell in '.($note->app?->name ?? 'every app').'.');
    }

    public function update(Request $request, ReleaseNote $note)
    {
        $note->update($this->validated($request));
        Audit::log('app.release_note', 'app', $note, after: $note->only('title', 'version', 'app_id') + ['edited' => true], appId: $note->app_id);

        return redirect()->route('admin.updates')->with('status', 'Update saved.');
    }

    public function destroy(ReleaseNote $note)
    {
        $note->delete();
        Audit::log('app.release_note', 'app', $note, before: $note->only('title', 'version', 'app_id'), appId: $note->app_id);

        return back()->with('status', 'Update removed from every bar.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'app_id' => 'nullable|exists:apps,id',
            'version' => 'nullable|string|max:40',
            'title' => 'required|string|max:160',
            'body' => 'nullable|string|max:4000',
            'link' => 'nullable|url|max:255',
            'published_at' => 'nullable|date',
        ]);
        $data['published_at'] = $data['published_at'] ?? now();
        $data['app_id'] = $data['app_id'] ?: null;

        return $data;
    }
}
