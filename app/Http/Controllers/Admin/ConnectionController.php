<?php

namespace App\Http\Controllers\Admin;

use App\Mail\LoginCodeMail;
use App\Models\Connection;
use App\Models\Organization;
use App\Services\Audit;
use App\Services\Connections;
use App\Services\OrgMail;
use App\Services\Sms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Organization › Sign-in & senders (SPEC-platform §7): an organization's own sign-in providers (OIDC, OAuth) and email
 * sender. Clauth owners also manage platform connections ({org} = "platform"): Facebook, platform OIDC/OAuth, SMS.
 */
class ConnectionController
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

    private function find(?Organization $o, int $id): Connection
    {
        return Connection::where('organization_id', $o?->id)->findOrFail($id);
    }

    public function index(string $org)
    {
        $o = $this->org($org);
        $types = array_diff_key(Connections::TYPES, array_flip($o ? Connections::PLATFORM_ONLY : Connections::ORG_ONLY));

        return view('admin.connections', ['org' => $o, 'orgKey' => $o?->id ?? 'platform', 'types' => $types,
            'connections' => Connection::where('organization_id', $o?->id)->orderBy('type')->orderBy('name')->get(),
            'fields' => collect($types)->mapWithKeys(fn ($l, $t) => [$t => Connections::fields($t, (bool) $o)])]);
    }

    public function store(Request $request, string $org)
    {
        $o = $this->org($org);
        $request->validate(['type' => Connections::typeRule((bool) $o), 'name' => 'required|string|max:80']);
        $type = $request->input('type');
        abort_if($type === 'sms' && Connection::where('type', 'sms')->whereNull('organization_id')->exists(), 422, 'There is already an SMS gateway. Change or delete it.');
        abort_if($type === 'email-smtp' && Connection::where('type', 'email-smtp')->where('organization_id', $o?->id)->exists(), 422, 'This organization already has an email sender.');
        $config = Connections::config($type, (bool) $o, (array) $request->input('config', []));
        $c = Connection::create(['organization_id' => $o?->id, 'type' => $type, 'key' => Connections::newKey($request->input('name'), $o),
            'name' => $request->input('name'), 'config' => $config, 'status' => 'active', 'created_by' => $request->user()->id]);
        Audit::log('connection.created', 'admin', $c, after: ['type' => $type, 'key' => $c->key, 'name' => $c->name], orgId: $o?->id);

        return back()->with('status', "“{$c->name}” added.".($c->isSignIn() ? ' Register this redirect URL with the provider: '.Connections::json($c)['callback_url'] : ''));
    }

    public function update(Request $request, string $org, int $connection)
    {
        $o = $this->org($org);
        $c = $this->find($o, $connection);
        $request->validate(['name' => 'required|string|max:80', 'status' => 'required|in:active,disabled']);
        $config = Connections::config($c->type, (bool) $o, (array) $request->input('config', []), $c);
        $before = ['name' => $c->name, 'status' => $c->status, 'config' => Connections::masked($c)];
        $c->update(['name' => $request->input('name'), 'status' => $request->input('status'), 'config' => $config]);
        Audit::log('connection.updated', 'admin', $c, $before, ['name' => $c->name, 'status' => $c->status, 'config' => Connections::masked($c)], orgId: $o?->id);

        return back()->with('status', "“{$c->name}” saved.");
    }

    public function destroy(string $org, int $connection)
    {
        $o = $this->org($org);
        $c = $this->find($o, $connection);
        $c->delete();
        Audit::log('connection.deleted', 'admin', $c, before: ['type' => $c->type, 'key' => $c->key], orgId: $o?->id);

        return back()->with('status', "“{$c->name}” deleted. People linked to it sign in another way.");
    }

    /** Check it works: OIDC discovery, a test SMS, or a test email to you. */
    public function test(Request $request, string $org, int $connection)
    {
        $o = $this->org($org);
        $c = $this->find($o, $connection);
        try {
            $msg = match ($c->type) {
                'oidc' => tap('Discovery works: '.Http::acceptJson()->timeout(10)->get(rtrim($c->cfg('issuer'), '/').'/.well-known/openid-configuration')->throw()->json('issuer'),
                    fn () => null),
                'sms' => tap('Test text sent.', fn () => Sms::send(Sms::normalise((string) $request->input('phone')) ?? abort(422, 'Enter a mobile number to send the test to.'),
                    'Clauth test message: your SMS gateway works.', $c)),
                'email-smtp' => tap('Test email sent to '.$request->user()->email.'.', fn () => OrgMail::send($o, $request->user()->email, new LoginCodeMail('000000'), $c)),
                default => 'Nothing to test here: try signing in with it.',
            };
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return back()->withErrors(['test' => 'Test failed: '.\Illuminate\Support\Str::limit($e->getMessage(), 200)]);
        }

        return back()->with('status', $msg);
    }
}
