@props(['url', 'logo' => null])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ $logo ? $logo() : asset('texas-renters-logo-email.png') }}" class="logo" width="200" height="56" alt="{{ config('app.name') }}">
</a>
</td>
</tr>
