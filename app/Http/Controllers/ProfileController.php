<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Profile;
use App\Refresh\RefreshScheduler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        $profiles = $search === ''
            ? Profile::query()->with('account')->orderBy('account_id')->orderBy('username')->paginate(50)
            : Profile::search($search)->query(fn ($query) => $query->with('account'))->paginate(50);

        return view('profiles.index', [
            'profiles' => $profiles->withQueryString(),
            'search' => $search,
            'stats' => $this->stats(),
        ]);
    }

    /**
     * The counts above the list: one aggregate pass over profiles plus the
     * account count. "Failing" uses the same rule as ProfileView::isFailing.
     */
    private function stats(): array
    {
        $row = Profile::query()->toBase()->selectRaw(<<<'SQL'
            count(*) AS tracked,
            count(refresh_queued_at) AS pending,
            min(refresh_queued_at) AS oldest_pending,
            count(*) FILTER (WHERE consecutive_failures > 0
                OR (last_failure_at IS NOT NULL AND (last_success_at IS NULL OR last_failure_at > last_success_at))) AS failing,
            count(*) FILTER (WHERE last_success_at >= ?) AS refreshed_24h
            SQL, [now()->subDay()])->first();

        return [
            'tracked' => (int) $row->tracked,
            'pending' => (int) $row->pending,
            'oldest_pending' => $row->oldest_pending ? Carbon::parse($row->oldest_pending) : null,
            'failing' => (int) $row->failing,
            'refreshed_24h' => (int) $row->refreshed_24h,
            'accounts' => Account::query()->count(),
        ];
    }

    public function show(Profile $profile): View
    {
        return view('profiles.show', [
            'profile' => $profile->load('account'),
            'attempts' => $profile->attempts()->latest('id')->limit(20)->get(),
        ]);
    }

    /** Start tracking a username on the Fans API account and refresh it now. */
    public function store(Request $request, RefreshScheduler $scheduler): RedirectResponse
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:64', 'regex:/^@?[A-Za-z0-9_.-]+$/']]);
        $account = Account::query()->where('slug', 'fansapi')->firstOrFail();

        $profile = Profile::query()->createOrFirst([
            'account_id' => $account->id,
            'username' => Profile::normalizeUsername($data['username']),
        ]);

        $scheduler->enqueue($profile);

        return redirect()->route('profiles.show', $profile)->with('status', 'Refresh queued.');
    }

    public function refresh(Profile $profile, RefreshScheduler $scheduler): RedirectResponse
    {
        $refreshId = $scheduler->enqueue($profile);

        return back()->with('status', $refreshId ? "Refresh {$refreshId} queued." : 'A refresh is already pending for this profile.');
    }
}
