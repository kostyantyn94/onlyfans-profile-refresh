@extends('layout')

@use('App\View\ProfileView')
@use('Illuminate\Support\Str')

@section('title', $profile->username)

@php
    $pending = $profile->isRefreshPending();
    $verdict = ProfileView::verdict($profile);
    $cycle = ProfileView::cycle($profile);
    $popular = ($profile->likes_count ?? 0) > config('refresh.likes_threshold');
    $cadence = $popular
        ? 'every '.config('refresh.interval_hours.popular').' h: above '.number_format(config('refresh.likes_threshold')).' likes'
        : 'every '.config('refresh.interval_hours.default').' h: '.number_format(config('refresh.likes_threshold')).' likes or fewer';
    $revisionTime = ProfileView::revisionTime($profile->revision);
    $fields = count($profile->raw ?? []);
@endphp

@section('content')
    <nav class="crumbs" aria-label="Breadcrumb">
        <a href="{{ route('profiles.index') }}"><x-icon name="arrow-left" :size="14" /> Profiles</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page" class="mono">{{ '@'.$profile->username }}</span>
    </nav>

    <section class="card hero" style="--h: {{ ProfileView::hue($profile) }}" aria-label="Profile">
        <div class="hero__banner" aria-hidden="true"></div>
        <div class="hero__body">
            <x-avatar :profile="$profile" size="xl" />
            <div class="hero__id">
                <h1 class="hero__name">
                    {{ $profile->name ?? $profile->username }}
                    @if ($profile->is_verified)
                        <span class="verified" title="Verified on OnlyFans"><x-icon name="verified" :size="15" /> Verified</span>
                    @endif
                </h1>
                <p class="hero__meta">
                    <span class="mono">{{ '@'.$profile->username }}</span>
                    <span class="tag" title="Upstream account (API key / team): {{ $profile->account->name }}">account {{ $profile->account->slug }}</span>
                    @if ($profile->upstream_id)
                        <span>OnlyFans id <span class="mono">{{ $profile->upstream_id }}</span></span>
                    @endif
                    @if ($profile->account->slug === 'fansapi')
                        <a href="https://onlyfans.com/{{ rawurlencode($profile->username) }}" target="_blank" rel="noopener noreferrer">onlyfans.com <x-icon name="external" :size="13" /></a>
                    @endif
                </p>
            </div>
            <div class="hero__actions" data-live="actions">
                @if ($pending)<x-live />@endif
                @unless ($readOnly ?? false)
                <form method="post" action="{{ route('profiles.refresh', $profile) }}">
                    @csrf
                    <button class="btn btn--primary" type="submit" @disabled($pending) @if ($pending) title="A refresh is already pending for this profile" @endif>
                        <x-icon name="refresh" :size="15" /> {{ $pending ? 'Refresh pending' : 'Refresh now' }}
                    </button>
                </form>
                @endunless
            </div>
        </div>
    </section>

    <dl class="tiles" aria-label="Profile stats" data-live="tiles">
        <div class="card tile tile--hero">
            <dt>Likes</dt>
            <dd>{{ $profile->likes_count === null ? '—' : number_format($profile->likes_count) }}
                <span class="tile__note">{{ $profile->likes_count === null ? 'no valid response stored yet' : 'refreshed '.$cadence }}</span></dd>
        </div>
        @foreach ([
            'Posts' => $profile->posts_count,
            'Photos' => $profile->photos_count,
            'Videos' => $profile->videos_count,
            'Media' => $profile->medias_count,
        ] as $label => $value)
            <div class="card tile"><dt>{{ $label }}</dt><dd>{{ $value === null ? '—' : number_format($value) }}</dd></div>
        @endforeach
        @if ($profile->subscribers_count !== null)
            <div class="card tile"><dt>Subscribers</dt><dd>{{ number_format($profile->subscribers_count) }}</dd></div>
        @endif
        <div class="card tile">
            <dt>Subscription</dt>
            <dd>{{ $profile->subscribe_price === null ? '—' : ((float) $profile->subscribe_price === 0.0 ? 'Free' : '$'.$profile->subscribe_price) }}
                @if ($profile->subscribe_price !== null && (float) $profile->subscribe_price > 0)<span class="tile__note">per month</span>@endif</dd>
        </div>
        <div class="card tile">
            <dt>Joined</dt>
            <dd>{{ $profile->joined_at?->format('M Y') ?? '—' }}
                @if ($profile->joined_at)<span class="tile__note">{{ $profile->joined_at->format('M j, Y') }}</span>@endif</dd>
        </div>
    </dl>

    <section class="card health" aria-labelledby="health-title" data-live="health">
        <div class="card__head">
            <div>
                <h2 id="health-title">Refresh health</h2>
                <p class="card__sub">Last attempt, last success and last failure are recorded separately; a failed attempt never overwrites stored data.</p>
            </div>
            <span class="chip chip--lg tone-{{ $verdict['tone'] }}">{{ $verdict['title'] }}</span>
        </div>

        <div class="health__body">
            <div class="verdict tone-{{ $verdict['tone'] }}" role="status">
                <span class="verdict__icon"><x-icon :name="$verdict['icon']" :size="20" /></span>
                <p><span class="verdict__title">{{ $verdict['title'] }}.</span> {{ $verdict['text'] }}</p>
            </div>

            <div class="cycle">
                <div class="cycle__labels">
                    <span>Last attempt <b><x-ago :at="$profile->last_attempt_at" empty="none yet" /></b></span>
                    <span>Next refresh <b>
                        @if ($cycle['state'] === 'inflight')
                            in flight now
                        @elseif ($cycle['state'] === 'due')
                            due now
                        @else
                            <x-ago :at="$profile->next_refresh_at" :parts="2" />
                        @endif
                    </b></span>
                </div>
                <div class="cycle__track">
                    <span class="meter meter--{{ $cycle['state'] }}" style="--p: {{ $cycle['percent'] }}%" aria-hidden="true"><span></span></span>
                    @if ($cycle['state'] === 'scheduled')<i class="cycle__now" style="left: {{ $cycle['percent'] }}%" aria-hidden="true"></i>@endif
                </div>
                <p class="cycle__caption">
                    @if ($cycle['state'] === 'inflight')
                        A refresh holds the claim; the cycle restarts when it finishes.
                    @elseif ($cycle['state'] === 'due')
                        Due: the scheduler queues due profiles every minute.
                    @elseif ($cycle['span'])
                        {{ round($cycle['percent']) }}% of the {{ $cycle['span'] }} {{ $cycle['backoff'] ? 'failure backoff' : 'refresh interval' }} elapsed.
                    @endif
                </p>
            </div>

            <dl class="milestones">
                <div class="milestone tone-{{ ProfileView::tone($profile->last_attempt_status) }}">
                    <dt>Last attempt</dt>
                    <dd>
                        <x-outcome :value="$profile->last_attempt_status" />
                        <span class="milestone__main"><x-ago :at="$profile->last_attempt_at" empty="Never" /></span>
                        @if ($profile->last_attempt_at)<span class="milestone__sub">{{ $profile->last_attempt_at->format('M j, Y H:i:s T') }}</span>@endif
                    </dd>
                </div>
                <div class="milestone {{ $profile->last_success_at ? 'tone-good' : 'tone-neutral' }}">
                    <dt>Last successful refresh</dt>
                    <dd>
                        <span class="milestone__main"><x-ago :at="$profile->last_success_at" empty="Never" /></span>
                        <span class="milestone__sub">{{ $profile->last_success_at ? $profile->last_success_at->format('M j, Y H:i:s T') : 'No valid response stored yet' }}</span>
                    </dd>
                </div>
                <div class="milestone {{ $profile->last_failure_at ? (ProfileView::isFailing($profile) ? 'tone-bad' : 'tone-neutral') : 'tone-good' }}">
                    <dt>Last failure</dt>
                    <dd>
                        <span class="milestone__main"><x-ago :at="$profile->last_failure_at" empty="None recorded" /></span>
                        @if ($profile->last_failure_at)
                            <span class="milestone__sub">{{ $profile->last_failure_at->format('M j, Y H:i:s T') }}{{ ProfileView::isFailing($profile) ? '' : ' · recovered since' }}</span>
                            <span class="milestone__reason">{{ $profile->last_failure_reason ?? 'no reason recorded' }}</span>
                        @endif
                    </dd>
                </div>
                <div class="milestone tone-accent">
                    <dt>Next refresh</dt>
                    <dd>
                        <span class="milestone__main">
                            @if ($profile->next_refresh_at)<x-ago :at="$profile->next_refresh_at" :parts="2" />@else when the scheduler next runs @endif
                        </span>
                        <span class="milestone__sub">
                            {{ $profile->next_refresh_at?->format('M j, Y H:i:s T') }}
                            {{ $profile->next_refresh_at ? '·' : '' }}
                            {{ $pending ? 'recomputed when the pending refresh finishes' : ($cycle['backoff'] ? 'failure backoff, doubling per failure' : $cadence) }}
                        </span>
                    </dd>
                </div>
            </dl>

            <dl class="milestones">
                <div class="milestone {{ $pending ? 'tone-neutral' : '' }}">
                    <dt>Pending claim</dt>
                    <dd>
                        @if ($pending)
                            <span class="chip chip--pending tone-neutral">pending</span>
                            <span class="milestone__main">since <x-ago :at="$profile->refresh_queued_at" /></span>
                            <span class="milestone__sub">refresh <span class="mono" title="{{ $profile->pending_refresh_id }}">{{ $profile->pending_refresh_id }}</span></span>
                            <span class="milestone__sub">taken over by the scheduler if still held <x-ago :at="$profile->refresh_queued_at->copy()->addMinutes(config('refresh.claim_expires_after_minutes'))" /></span>
                        @else
                            <span class="milestone__main">None</span>
                            <span class="milestone__sub">No refresh is queued or running{{ ($readOnly ?? false) ? '.' : '; Refresh now can claim one.' }}</span>
                        @endif
                    </dd>
                </div>
                <div class="milestone {{ $profile->consecutive_failures > 0 ? 'tone-bad' : '' }}">
                    <dt>Consecutive failures</dt>
                    <dd>
                        <span class="milestone__main">{{ $profile->consecutive_failures }}</span>
                        <span class="milestone__sub">{{ $profile->consecutive_failures > 0 ? 'failed refreshes since the last success (retries not counted)' : 'reset by every valid response' }}</span>
                    </dd>
                </div>
                <div class="milestone">
                    <dt>Stored revision</dt>
                    <dd>
                        <span @class(['milestone__main', 'mono' => $profile->revision !== null])>{{ $profile->revision ?? '—' }}</span>
                        <span class="milestone__sub">{{ $revisionTime ? 'Fans API snapshot of '.$revisionTime->format('M j, Y H:i:s T') : 'only a newer revision can overwrite it' }}</span>
                    </dd>
                </div>
            </dl>
        </div>
    </section>

    <section class="card" aria-labelledby="attempts-title" data-live="attempts">
        <div class="card__head">
            <div>
                <h2 id="attempts-title">Recent upstream attempts <span class="count">{{ $attempts->count() }}</span></h2>
                <p class="card__sub">One row per upstream request, newest first. Retries of the same refresh are joined on the timeline.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table class="table table--attempts">
                <thead>
                <tr>
                    <th scope="col">When</th>
                    <th scope="col">Outcome</th>
                    <th scope="col">HTTP</th>
                    <th scope="col" class="num">Likes</th>
                    <th scope="col" class="num">Revision</th>
                    <th scope="col" class="num">Retry in</th>
                    <th scope="col" class="num">Duration</th>
                    <th scope="col">Refresh</th>
                    <th scope="col" class="num">Try</th>
                    <th scope="col">Handler</th>
                    <th scope="col">Error</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($attempts as $i => $attempt)
                    @php
                        $up = $i > 0 && $attempts[$i - 1]->refresh_id === $attempt->refresh_id;
                        $down = isset($attempts[$i + 1]) && $attempts[$i + 1]->refresh_id === $attempt->refresh_id;
                        $http = $attempt->http_status;
                        $httpTone = $http === null ? '' : ($http < 300 ? 'good' : ($http === 429 || $http >= 500 ? 'warn' : 'bad'));
                    @endphp
                    <tr>
                        <td @class(['tl', 'tl--up' => $up, 'tl--down' => $down])>
                            <span class="tl__dot tone-{{ ProfileView::tone($attempt->outcome) }}" aria-hidden="true"></span>
                            <x-ago :at="$attempt->created_at" />
                            <span class="when">{{ $attempt->created_at?->format('M j, H:i:s') }}</span>
                        </td>
                        <td><x-outcome :value="$attempt->outcome" /></td>
                        <td>@if ($http)<span class="http http--{{ $httpTone }}">{{ $http }}</span>@else<span class="muted">—</span>@endif</td>
                        <td class="num">{{ $attempt->likes === null ? '—' : number_format($attempt->likes) }}</td>
                        <td class="num">@if ($attempt->revision !== null)<span class="mono">{{ $attempt->revision }}</span>@else — @endif</td>
                        <td class="num">{{ $attempt->retry_in_seconds ? $attempt->retry_in_seconds.' s' : '—' }}</td>
                        <td class="num">{{ $attempt->duration_ms >= 1000 ? number_format($attempt->duration_ms / 1000, 1).' s' : $attempt->duration_ms.' ms' }}</td>
                        <td>
                            @if ($up)
                                <span class="sr-only">same refresh</span>
                            @else
                                <span class="refresh-id" title="{{ $attempt->refresh_id }}">{{ Str::limit($attempt->refresh_id, 8, '') }}</span>
                            @endif
                        </td>
                        <td class="num">{{ $attempt->job_attempt ?? '—' }}</td>
                        <td><span class="tag">{{ $attempt->handler }}</span></td>
                        <td>@if ($attempt->error)<span class="error-text">{{ $attempt->error }}</span>@else<span class="muted">—</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="empty">No upstream attempts yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($profile->about)
        <details class="card disclosure">
            <summary>
                <span class="disclosure__chevron"><x-icon name="chevron-down" /></span>
                <span class="disclosure__title">About</span>
                <span class="disclosure__meta">Bio from OnlyFans, shown as plain text</span>
            </summary>
            <div class="disclosure__body"><p class="about">{{ ProfileView::aboutText($profile->about) }}</p></div>
        </details>
    @endif

    <details class="card disclosure">
        <summary>
            <span class="disclosure__chevron"><x-icon name="chevron-down" /></span>
            <span class="disclosure__title">Full upstream profile ({{ $fields }} fields)</span>
            <span class="disclosure__meta">Raw JSON as received, stored in profiles.raw</span>
        </summary>
        <div class="disclosure__body">
            @if ($fields > 0)
                <pre class="code">{{ json_encode($profile->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            @else
                <p class="muted">Nothing stored yet: the first valid response fills it.</p>
            @endif
        </div>
    </details>
@endsection
