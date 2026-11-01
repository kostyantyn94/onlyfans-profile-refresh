{{-- The last attempt's outcome, plus "pending" while a refresh holds the claim. --}}
<span class="chips">
    <x-outcome :value="$profile->last_attempt_status" />
    @if ($profile->isRefreshPending())
        <span class="chip chip--pending tone-neutral" title="Queued {{ $profile->refresh_queued_at->format('M j, Y H:i:s T') }}">pending</span>
    @endif
</span>
