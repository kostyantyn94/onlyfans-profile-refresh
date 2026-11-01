@extends('layout')

@section('content')
    <div class="toolbar">
        <form class="inline" method="get" action="{{ route('profiles.index') }}">
            <input type="text" name="q" value="{{ $search }}" placeholder="Search username or name">
            <button type="submit">Search</button>
        </form>
        <form class="inline" method="post" action="{{ route('profiles.store') }}">
            @csrf
            <input type="text" name="username" placeholder="OnlyFans username, e.g. madison420ivy" required>
            <button type="submit">Track and refresh</button>
        </form>
    </div>
    @error('username')<p class="bad">{{ $message }}</p>@enderror

    <h1>Profiles <span class="muted">({{ $profiles->total() }})</span></h1>

    <div class="wrap">
        <table>
            <thead>
            <tr>
                <th>Username</th><th>Account</th><th>Name</th><th class="num">Likes</th><th class="num">Revision</th>
                <th>Last attempt</th><th>Last success</th><th>Last failure</th><th>Next refresh</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($profiles as $profile)
                <tr>
                    <td><a href="{{ route('profiles.show', $profile) }}">{{ $profile->username }}</a></td>
                    <td class="muted">{{ $profile->account->slug }}</td>
                    <td>{{ $profile->name }}</td>
                    <td class="num">{{ $profile->likes_count === null ? '—' : number_format($profile->likes_count) }}</td>
                    <td class="num muted">{{ $profile->revision ?? '—' }}</td>
                    <td>@include('profiles._status') <span class="muted">{{ $profile->last_attempt_at?->diffForHumans() }}</span></td>
                    <td class="muted">{{ $profile->last_success_at?->diffForHumans() ?? '—' }}</td>
                    <td class="muted" title="{{ $profile->last_failure_reason }}">{{ $profile->last_failure_at ? $profile->last_failure_at->diffForHumans().': '.\Illuminate\Support\Str::limit($profile->last_failure_reason, 40) : '—' }}</td>
                    <td class="muted">{{ $profile->next_refresh_at?->diffForHumans() ?? 'now' }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="muted">No profiles{{ $search !== '' ? ' match "'.$search.'"' : '' }}.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($profiles->hasPages())
        <p>
            @if ($profiles->previousPageUrl())<a href="{{ $profiles->previousPageUrl() }}">&larr; Previous</a>@endif
            <span class="muted">Page {{ $profiles->currentPage() }} of {{ $profiles->lastPage() }}</span>
            @if ($profiles->nextPageUrl())<a href="{{ $profiles->nextPageUrl() }}">Next &rarr;</a>@endif
        </p>
    @endif
@endsection
