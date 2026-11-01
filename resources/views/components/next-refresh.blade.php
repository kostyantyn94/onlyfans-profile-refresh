@props(['profile'])
{{-- Next refresh as text plus a small bar: how much of the current interval (or failure backoff) has passed. --}}
@php($cycle = \App\View\ProfileView::cycle($profile))
<div class="next">
    <span class="next__text">
        @if ($cycle['state'] === 'inflight')
            <span class="muted">in flight</span>
        @elseif ($cycle['state'] === 'due')
            due now
        @else
            <x-ago :at="$profile->next_refresh_at" />
            @if ($cycle['backoff'])<span class="muted">· backoff</span>@endif
        @endif
    </span>
    <span class="meter meter--{{ $cycle['state'] }}" style="--p: {{ $cycle['percent'] }}%" aria-hidden="true"><span></span></span>
</div>
