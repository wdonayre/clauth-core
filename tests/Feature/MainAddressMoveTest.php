<?php

namespace Tests\Feature;

/** Moving Clauth's main address (auth.old.test → auth.new.test) without breaking anything on the way. */
class MainAddressMoveTest extends ClauthTestCase
{
    public function test_new_host_gets_a_certificate_before_the_switch(): void
    {
        config(['clauth.issuer' => 'https://auth.old.test', 'clauth.main_aliases' => ['auth.new.test', 'bad host']]);
        $this->artisan('clauth:domains', ['action' => 'hosts'])->expectsOutput('auth.new.test')->doesntExpectOutput('bad host')->assertSuccessful();
        $this->artisan('clauth:domains', ['action' => 'cert-hosts'])->expectsOutput('auth.new.test')->assertSuccessful();
        $this->artisan('clauth:domains', ['action' => 'nginx-conf', 'host' => 'auth.new.test', '--tls' => true])->assertSuccessful();
        $this->artisan('clauth:domains', ['action' => 'cert-result', 'host' => 'auth.new.test', '--ok' => true])->assertSuccessful();
        // Not yet the main address: nothing is served there.
        $this->get('https://auth.new.test/login')->assertNotFound();
        $this->get('https://auth.old.test/login')->assertOk();
    }

    public function test_after_the_switch_the_old_address_forwards_everything(): void
    {
        config(['clauth.issuer' => 'https://auth.new.test', 'app.url' => 'https://auth.new.test',
            'clauth.main_aliases' => ['auth.new.test'], 'clauth.previous_hosts' => ['auth.old.test']]);
        $this->get('https://auth.new.test/login')->assertOk();
        $this->get('https://auth.new.test/.well-known/openid-configuration')->assertOk()->assertJsonPath('issuer', 'https://auth.new.test');
        $r = $this->get('https://auth.old.test/oauth/authorize?client_id=x&state=y');
        $r->assertStatus(308)->assertRedirect('https://auth.new.test/oauth/authorize?client_id=x&state=y');
        $this->post('https://auth.old.test/oauth/token', ['grant_type' => 'refresh_token'])->assertStatus(308)->assertRedirect('https://auth.new.test/oauth/token');
        $this->get('https://auth.old.test/bar/v1')->assertRedirect('https://auth.new.test/bar/v1');
        // The main and old addresses can't be claimed as an app's sign-in domain.
        $this->artisan('clauth:domains', ['action' => 'hosts'])->expectsOutput('auth.new.test')->assertSuccessful();
    }
}
