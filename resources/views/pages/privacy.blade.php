@extends('layouts.auth')
@section('title', 'Privacy')
@section('wide', true)
@section('card')
  <h1>Privacy Policy</h1>
  @include('partials.legal-draft')
  <div class="legal" style="text-align:left;line-height:1.6;font-size:14px">
    <p class="small muted">Version {{ config('clauth.terms_version') }}. Clauth is run by The Cake Labs [registered business name and address to be added]. Data protection contact: {{ \App\Services\Settings::get('support_email') }} [Data Protection Officer to be named].</p>

    <h2 style="font-size:16px;margin:20px 0 6px">Who decides what happens to your data</h2>
    <p>For people who sign in to apps of The Cake Labs’ own businesses (Alab Apparel, CakesbyGrace, CebuCake Inc., The Cake Labs), we decide how Clauth handles your data. For people who belong to another organization, <b>that organization</b> decides who you are in Clauth and which apps you use; we handle your data on its behalf under a <a href="{{ route('dpa') }}">Data Processing Agreement</a>.</p>

    <h2 style="font-size:16px;margin:20px 0 6px">What we keep</h2>
    <ul>
      <li><b>Account:</b> name, email, password (only as a one-way hash), 2-step verification settings and recovery codes (hashed or encrypted).</li>
      <li><b>Access:</b> your organizations, which apps you may use there and your role in each.</li>
      <li><b>Security records:</b> sign-ins and changes to your account (time, IP address, browser), devices you’ve signed in on, and which apps you’ve allowed. Kept for one year.</li>
      <li><b>Sessions and tokens</b> while you’re signed in.</li>
    </ul>
    <p>We don’t use ads, tracking pixels or analytics cookies, and we don’t sell or rent data.</p>

    <h2 style="font-size:16px;margin:20px 0 6px">What apps receive</h2>
    <p>An app you sign in to receives your name, email and your roles in that app only — never your password, other apps or other organizations. Apps outside your own organizations ask you first, and you can remove their access in My account. What an app then does with your data is covered by that app’s own privacy policy.</p>

    <h2 style="font-size:16px;margin:20px 0 6px">Why</h2>
    <p>To sign you in, keep your account and the apps secure, prevent abuse, and meet legal obligations. The basis is the service you or your organization asked for, our legitimate interest in security, and legal obligations.</p>

    <h2 style="font-size:16px;margin:20px 0 6px">Who else handles it</h2>
    <ul>
      <li>Our hosting provider (servers in [country to be confirmed]).</li>
      <li>SMTP2GO, to send emails such as invites and password resets.</li>
      <li>Cloudflare (R2 storage), for encrypted backups; and for DNS of some sign-in domains.</li>
    </ul>

    <h2 style="font-size:16px;margin:20px 0 6px">How long</h2>
    <p>While your account is active. Security records: one year. Encrypted backups: up to 30 days. When an organization is deleted, its memberships and access go after a 30-day grace period, and accounts that belong to no other organization are deleted.</p>

    <h2 style="font-size:16px;margin:20px 0 6px">Your rights</h2>
    <p>Under the Philippine Data Privacy Act of 2012 you can ask to see, correct, download or delete your data, object to its use, and complain to the National Privacy Commission. Ask your organization’s admin, or write to {{ \App\Services\Settings::get('support_email') }}. If an organization handles your account, we’ll pass your request to it.</p>

    <h2 style="font-size:16px;margin:20px 0 6px">Security and breaches</h2>
    <p>Passwords are hashed, 2-step verification is required for admins, signing keys are encrypted, and traffic is encrypted. If a breach affects your data we’ll notify the affected organizations, you and the National Privacy Commission as the law requires.</p>
  </div>
  <a class="btn-auth sec" href="{{ route('login') }}" style="margin-top:16px">Back</a>
@endsection
