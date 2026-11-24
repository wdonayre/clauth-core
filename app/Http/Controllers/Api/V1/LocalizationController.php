<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Services\Audit;
use App\Services\Templates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Languages, wording changes and message templates (SPEC-platform §8.5). */
class LocalizationController
{
    use UsesApi;

    public function languages()
    {
        return response()->json(['data' => collect(config('clauth.locales'))->map(fn ($name, $code) => ['code' => $code, 'name' => $name,
            'overrides' => DB::table('translation_overrides')->where('locale', $code)->count()])->values()]);
    }

    /** The sign-in page wording in one language: built-in text with the platform's changes applied. */
    public function translations(string $lang)
    {
        abort_unless(isset(config('clauth.locales')[$lang]), 404, 'Language not found.');
        $file = is_file($f = lang_path("$lang.json")) ? json_decode(file_get_contents($f), true) : [];
        $over = DB::table('translation_overrides')->where('locale', $lang)->pluck('text', 'key')->all();

        return response()->json(['language' => $lang, 'strings' => (object) array_merge($file, $over), 'overrides' => (object) $over]);
    }

    /** PUT {"English source text": "your wording" | null} — null removes your change. Platform only. */
    public function updateTranslations(Request $request, string $lang)
    {
        abort_unless(isset(config('clauth.locales')[$lang]), 404, 'Language not found.');
        abort_unless($this->api()->isPlatform(), 403, 'Wording changes are for platform clients only.');
        $in = $request->json()->all();
        abort_if(! is_array($in) || count($in) > 500, 422, 'Send an object of up to 500 strings.');
        foreach ($in as $key => $text) {
            abort_if(! is_string($key) || mb_strlen($key) > 500 || ($text !== null && (! is_string($text) || mb_strlen($text) > 2000)), 422, 'Keys and texts must be strings (500 / 2000 characters).');
            $text === null ? DB::table('translation_overrides')->where(['locale' => $lang, 'key' => $key])->delete()
                : DB::table('translation_overrides')->updateOrInsert(['locale' => $lang, 'key' => $key], ['text' => $text, 'updated_at' => now(), 'created_at' => now()]);
        }
        Cache::forget('i18n:over:'.$lang);
        Audit::log('i18n.changed', 'admin', null, after: ['language' => $lang, 'keys' => array_keys($in)]);

        return $this->translations($lang);
    }

    private function scopeOrg(Request $request): ?Organization
    {
        if ($request->filled('org')) {
            return $this->api()->org((string) $request->input('org'));
        }
        abort_unless($this->api()->isPlatform() || $request->isMethod('GET'), 422, 'Say which organization (org); platform templates are for platform clients.');

        return null;
    }

    public function templates(Request $request)
    {
        $org = $request->filled('org') ? $this->api()->org((string) $request->query('org')) : null;
        $rows = DB::table('message_templates')->when($org, fn ($q) => $q->where('organization_id', $org->id), fn ($q) => $q->whereNull('organization_id'))->get();

        return response()->json(['keys' => collect(Templates::KEYS)->map(fn ($k, $key) => ['key' => $key, 'channel' => $k[0], 'label' => $k[1], 'placeholders' => $k[2]])->values(),
            'data' => $rows->map(fn ($r) => ['key' => $r->key, 'locale' => $r->locale, 'subject' => $r->subject, 'body' => $r->body, 'updated_at' => $r->updated_at])->values()]);
    }

    /** PUT /templates/{key} {org?, locale, subject (email), body} */
    public function putTemplate(Request $request, string $key)
    {
        abort_unless(isset(Templates::KEYS[$key]), 404, 'Unknown template.');
        $org = $this->scopeOrg($request);
        abort_if($org && $key !== 'invite', 422, 'Organizations can change their invite email; the other messages are platform-wide.');
        $data = $request->validate(['locale' => ['required', Rule::in(array_keys(config('clauth.locales')))],
            'subject' => [Templates::KEYS[$key][0] === 'email' ? 'required' : 'nullable', 'string', 'max:190'],
            'body' => 'required|string|max:'.(Templates::KEYS[$key][0] === 'sms' ? 320 : 5000)]);
        if ($key === 'invite' && ! str_contains($data['body'], '{{link}}') && ! str_contains($data['body'], '{{ link }}')) {
            abort(422, 'The invite must include {{link}}.');
        }
        if (in_array($key, ['login_code', 'sms_code'], true) && ! preg_match('/\{\{\s*code\s*\}\}/', $data['body'])) {
            abort(422, 'The message must include {{code}}.');
        }
        DB::table('message_templates')->updateOrInsert(['organization_id' => $org?->id, 'key' => $key, 'locale' => $data['locale']],
            ['subject' => $data['subject'] ?? null, 'body' => $data['body'], 'updated_by' => $this->api()->actor->id, 'updated_at' => now(), 'created_at' => now()]);
        Audit::log('template.changed', 'admin', null, after: ['key' => $key, 'locale' => $data['locale']], orgId: $org?->id);

        return $this->templates($request);
    }

    public function deleteTemplate(Request $request, string $key)
    {
        $org = $this->scopeOrg($request);
        $request->validate(['locale' => ['required', Rule::in(array_keys(config('clauth.locales')))]]);
        DB::table('message_templates')->where(['key' => $key, 'locale' => $request->input('locale')])
            ->when($org, fn ($q) => $q->where('organization_id', $org->id), fn ($q) => $q->whereNull('organization_id'))->delete();
        Audit::log('template.reset', 'admin', null, after: ['key' => $key, 'locale' => $request->input('locale')], orgId: $org?->id);

        return response()->noContent();
    }
}
