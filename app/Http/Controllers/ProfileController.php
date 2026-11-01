<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Profile;
use App\Refresh\RefreshScheduler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        $profiles = $search === ''
            ? Profile::query()->with('account')->orderBy('account_id')->orderBy('username')->paginate(50)
            : Profile::search($search)->query(fn ($query) => $query->with('account'))->paginate(50);

        return view('profiles.index', ['profiles' => $profiles->withQueryString(), 'search' => $search]);
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
