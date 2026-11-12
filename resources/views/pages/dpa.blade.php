@extends('layouts.auth')
@section('title', 'Data Processing Agreement')
@section('wide', true)
@section('card')
  <h1>Data Processing Agreement</h1>
  @include('partials.legal-draft')
  <div class="legal" style="text-align:left;line-height:1.6;font-size:14px">
    <p class="small muted">Version {{ config('clauth.terms_version') }}. Between The Cake Labs [registered details to be added] (“Clauth”, the processor) and the organization that accepts it (the “Organization”, the controller of its people’s data).</p>

    <h2 style="font-size:16px;margin:20px 0 6px">1. Scope</h2>
    <p>Clauth processes the personal data of the Organization’s people (name, email, credentials, roles, sign-in records) only to provide sign-in and access management for the Organization’s apps, as described in the <a href="{{ route('privacy') }}">Privacy Policy</a>, and only on the Organization’s documented instructions — which are these terms and the Organization’s use of the admin console.</p>

    <h2 style="font-size:16px;margin:20px 0 6px">2. Clauth’s commitments</h2>
    <ul>
      <li>Keep the data confidential; only people who need it to run Clauth can access it, under confidentiality.</li>
      <li>Security measures: passwords hashed, admin 2-step verification, encrypted signing keys and backups, encrypted traffic, audit logs, separation of organizations’ data in the console, limits and abuse monitoring.</li>
      <li>Use only the sub-processors listed in the Privacy Policy, tell the Organization before adding or replacing one, and hold them to equivalent obligations.</li>
      <li>Help the Organization answer people’s requests (access, correction, export, deletion) — the admin console and data export cover most of these.</li>
      <li>Notify the Organization without undue delay, and within 72 hours of becoming aware, of a personal data breach affecting its data, with what is known, and help it meet its own notification duties under the Data Privacy Act of 2012.</li>
      <li>On deletion of the Organization, delete its data after the 30-day grace period (backups roll off within 30 days after that), unless the law requires keeping it.</li>
      <li>Make available the information reasonably needed to show compliance with this agreement.</li>
    </ul>

    <h2 style="font-size:16px;margin:20px 0 6px">3. The Organization’s commitments</h2>
    <ul>
      <li>It has a lawful basis to have its people use Clauth and has told them how their data is used.</li>
      <li>It controls who gets access and keeps its admin and client credentials secure.</li>
      <li>Its apps handle the data they receive from Clauth lawfully, under their own privacy policies.</li>
    </ul>

    <h2 style="font-size:16px;margin:20px 0 6px">4. Transfers, term and law</h2>
    <p>Data is stored with the hosting provider in [country to be confirmed]; transfers outside the Philippines are protected as the Data Privacy Act requires. This agreement lasts as long as the Organization uses Clauth and is governed by Philippine law.</p>
  </div>
  <a class="btn-auth sec" href="{{ route('login') }}" style="margin-top:16px">Back</a>
@endsection
