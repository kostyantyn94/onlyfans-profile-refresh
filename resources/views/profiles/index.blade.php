@extends('layout')

@use('App\View\ProfileView')
@use('Illuminate\Support\Str')

@section('content')
    <div class="page-head">
        <div>
            <p class="eyebrow"><x-icon name="activity" :size="14" /> Refresh operations</p>
            <h1>Creator profiles</h1>
            <p class="lede">Every tracked profile, its last refresh and the next one. Last attempt, last success and last failure are kept apart: a failed refresh never touches the stored data.</p>
        </div>
    </div>

    <section class="kpis" aria-label="Overview" data-live="kpis">
        <div class="card kpi kpi--accent">
            <div class="kpi__top"><span class="kpi__icon"><x-icon name="users" /></span>Profiles tracked</div>
            <div class="kpi__value">{{ number_format($stats['tracked']) }}</div>
            <div class="kpi__hint">on {{ $stats['accounts'] }} {{ Str::plural('account', $stats['accounts']) }}</div>
        </div>
        <div class="card kpi {{ $stats['pending'] > 0 ? 'kpi--accent' : '' }}">
            <div class="kpi__top"><span class="kpi__icon"><x-icon name="clock" /></span>Refreshes pending</div>
            <div class="kpi__value">{{ number_format($stats['pending']) }}</div>
            <div class="kpi__hint">
                @if ($stats['oldest_pending'])
                    oldest queued <x-ago :at="$stats['oldest_pending']" />
                @else
                    nothing queued or running
                @endif
            </div>
        </div>
        <div class="card kpi {{ $stats['failing'] > 0 ? 'kpi--bad' : 'kpi--good' }}">
            <div class="kpi__top"><span class="kpi__icon"><x-icon name="alert" /></span>Failing now</div>
            <div class="kpi__value">{{ number_format($stats['failing']) }}</div>
            <div class="kpi__hint">last attempts failed, no success since</div>
        </div>
        <div class="card kpi kpi--good">
            <div class="kpi__top"><span class="kpi__icon"><x-icon name="check-circle" /></span>Refreshed in 24 h</div>
            <div class="kpi__value">{{ number_format($stats['refreshed_24h']) }}</div>
            <div class="kpi__hint">
                {{ $stats['tracked'] > 0 ? round($stats['refreshed_24h'] / $stats['tracked'] * 100).'% of tracked profiles' : 'no profiles yet' }}
            </div>
        </div>
    </section>

    <section class="card tools" aria-label="Search and track">
        <form class="tool" role="search" method="get" action="{{ route('profiles.index') }}">
            <label class="tool__label" for="q">Search profiles</label>
            <div class="input-group">
                <span class="input-group__addon"><x-icon name="search" /></span>
                <input class="input" id="q" type="search" name="q" value="{{ $search }}" placeholder="Username or name" autocomplete="off">
                <button class="btn btn--secondary" type="submit">Search</button>
            </div>
            @if ($search !== '')
                <p class="tool__hint">Showing matches for “{{ $search }}” · <a href="{{ route('profiles.index') }}">clear search</a></p>
            @else
                <p class="tool__hint">Scout search over usernames and display names, case-insensitive.</p>
            @endif
        </form>

        @unless ($readOnly ?? false)
        <form class="tool" method="post" action="{{ route('profiles.store') }}">
            @csrf
            <label class="tool__label" for="username">Track a username</label>
            <div @class(['input-group', 'is-invalid' => $errors->has('username')])>
                <span class="input-group__addon" aria-hidden="true">{{ '@' }}</span>
                <input class="input" id="username" name="username" value="{{ old('username') }}" placeholder="madison420ivy"
                       required maxlength="64" autocomplete="off" autocapitalize="none" spellcheck="false"
                       @error('username') aria-invalid="true" aria-describedby="username-error" @enderror>
                <button class="btn btn--primary" type="submit"><x-icon name="refresh" :size="15" /> Track and refresh</button>
            </div>
            @error('username')
                <p class="field-error" id="username-error"><x-icon name="alert" :size="14" />{{ $message }}</p>
            @else
                <p class="tool__hint">Adds the OnlyFans username to the Fans API account and queues its first refresh now.</p>
            @enderror
        </form>
        @endunless
    </section>

    @php($pendingHere = $profiles->contains(fn ($profile) => $profile->isRefreshPending()))

    <section class="card" aria-labelledby="profiles-title">
        <div data-live="profiles">
            <div class="card__head">
                <h2 id="profiles-title">{{ $search === '' ? 'All profiles' : 'Search results' }} <span class="count">{{ number_format($profiles->total()) }}</span></h2>
                @if ($pendingHere)<x-live />@endif
            </div>

            <div class="table-wrap">
                <table class="table table--profiles">
                    <thead>
                    <tr>
                        <th scope="col">Profile</th>
                        <th scope="col">Account</th>
                        <th scope="col">Last attempt</th>
                        <th scope="col" class="num">Likes</th>
                        <th scope="col">Last success</th>
                        <th scope="col">Next refresh</th>
                        <th scope="col">Last failure</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($profiles as $profile)
                        <tr>
                            <th scope="row">
                                <a class="profile-link" href="{{ route('profiles.show', $profile) }}">
                                    <x-avatar :profile="$profile" />
                                    <span class="profile-link__text">
                                        <span class="profile-link__name">{{ $profile->name ?? $profile->username }}</span>
                                        <span class="profile-link__user">{{ '@'.$profile->username }}</span>
                                    </span>
                                </a>
                            </th>
                            <td><span class="tag" title="{{ $profile->account->name }}">{{ $profile->account->slug }}</span></td>
                            <td>
                                <div class="status-cell">
                                    @include('profiles._status')
                                    @if ($profile->last_attempt_at)<x-ago :at="$profile->last_attempt_at" />@endif
                                </div>
                            </td>
                            <td class="num likes">{{ $profile->likes_count === null ? '—' : number_format($profile->likes_count) }}</td>
                            <td class="nowrap"><x-ago :at="$profile->last_success_at" empty="never" /></td>
                            <td><x-next-refresh :profile="$profile" /></td>
                            <td>
                                @if ($profile->last_failure_at)
                                    @php($recovered = ! ProfileView::isFailing($profile))
                                    <div @class(['failure', 'failure--recovered' => $recovered])>
                                        <span class="failure__reason" title="{{ $profile->last_failure_reason }}">{{ Str::limit($profile->last_failure_reason ?? 'unknown', 70) }}</span>
                                        <span class="failure__when"><x-ago :at="$profile->last_failure_at" />{{ $recovered ? ' · recovered since' : '' }}</span>
                                    </div>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="empty">
                                @if ($search !== '')
                                    No profiles match “{{ $search }}”. <a href="{{ route('profiles.index') }}">Show all profiles</a>
                                @else
                                    No profiles yet. Track a username above to start.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if ($profiles->hasPages())
                <nav class="pager" aria-label="Pages">
                    <span class="muted">Page {{ $profiles->currentPage() }} of {{ $profiles->lastPage() }} · {{ $profiles->firstItem() }}–{{ $profiles->lastItem() }} of {{ number_format($profiles->total()) }}</span>
                    <span class="pager__links">
                        @if ($profiles->previousPageUrl())<a class="btn btn--secondary" href="{{ $profiles->previousPageUrl() }}" rel="prev">&larr; Previous</a>@endif
                        @if ($profiles->nextPageUrl())<a class="btn btn--secondary" href="{{ $profiles->nextPageUrl() }}" rel="next">Next &rarr;</a>@endif
                    </span>
                </nav>
            @endif
        </div>

        <details class="legend">
            <summary>What the statuses mean</summary>
            <div class="legend__grid">
                @foreach (['good' => 'Data is current', 'warn' => 'Retried or harmless', 'bad' => 'Refresh failed, data kept'] as $tone => $heading)
                    <div class="legend__group">
                        <h3>{{ $heading }}</h3>
                        <ul>
                            @foreach (ProfileView::legend()[$tone] as $outcome)
                                <li><x-outcome :value="$outcome" /> {{ ProfileView::meaning($outcome) }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
                <div class="legend__group">
                    <h3>Other states</h3>
                    <ul>
                        <li><span class="chip chip--pending tone-neutral">pending</span> A refresh holds the claim: queued or running</li>
                        <li><x-outcome /> No upstream attempt yet</li>
                    </ul>
                </div>
            </div>
        </details>
    </section>
@endsection
