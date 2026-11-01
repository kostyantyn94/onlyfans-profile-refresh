@extends('layout')

@section('title', $profile->username)

@section('content')
    <div class="toolbar">
        <div style="display: flex; gap: 16px; align-items: center;">
            @if ($profile->avatar_url)
                <img src="{{ $profile->avatar_url }}" alt="" width="64" height="64" style="border-radius: 50%; object-fit: cover;" referrerpolicy="no-referrer">
            @endif
            <div>
                <h1 style="margin: 0;">{{ $profile->name ?? $profile->username }}
                    @if ($profile->is_verified)<span class="badge ok">verified</span>@endif
                </h1>
                <div class="muted">{{ '@'.$profile->username }} · account {{ $profile->account->slug }}
                    @if ($profile->upstream_id) · OnlyFans id {{ $profile->upstream_id }} @endif</div>
            </div>
        </div>
        <form method="post" action="{{ route('profiles.refresh', $profile) }}">
            @csrf
            <button type="submit" @disabled($profile->isRefreshPending())>Refresh now</button>
        </form>
    </div>

    <div class="grid">
        @foreach ([
            'Likes' => $profile->likes_count,
            'Posts' => $profile->posts_count,
            'Photos' => $profile->photos_count,
            'Videos' => $profile->videos_count,
            'Audios' => $profile->audios_count,
            'Media' => $profile->medias_count,
            'Subscribers' => $profile->subscribers_count,
        ] as $label => $value)
            <div class="card stat"><span class="muted">{{ $label }}</span><b>{{ $value === null ? '—' : number_format($value) }}</b></div>
        @endforeach
        <div class="card stat"><span class="muted">Subscription</span><b>{{ $profile->subscribe_price === null ? '—' : ((float) $profile->subscribe_price === 0.0 ? 'free' : '$'.$profile->subscribe_price) }}</b></div>
        <div class="card stat"><span class="muted">Joined</span><b>{{ $profile->joined_at?->toDateString() ?? '—' }}</b></div>
    </div>

    <h2>Refresh status</h2>
    <div class="card">
        <table>
            <tr><th>Last attempt</th><td>@include('profiles._status') {{ $profile->last_attempt_at ?? '—' }}</td></tr>
            <tr><th>Last successful refresh</th><td>{{ $profile->last_success_at ?? '—' }}</td></tr>
            <tr><th>Last failure</th><td>{{ $profile->last_failure_at ? $profile->last_failure_at.' — '.$profile->last_failure_reason : '—' }}</td></tr>
            <tr><th>Consecutive failures</th><td>{{ $profile->consecutive_failures }}</td></tr>
            <tr><th>Stored revision</th><td>{{ $profile->revision ?? '—' }}</td></tr>
            <tr><th>Next refresh</th><td>{{ $profile->next_refresh_at ?? 'when the scheduler next runs' }}
                    <span class="muted">({{ ($profile->likes_count ?? 0) > config('refresh.likes_threshold') ? 'every 24 h: above 100,000 likes' : 'every 72 h' }})</span></td></tr>
            <tr><th>Pending refresh</th><td>{{ $profile->pending_refresh_id ? $profile->pending_refresh_id.' since '.$profile->refresh_queued_at : '—' }}</td></tr>
        </table>
    </div>

    <h2>Recent upstream attempts</h2>
    <div class="wrap">
        <table>
            <thead><tr><th>When</th><th>Refresh</th><th>Job attempt</th><th>Handler</th><th>Outcome</th><th>HTTP</th><th class="num">Likes</th><th class="num">Revision</th><th class="num">Retry in</th><th class="num">ms</th><th>Error</th></tr></thead>
            <tbody>
            @forelse ($attempts as $attempt)
                <tr>
                    <td class="muted">{{ $attempt->created_at }}</td>
                    <td class="muted" title="{{ $attempt->refresh_id }}">{{ \Illuminate\Support\Str::limit($attempt->refresh_id, 8, '') }}</td>
                    <td class="num">{{ $attempt->job_attempt }}</td>
                    <td>{{ $attempt->handler }}</td>
                    <td>{{ $attempt->outcome }}</td>
                    <td>{{ $attempt->http_status ?? '—' }}</td>
                    <td class="num">{{ $attempt->likes === null ? '—' : number_format($attempt->likes) }}</td>
                    <td class="num">{{ $attempt->revision ?? '—' }}</td>
                    <td class="num">{{ $attempt->retry_in_seconds ? $attempt->retry_in_seconds.' s' : '—' }}</td>
                    <td class="num">{{ $attempt->duration_ms }}</td>
                    <td class="muted">{{ $attempt->error }}</td>
                </tr>
            @empty
                <tr><td colspan="11" class="muted">No attempts yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($profile->about)
        <h2>About</h2>
        <details class="card"><summary class="muted">Show the profile's about text</summary>
            <p>{{ \Illuminate\Support\Str::limit(html_entity_decode(strip_tags(str_replace('<br />', ' ', $profile->about))), 1000) }}</p>
        </details>
    @endif

    <h2>Full upstream profile ({{ count($profile->raw ?? []) }} fields)</h2>
    <details class="card"><summary class="muted">Raw JSON as received</summary>
        <pre>{{ json_encode($profile->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
    </details>
@endsection
