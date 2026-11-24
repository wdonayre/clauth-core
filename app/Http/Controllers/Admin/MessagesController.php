<?php

namespace App\Http\Controllers\Admin;

use App\Models\Organization;
use App\Services\Audit;
use App\Services\Templates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Organization › Messages (its invite email) and Platform messages (every email and text), per language (§8.5). */
class MessagesController
{
    use Scope;

    private function org(string $org): ?Organization
    {
        if ($org === 'platform') {
            abort_unless(auth()->user()->isOwner(), 404);

            return null;
        }
        abort_unless($this->canManageOrg($org), 404);

        return Organization::findOrFail($org);
    }

    public function index(string $org)
    {
        $o = $this->org($org);
        $keys = $o ? array_intersect_key(Templates::KEYS, ['invite' => 1]) : Templates::KEYS;

        return view('admin.messages', ['org' => $o, 'orgKey' => $o?->id ?? 'platform', 'keys' => $keys, 'locales' => config('clauth.locales'),
            'rows' => DB::table('message_templates')->where('organization_id', $o?->id)->get()->keyBy(fn ($r) => $r->key.'|'.$r->locale)]);
    }

    public function save(Request $request, string $org)
    {
        $o = $this->org($org);
        $data = $request->validate(['key' => ['required', Rule::in($o ? ['invite'] : array_keys(Templates::KEYS))],
            'locale' => ['required', Rule::in(array_keys(config('clauth.locales')))], 'subject' => 'nullable|string|max:190', 'body' => 'nullable|string|max:5000']);
        $where = ['organization_id' => $o?->id, 'key' => $data['key'], 'locale' => $data['locale']];
        if (blank($data['body'])) {
            DB::table('message_templates')->where($where)->delete();
            Audit::log('template.reset', 'admin', null, after: $where, orgId: $o?->id);

            return back()->with('status', 'Back to the built-in message.');
        }
        $need = ['invite' => 'link', 'login_code' => 'code', 'sms_code' => 'code'][$data['key']] ?? null;
        if ($need && ! preg_match('/\{\{\s*'.$need.'\s*\}\}/', $data['body'])) {
            return back()->withErrors(['body' => "This message must include {{{$need}}}."])->withInput();
        }
        if (Templates::KEYS[$data['key']][0] === 'email' && blank($data['subject'])) {
            return back()->withErrors(['body' => 'Add a subject.'])->withInput();
        }
        DB::table('message_templates')->updateOrInsert($where, ['subject' => $data['subject'], 'body' => $data['body'], 'updated_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now()]);
        Audit::log('template.changed', 'admin', null, after: $where, orgId: $o?->id);

        return back()->with('status', 'Message saved.');
    }
}
