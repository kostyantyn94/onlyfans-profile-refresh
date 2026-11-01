@props(['at' => null, 'empty' => '—', 'parts' => 1])
{{-- A relative time ("5m ago", "in 23h") with the exact UTC time on hover and in datetime. --}}
@if ($at)
    <time {{ $attributes }} datetime="{{ $at->toIso8601String() }}" title="{{ $at->format('M j, Y H:i:s T') }}">{{ \App\View\ProfileView::relative($at, $parts) }}</time>
@else
    <span {{ $attributes->merge(['class' => 'muted']) }}>{{ $empty }}</span>
@endif
