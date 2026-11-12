@extends('layouts.auth')
@section('title', 'Terms')
@section('wide', true)
@section('card')
  <h1>Terms of Service</h1>
  @include('partials.legal-draft')
  <div class="legal" style="text-align:left;line-height:1.6;font-size:14px">
    <p class="small muted">Version {{ config('clauth.terms_version') }}. Clauth is run by The Cake Labs [registered business name, address and registration number to be added].</p>

    <h2 style="font-size:16px;margin:20px 0 6px">1. What Clauth is</h2>
    <p>Clauth is a sign-in service. It lets people sign in once and use the apps their organization gives them access to. Each organization decides who its people are, which apps they may use and which role they have. The apps themselves are run by their own organizations, not by Clauth, unless the app says it is ours.</p>

    <h2 style="font-size:16px;margin:20px 0 6px">2. Your account (people)</h2>
    <ul>
      <li>Your account is for you only. Don’t share your password, codes or recovery codes.</li>
      <li>Keep your email address current: it’s how we reach you about security.</li>
      <li>Your organization’s admins can change or remove your access to their apps at any time.</li>
      <li>We may lock or suspend an account to protect it or others after suspicious activity.</li>
      <li>When an app outside your own organizations asks for your details, you decide whether to continue. You can remove that access any time in My account.</li>
    </ul>

    <h2 style="font-size:16px;margin:20px 0 6px">3. Organizations</h2>
    <p>If you set up or manage an organization on Clauth, you agree on its behalf that:</p>
    <ul>
      <li>You are allowed to act for the organization and to accept these Terms and the <a href="{{ route('dpa') }}">Data Processing Agreement</a> for it.</li>
      <li>You only invite people you have a reason to give access to, and you tell them how their data is used.</li>
      <li>Your apps’ names, logos and sign-in domains are your own or you’re allowed to use them. You won’t make a sign-in page that pretends to be another company, collect passwords for anything else, or send unwanted invites.</li>
      <li>Your apps publish a privacy policy, terms and a support email, and handle the data they receive from Clauth lawfully.</li>
      <li>You keep your client secrets safe and tell us at once if one may have leaked.</li>
    </ul>

    <h2 style="font-size:16px;margin:20px 0 6px">4. Limits, suspension and ending</h2>
    <ul>
      <li>Organizations have usage limits; verified organizations get higher ones.</li>
      <li>We may suspend an organization or app that breaks these Terms, is reported for phishing or abuse, or puts other people at risk. We’ll tell the organization’s owners why when we can.</li>
      <li>An organization’s owner can export its data and ask for it to be deleted. Deletion happens 30 days after the request.</li>
      <li>We may change these Terms. We’ll tell organization owners about changes that matter and may ask them to accept the new version.</li>
    </ul>

    <h2 style="font-size:16px;margin:20px 0 6px">5. Service and responsibility</h2>
    <p>We work to keep Clauth available and secure, but it’s provided as is, without a guarantee of uninterrupted service. To the extent the law allows, we aren’t liable for indirect losses, and our total liability to an organization is limited to [amount to be set; free service today]. Nothing here limits rights you have under Philippine law, including the Data Privacy Act of 2012.</p>

    <h2 style="font-size:16px;margin:20px 0 6px">6. Law and contact</h2>
    <p>These Terms are governed by the laws of the Philippines [venue to be confirmed]. Questions: {{ \App\Services\Settings::get('support_email') }}.</p>
  </div>
  <a class="btn-auth sec" href="{{ route('login') }}" style="margin-top:16px">Back</a>
@endsection
