@php($brand ??= \App\Services\Brand::current())
<!doctype html>
<html><body style="margin:0;background:#F7F8FA;font-family:Inter,Arial,sans-serif;color:#0B0D12">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 16px">
<table role="presentation" width="100%" style="max-width:520px;background:#fff;border:1px solid #DDE2EA;border-radius:16px" cellpadding="0" cellspacing="0">
@if($brand['level'] === 'clauth')
<tr><td style="padding:28px 28px 8px"><img src="{{ rtrim(config('app.url'), '/') }}/img/brand/logo-on-light.png" width="96" height="32" alt="Clauth" style="display:block;border:0;height:32px;width:auto"></td></tr>
@else
<tr><td style="padding:28px 28px 8px">@if($brand['logo'])<img src="{{ $brand['logo'] }}" height="36" alt="{{ $brand['name'] }}" style="display:block;border:0;height:36px;width:auto">@else<b style="font-size:18px">{{ $brand['account'] }}</b>@endif</td></tr>
@endif
<tr><td style="padding:8px 28px 28px;font-size:15px;line-height:1.6">@yield('body')</td></tr>
</table>
@if($brand['level'] === 'clauth')
<p style="font-size:12px;color:#5B6475;margin:16px 0 0">Clauth · sign-in for The Cake Labs, Alab Apparel, CakesbyGrace and CebuCake apps</p>
@else
<p style="font-size:12px;color:#5B6475;margin:16px 0 0">{{ $brand['account'] }}@if($brand['support_email']) · {{ $brand['support_email'] }}@endif @if($brand['secured_by'])· secured by Clauth @endif</p>
@endif
</td></tr></table></body></html>
