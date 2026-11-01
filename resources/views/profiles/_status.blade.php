@php
    $status = $profile->last_attempt_status;
    $class = match (true) {
        in_array($status, ['success', 'unchanged'], true) => 'ok',
        in_array($status, ['rate_limited', 'server_error', 'timeout', 'stale_ignored'], true) => 'warn',
        $status === null => 'muted',
        default => 'bad',
    };
@endphp
<span class="badge {{ $class }}">{{ $status ?? 'never refreshed' }}</span>
@if ($profile->isRefreshPending())
    <span class="badge muted" title="Queued {{ $profile->refresh_queued_at }}">pending</span>
@endif
