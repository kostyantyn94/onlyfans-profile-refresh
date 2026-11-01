@props(['value' => null, 'empty' => 'never refreshed'])
{{-- A refresh outcome as a chip: success/unchanged good, retried or harmless warn, the rest bad. --}}
<span {{ $attributes->class(['chip', 'tone-'.\App\View\ProfileView::tone($value)]) }} title="{{ \App\View\ProfileView::meaning($value) }}">{{ $value ?? $empty }}</span>
