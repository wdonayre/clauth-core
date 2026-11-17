<?php

namespace Tests\Feature;

/** Moving Clauth's main address (auth.thecakelabs.com → auth.cebos.tech) without breaking anything on the way. */
class MainAddressMoveTest extends ClauthTestCase
{
    public function test_new_host_gets_a_certificate_before_the_switch(): void
    {
        config(['clauth.issuer' => 'https://auth.thecakelabs.com', 'clauth.main_aliases' => ['auth.cebos.tech', 'bad host']]);
        $this->artisan('clauth:domains', ['action' => 'hosts'])->expectsOutput('auth.cebos.tech')->doesntExpectOutput('bad host')->assertSuccessful();
        $this->artisan('clauth:domains', ['action' => 'cert-hosts'])->expectsOutput('auth.cebos.tech')->assertSuccessful();
        $this->artisan('clauth:domains', ['action' => 'nginx-conf', 'host' => 'auth.cebos.tech', '--tls' => true])->assertSuccessful();
        $this->artisan('clauth:domains', ['action' => 'cert-result', 'host' => 'auth.cebos.tech', '--ok' => true])->assertSuccessful();
        // Not yet the main address: nothing is served there.
        $this->get('https://auth.cebos.tech/login')->assertNotFound();
        $this->get('https://auth.thecakelabs.com/login')->assertOk();
    }

    public function test_after_the_switch_the_old_address_forwards_everything(): void
    {
        config(['clauth.issuer' => 'https://auth.cebos.tech', 'app.url' => 'https://auth.cebos.tech',
            'clauth.main_aliases' => ['auth.cebos.tech'], 'clauth.previous_hosts' => ['auth.thecakelabs.com']]);
        $this->get('https://auth.cebos.tech/login')->assertOk();
        $this->get('https://auth.cebos.tech/.well-known/openid-configuration')->assertOk()->assertJsonPath('issuer', 'https://auth.cebos.tech');
        $r = $this->get('https://auth.thecakelabs.com/oauth/authorize?client_id=x&state=y');
        $r->assertStatus(308)->assertRedirect('https://auth.cebos.tech/oauth/authorize?client_id=x&state=y');
        $this->post('https://auth.thecakelabs.com/oauth/token', ['grant_type' => 'refresh_token'])->assertStatus(308)->assertRedirect('https://auth.cebos.tech/oauth/token');
        $this->get('https://auth.thecakelabs.com/bar/v1')->assertRedirect('https://auth.cebos.tech/bar/v1');
        // The main and old addresses can't be claimed as an app's sign-in domain.
        $this->artisan('clauth:domains', ['action' => 'hosts'])->expectsOutput('auth.cebos.tech')->assertSuccessful();
    }
}
