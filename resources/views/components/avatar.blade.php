@props(['profile', 'size' => null])
{{--
    Initials underneath, the OnlyFans avatar on top. If the image is missing or
    fails to load (the layout removes broken [data-fallback] images), the
    initials show. no-referrer: the CDN does not learn which page shows it.
--}}
<span {{ $attributes->class(['avatar', 'avatar--'.$size => $size]) }} style="--h: {{ \App\View\ProfileView::hue($profile) }}">
    <span aria-hidden="true">{{ \App\View\ProfileView::initials($profile) }}</span>
    @if (preg_match('~^https?://~i', (string) $profile->avatar_url))
        <img src="{{ $profile->avatar_url }}" alt="" referrerpolicy="no-referrer" loading="lazy" decoding="async" data-fallback>
    @endif
</span>
