<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>@yield('title', 'Profiles') · Profile Refresher</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    <script>
        // A broken avatar (expired CDN link, blocked host) is removed so the initials under it show.
        document.addEventListener('error', function (event) {
            if (event.target instanceof HTMLImageElement && event.target.hasAttribute('data-fallback')) event.target.remove();
        }, true);
    </script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="topbar">
    <div class="topbar__inner">
        <a class="brand" href="{{ route('profiles.index') }}">
            <span class="brand__mark"><x-icon name="refresh" :size="17" /></span>
            <span class="brand__text">
                <span class="brand__name">Profile Refresher</span>
                <span class="brand__sub">OnlyFans profiles via the Fans API</span>
            </span>
        </a>
        <nav class="topnav" aria-label="Main">
            <a href="{{ route('profiles.index') }}" @if (request()->routeIs('profiles.*')) aria-current="page" @endif>Profiles</a>
            <a href="/horizon">Horizon <x-icon name="external" :size="13" /></a>
        </nav>
    </div>
</header>
<main id="main" class="container">
    @if (session('status'))
        <div class="flash" role="status"><x-icon name="check-circle" /><span>{{ session('status') }}</span></div>
    @endif
    @yield('content')
</main>
<footer class="footer">
    <div class="footer__inner">
        <span>Refreshed by Horizon workers: every 24 h above {{ number_format(config('refresh.likes_threshold')) }} likes, every 72 h otherwise.</span>
        <span>Times in UTC · failures never overwrite stored data</span>
    </div>
</footer>
<script>
    // While a refresh is pending ([data-poll] on the page), re-fetch this page every
    // few seconds and swap the [data-live] regions in place. Stops when nothing is
    // pending any more, after ten minutes, or after repeated errors.
    (function () {
        if (!document.querySelector('[data-poll]') || !window.fetch || !window.DOMParser) return;
        var every = 3000, left = 200, errors = 0;

        function swap(doc) {
            document.querySelectorAll('[data-live]').forEach(function (region) {
                var fresh = doc.querySelector('[data-live="' + region.getAttribute('data-live') + '"]');
                // Leave a region alone while the user is interacting with it.
                if (fresh && !region.contains(document.activeElement)) region.replaceWith(fresh);
            });
        }

        function pause() {
            document.querySelectorAll('[data-poll]').forEach(function (el) {
                el.setAttribute('data-paused', '');
                el.querySelector('.live__text').textContent = 'Auto-update paused, reload to update';
            });
        }

        function tick() {
            if (document.hidden) return setTimeout(tick, every);
            if (left-- <= 0) return pause();
            fetch(location.href, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' } })
                .then(function (response) {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.text();
                })
                .then(function (html) {
                    errors = 0;
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    // Ask before swapping: swapping moves the nodes out of doc.
                    var stillPending = doc.querySelector('[data-poll]') !== null;
                    swap(doc);
                    if (stillPending) setTimeout(tick, every);
                })
                .catch(function () {
                    if (++errors >= 5) return pause();
                    setTimeout(tick, every * 2);
                });
        }

        setTimeout(tick, every);
    })();
</script>
</body>
</html>
