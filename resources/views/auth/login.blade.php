@extends('layouts.auth')
@section('title', __('Sign in'))
@section('card')
  <h1>{{ __('Sign in') }}</h1>
  <p class="sub">{!! $app ? e(__('Continue to')).' <strong>'.e($app->name).'</strong>' : (! empty($theme['brand']) ? e(__('Continue to')).' <strong>'.e($theme['brand']).'</strong>' : e(__('to your Clauth account'))) !!}</p>
  @if($app && ! $app->isFirstParty())<p class="small" style="margin:-6px 0 12px">@include('partials.publisher', ['app' => $app])</p>@endif
  @include('partials.errors')
  @if(! empty($socialError))<div class="notice bad" role="alert">{{ $socialError }}</div>@endif
  @if(! empty($policyNotice))<div class="notice" role="status">{{ $policyNotice }}</div>@endif
  @if(! empty($google))
    <a class="btn-auth sec" href="{{ ($preview ?? false) ? '#' : url('/login/google') }}" data-google>
      <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
      {{ __('Continue with Google') }}
    </a>
  @endif
  @foreach($connections ?? [] as $c)
    <a class="btn-auth sec" href="{{ ($preview ?? false) ? '#' : url('/login/c/'.$c->key) }}">
      @if($c->type === 'meta')<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="#1877F2" d="M24 12a12 12 0 1 0-13.9 11.9v-8.4H7.1V12h3V9.4c0-3 1.8-4.7 4.5-4.7 1.3 0 2.7.2 2.7.2v2.9h-1.5c-1.5 0-2 .9-2 1.9V12h3.4l-.5 3.5h-2.9v8.4A12 12 0 0 0 24 12z"/></svg>
      @else<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>@endif
      Continue with {{ $c->name }}
    </a>
  @endforeach
  @if((! empty($google) || count($connections ?? [])) && ($passwordForm ?? true))
    <div class="or" role="separator"><span>{{ __('or use your email') }}</span></div>
  @endif
  @if($passwordForm ?? true)
  <form method="post" action="{{ $preview ?? false ? '#' : url('/login') }}" novalidate>
    @csrf
    <div class="field">
      <label for="email">{{ __('Email') }}</label>
      <input class="input" id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required autofocus>
    </div>
    <div class="field">
      <div class="row-between"><label for="password">{{ __('Password') }}</label><a class="link" href="{{ route('password.request') }}">{{ __('Forgot password?') }}</a></div>
      <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
    </div>
    <label class="check"><input type="checkbox" name="remember" value="1" @checked(session()->hasOldInput() ? old('remember') : true)> {{ __('Keep me signed in on this device') }}</label>
    <div style="height:12px"></div>
    <button class="btn-auth pri" type="submit">{{ __('Sign in') }}</button>
  </form>
  @endif
  @if(! empty($codeSignIn))<p style="text-align:center;margin:16px 0 0"><a class="link" href="{{ ($preview ?? false) ? '#' : url('/login/code') }}">{{ __('Sign in with a one-time code instead') }}</a></p>@endif
  @if(! empty($signup))
    <p class="sub" style="margin:20px 0 0;font-size:14px">{{ __('New here?') }} <a class="link" href="{{ ($preview ?? false) ? '#' : url('/signup') }}">{{ __('Create an account') }}</a></p>
  @else
    <p class="sub" style="margin:20px 0 0;font-size:14px">{{ __('New here? Ask your admin for an invite.') }}</p>
  @endif
@endsection
