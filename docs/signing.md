# Signing OnlyFans requests

How the service makes `GET https://onlyfans.com/api2/v2/users/{username}` itself: the headers, where
the signing rules come from, what happens when OnlyFans refuses a signature, and the session.
Code: [`app/Upstream/OnlyFans/`](../app/Upstream/OnlyFans/).

## The request

| Header | Value |
|---|---|
| `sign` | `prefix:sha1:hex(checksum):suffix`, where `sha1` = SHA-1 of `static_param \n time \n path?query \n user_id` and `checksum` = the sum of the ASCII codes of `sha1` at the rule's `checksum_indexes`, plus `checksum_constant` (absolute value) |
| `time` | the Unix time in ms that was signed |
| `app-token` | from the web app (the rules) |
| `user-id` | `0` for a guest, the logged-in user's id otherwise (signed too); left out when the rules say the web app drops it |
| `x-bc` | a random 40-hex token per session, as the web app keeps in local storage |
| `x-of-rev` | the web app release the rules were read from, as the web app sends it (OnlyFans does not require it) |
| `Cookie`, `User-Agent` | the session's unexpired cookies (`sess`, `__cf_bm`, `_cfuvid`) and the user agent they belong to |

[`RequestSigner`](../app/Upstream/OnlyFans/RequestSigner.php) builds `sign`;
[`OnlyFansClient`](../app/Upstream/OnlyFans/OnlyFansClient.php) the rest. What live guest requests
showed on 4 Nov ([15](evidence/15-live-probes.txt)):
- the first signed request works without cookies, and OnlyFans sets `sess`, `__cf_bm` and `_cfuvid`
  on it, so there is no separate bootstrap request;
- without `x-bc` the answer is HTTP 400 "Something went wrong", and `user-id` is optional for a guest;
- an unknown username is HTTP 404 "User not found" (`not_found`, not retried);
- a refused signature is HTTP 400 with `{"error":{"code":401,"message":"Please refresh the page"}}`
  (`signature_rejected`).

## Where the rules come from: OnlyFans' own web app

The rules change with OnlyFans releases, so they are read at run time, never hard-coded.
[`WebAppRulesSource`](../app/Upstream/OnlyFans/WebAppRulesSource.php) gets them the way a browser
does, in three downloads:

1. **The page** (`https://onlyfans.com/`) lists its scripts. Their paths carry the release, e.g.
   `static2.onlyfans.com/static/prod/f/202611031453-fbc506331f/app.js`.
2. **`app.js`** sets the request headers:
   `const t={...(0,J.A)(e)};t["app-token"]=Pe;…`, with `J=s(802313)` and `Pe="33d5…"` in the same
   module. That gives the `app-token` and the id of the module that signs.
3. **The signing module** (`802313`, in one of the page's small scripts) holds the static param,
   prefix, suffix and checksum. It is obfuscated in the javascript-obfuscator style.

[`WebAppRulesExtractor`](../app/Upstream/OnlyFans/WebAppRulesExtractor.php) reads that module as
text. It undoes the obfuscation step by step:

- **String table.** Every string literal sits encrypted in one array. The decoder function uses the
  obfuscator's own base64 alphabet, reads the bytes as UTF-8, then applies RC4 with a key that each
  call site passes. The extractor decodes the same way.
- **Rotation.** The array ships rotated. A loop shifts it until a checksum of `parseInt()` over
  decoded strings equals a target number. The extractor evaluates that expression for each rotation
  until it matches.
- **Wrappers.** Call sites go through small functions that shift the index
  (`function o(W,n){return i(n- -194,W)}`). The extractor follows them to the decoder.
- **Helper object.** Arithmetic hides behind an object of tiny functions (`{abc:(W,n)=>W+n, …}`), and
  the static param, prefix and suffix are decoded strings in the same object.
- **Checksum.** The checksum is one long expression, `Math.abs(sha1[N % sha1.length].charCodeAt(0) ± c
  + …).toString(16)`. A small parser ([`JsExpression`](../app/Upstream/OnlyFans/JsExpression.php))
  evaluates it symbolically, with the digest as an unknown. That yields how often each digest position
  counts (the indexes, with repeats) and the constant.

Nothing downloaded is executed: the extractor's own parser understands numbers, string literals,
`+ - * / %`, calls of the decoder and the helpers, and nothing else. Anything it does not recognise
makes it fail loudly (`InvalidSigningRules`), never guess:
- a helper whose value goes on after the string (`o(1,"x").split("")…`);
- a digest character that is subtracted;
- an unknown function, or a module far larger than the ~9 KB expected.

**Per release.** Rules are cached per release for 7 days. Until OnlyFans ships a new release, a
reload reads only the page (17 KB). A new release costs the page, `app.js` (780 KB) and the 9 KB
module, about 0.6 s ([16](evidence/16-webapp-rules.txt)). A release whose code cannot be read is
not downloaded again for 10 minutes. A failed download (an HTTP error, a timeout) is not taken for
unreadable code and is retried on the next load.

**What 4 Nov showed** ([15](evidence/15-live-probes.txt), section 3; [16](evidence/16-webapp-rules.txt)):
- the rules read from release `202611031453-fbc506331f` were accepted;
- none of the four public rule feeds had them;
- the rafa-9 feed's rules (an earlier release's) were also accepted;
- two other feeds' rules were rejected.

**A new release on 6 Nov** (`202611061539-e69826e739`). Its signing module is the same program,
but the obfuscator's output differs in three ways:
- in the rotation loop the wrapper comes after `const t=W()` instead of before it;
- the checksum's wrapper takes the key first: `c("D83y",-385)`, not `t(160,"Xv(T")`;
- the helper object is followed by more declarations (`,c=e()(...)`) before the signing function's
  wrapper.

The extractor refused it (`string array rotation not found`), as it should for any shape it does
not know: nothing was guessed. It now accepts either statement order, reads calls with the key
first or second, and cuts the helper object out by matching its brackets. Both releases give their
rules. The 3 Nov release still gives exactly the rules above, and the 6 Nov one gives new ones
(prefix `67795`, suffix `6aedf4bb`, constant −193; [16](evidence/16-webapp-rules.txt)). OnlyFans
accepted them on 7 Nov ([14](evidence/14-live-onlyfans-fetch.txt)).

**When the code cannot be read.** Refreshes keep signing with the last good rules, and OnlyFans may
accept them for a while: on 4 Nov it accepted an earlier release's rules, and on 7 Nov, a day after
the 6 Nov release, it still accepted the 3 Nov rules ([17](evidence/17-previous-release-rules.txt)).
A fresh setup has no last good rules. That is why the default sources end with the rules OnlyFans last accepted, kept in the
repository: [`last-accepted-rules.json`](../resources/onlyfans/last-accepted-rules.json), the rules
of [16](evidence/16-webapp-rules.txt), saved after the live check in
[14](evidence/14-live-onlyfans-fetch.txt). Loading them logs `onlyfans.rules_loaded` as a warning,
with the web app's error in `passed_over`. They are replaced by hand once new rules have been
accepted live.

**Other sources** (`ONLYFANS_RULES_SOURCES`, tried in order) are opt-in: a local JSON file to pin
rules by hand, or a public rule feed. Feeds publish three different shapes. All three are read and
validated strictly (types, index range 0–39, hex app token).

### Why the code is read, not run

Running the signing module (in Node, or a JavaScript engine inside PHP) would survive any change in
the obfuscator's output: the module computes the signature whatever its shape. It is not done here
because it means running code downloaded from a third party on the machines that hold the sessions.
Reading the code as text has the opposite trade-off: nothing downloaded runs, but a new shape needs
the extractor adjusted, as on 6 Nov.

In production I would use both. A throwaway sandbox (a container with no network, no secrets, and
limits on CPU, memory and time) runs each new release's module once, for a fixed path, time and
user id. Its signature must equal the one built from the extracted rules. A mismatch, or a module
the extractor refuses, alerts someone while the old rules still work. Requests are still signed
here, from plain rules; nothing the sandbox produces is sent to OnlyFans.

## Tests without OnlyFans' code

No OnlyFans code is in the repository.
[`SyntheticWebApp`](../tests/Support/SyntheticWebApp.php) builds a page, an `app.js` and an
obfuscated signing module of the same shape from made-up rules. The module has:
- RC4-encrypted strings in the custom base64;
- an array rotated by 0, 1, 5 or 17 positions;
- wrappers that shift indexes, and helpers that mix operators;
- either the 3 Nov or the 6 Nov shape.

[`WebAppRulesExtractorTest`](../tests/Unit/WebAppRulesExtractorTest.php) recovers the rules from it,
and refuses variants it must not guess at.

So that this is not the extractor checked against itself, the synthetic module is a working
program. `tests/Support/synthetic-web-app-in-node.*` runs it in Node through a minimal webpack
runtime and checks that it signs a request exactly as its rules say:

```text
$ docker compose exec -T app php tests/Support/synthetic-web-app-in-node.php | node tests/Support/synthetic-web-app-in-node.mjs
rotation 0: signs as its rules say (13579:dd465749cbd614564bc0444105ef79232112990e:1b3:2468aceb)
rotation 1: signs as its rules say (13579:111ef65939a56eff7b6f19bc50c60229ee9bd9c2:30b:2468aceb)
rotation 17: signs as its rules say (13579:e09f2171ed43df57ba113ec0783bf7c2685a205e:2d1:2468aceb)
rotation 5, later shape: signs as its rules say (13579:4d547f7a8e4237db48d1578bc628df90d8ff585e:28c:2468aceb)
```

The checks against the real code are the live ones: each release read live
([16](evidence/16-webapp-rules.txt)) and its rules accepted by OnlyFans
([15](evidence/15-live-probes.txt), [14](evidence/14-live-onlyfans-fetch.txt)).

## Sharing, reloading and rejecting rules

[`SigningRulesProvider`](../app/Upstream/OnlyFans/SigningRulesProvider.php) keeps the rules per
host in the cache, shared by all workers.

- **Reloads.** After 60 minutes one worker reloads them while the others keep signing with the last
  good copy. If the sources fail, that copy stays in use.
  - A load that finds nothing usable is remembered for 30 s, so jobs fail fast instead of all asking
    again.
  - A job waits at most 2 s for another worker's load. After that it is deferred for 3 s without
    spending an attempt.
  - One load is capped at 12 s, so a job stays inside its 30 s timeout.
- **Kept until replaced.** The cached rules have no expiry of their own: however long the sources
  fail, the last good copy stays in use.
- **A new release.** Every OnlyFans response names the current release (`x-of-rev`). One newer than
  the cached rules' release marks them for reloading at once, once per release: if the page still
  serves the old release for a while (a CDN lagging), the following responses do not reload again.
- **A rejected signature does not prove the rules are wrong**, because the session can be the cause.
  - The rules are marked for reloading, but only if they were loaded before the rejected request
    was sent: requests already in flight that are rejected late do not reload again. The guest
    session is dropped, and the refresh retries from 5 s.
  - What is counted against the rules is the *sessions* rejected with them (per release), kept as
    a Redis set of hashed `x-bc` tokens in a fixed 30-minute window. Two requests in flight on one
    bad session count once.
  - Rules OnlyFans accepted in the last 10 minutes are not counted against at all: then it is the
    sessions that are refused.
  - Rules rejected for two different sessions give way to the next source for the rest of the
    window, if one is configured. The last source with valid rules is never skipped: sending
    nothing would stop every refresh, with no request left to prove the rules good again. Each
    refresh is still bounded by its 3 `signature_rejected` tries.
- `php artisan onlyfans:rules [--reload]` shows the rules in use, their source and release. With
  `--reload` it loads them from the sources first, without dropping the cached copy.

## Sessions

[`SessionStore`](../app/Upstream/OnlyFans/SessionStore.php) keeps one session per account in the
cache, encrypted because cookies are credentials, and shares it between all workers.

- **Creation.** A guest session is created on first use; the first writer wins, so concurrent workers
  share one.
- **Cookies.** Every response's `Set-Cookie` headers are applied in order: OnlyFans deletes and
  re-sets `sess` in one response, without a `Domain`. Each cookie keeps its expiry (`Max-Age` or
  `Expires`; `__cf_bm` lives 30 minutes), and an expired cookie is not sent. Domain and Path are not
  kept: an account's requests all go to one host, and OnlyFans sets its cookies for `/`.
- **Concurrent updates.** Cookie updates apply to the session as it is stored at that moment, under a
  per-account lock, and only if it is still the session the request was sent with. So concurrent
  responses do not undo each other's cookies, and a late response cannot bring back a dropped session.
- **Rejection.** After a rejection or a 401/403, the guest session is dropped, but only if it is still
  the one that was rejected. A late rejection of an old session does not drop its replacement.
- **Logged-in mode.** An account can instead hold a logged-in browser session in its encrypted
  credential: `{"user_id", "cookie", "x_bc", "user_agent"}`. Its user id is then sent and signed, and
  the session follows the cookies OnlyFans sets from then on. It is replaced when the credential
  changes and removed with it. This mode is tested with fakes only.
