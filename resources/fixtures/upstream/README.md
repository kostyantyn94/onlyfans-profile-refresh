# Upstream response fixtures

Used by the tests (`tests/`). The local fake upstream (`fake-upstream/server.php`) builds its answers in the same three shapes (old, new, Fans API) from its scenario instead of reading these files.

| File | What it is | Status |
|---|---|---|
| `fansapi-madison420ivy.json` | Real Fans API `GET /api/profiles/madison420ivy` response, captured 2026-11-01 (cache hit, 0 credits). Likes are `data.favoritedCount`. | 200 |
| `old-format.json` | Incident, before the change: `likes` at the top level, revision 10. | 200 |
| `new-format.json` | Incident, after the change: `profile.likes`, revision 11. | 200 |
| `missing-likes.json` | No likes anywhere: invalid. | 200 |
| `zero-likes.json` | Explicit 0: valid. | 200 |
| `negative-likes.json` | Negative: invalid. | 200 |
| `non-numeric-likes.json` | `"121k"`: invalid. | 200 |
| `truncated.json` | Body cut off mid-JSON: invalid. | 200 |
| `rate-limited.json` | Body of a 429, sent without a Retry-After header. | 429 |
| `server-error-empty-body.txt` | Empty body of a 500. | 500 |
