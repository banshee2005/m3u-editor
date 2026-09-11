# CI Validation Checklist — m3u-editor & m3u-tv

How to validate a PR locally before pushing, and what the CI gates actually run.
Fork PRs have a GitHub first-time approval gate: workflow runs show
`action_required` until a maintainer clicks "Approve and run" — checks
"skipped" or never starting is that gate, not the branch.

---

## m3u-editor (Laravel/Pest)

### CI pipeline (`.github/workflows/ci.yml`)
| Job | Command (CI) | Notes |
|---|---|---|
| Code Quality | `composer install` + `npm ci` + `vendor/bin/pint --test` | pint.json enables `Pint/laravel_blade`, which shells out to Node — **Node + node_modules must exist before pint** |
| Security Scan | `composer audit`, `npm audit`, `php artisan checkpoint:scan` | |
| Tests | Postgres 16 + Redis services; `npm run build` then `php artisan test --parallel --processes=4 --recreate-databases` | Runs the FULL suite on Postgres |
| Docker Build Validation | `docker build` with `GIT_BRANCH`/`GIT_COMMIT` build args | |

### Local reproduction (scratch container)
```bash
# Scratch container with the repo mounted + php/node/composer
docker run --rm --entrypoint sh -v "<repo>:/app" -w /app composer:2 -c \
  "apk add --no-cache nodejs npm && \
   composer install --no-interaction --prefer-dist --no-progress --ignore-platform-reqs && \
   npm ci --no-audit --no-fund && \
   php vendor/bin/pint --test"
```
Tests need the same env as CI (Postgres test role/db + Redis + a Vite build):
```bash
# On a dev box with a live m3u-editor container:
# 1) Postgres: CREATE ROLE testing LOGIN PASSWORD 'testing' SUPERUSER; CREATE DATABASE m3ue_test OWNER testing;
# 2) .env from .env.example + REVERB_APP_SECRET=123 + APP_KEY; touch database/jobs.sqlite
# 3) Build assets (or copy the deployed container's public/build) — otherwise
#    view-rendering tests 500 with ViteManifestNotFoundException
docker run --rm --network container:<m3u-editor-container> -v "<repo>:/app" -w /app --entrypoint sh \
  -e DB_CONNECTION=pg_test -e DB_HOST=127.0.0.1 -e DB_PORT=5432 \
  -e TEST_DB_DATABASE=m3ue_test -e TEST_DB_USERNAME=testing -e TEST_DB_PASSWORD=testing \
  -e REDIS_HOST=redis -e REDIS_SERVER_PORT=6379 -e REDIS_PASSWORD=<redis-pass> \
  m3u-editor:<image> -c "php artisan test --parallel --processes=4 --recreate-databases"
```
Focused runs: pass the test file paths instead of the full suite.

### Known gotchas
- **Pint**: run with the SAME lockfile the CI resolves (pint version from
  composer.lock). A locally-PASS does not guarantee the CI passes if your
  vendor is stale — always run against the CI-equivalent install.
- **Vite manifest**: view-rendering tests 500 without `public/build/manifest.json`.
- **attempt_count default**: `dvr_recordings.attempt_count` defaults to 1 —
  retry tests must pin it explicitly.
- **Factory randomness**: `Channel::factory()` randomizes `enabled` — tests
  that assert EPG-scoped UI must pin `'enabled' => true`.
- **Queue**: tests of retrying jobs should `Queue::fake()` — the sync driver
  runs delayed dispatches inline and makes attempt-count assertions racy.

---

## m3u-tv (Flutter)

### CI pipeline (`.github/workflows/ci.yml`)
| Gate | Command | Notes |
|---|---|---|
| detect | paths filter (lib/test/pubspec) | gates only run when relevant files change |
| Flutter analyze and test | `flutter pub get`, `dart format --output=none --set-exit-if-changed lib test`, `flutter analyze lib test`, `flutter test` | Flutter **pinned to 3.44.8** |
| desktop build jobs | `flutter build linux/windows --release` | gated on desktop-affecting paths |

### Local reproduction
```bash
cd flutter_client
flutter pub get
dart format --output=none --set-exit-if-changed lib test   # exit 1 = unformatted
flutter analyze lib test
flutter test
```
Check the local Flutter version — a NEWER SDK flags lints/tests the pinned
3.44.8 does not (and vice versa). `flutter --version` should match 3.44.8 for
a faithful pre-PR check.

### Known gotchas
- **Format gate**: after any merge of the target branch, run `dart format lib
  test` — the CI's `--set-exit-if-changed` fails on unformatted files.
- **Environment-sensitive tests** (fail on newer SDKs but pass on the pinned
  one): `release_matrix_documentation_test.dart`, `tvos_port_drift_test.dart`,
  `transcoding_contract_test.dart`, some `push_token_lifecycle_test.dart`.
  Verify against the BASE branch (`git worktree add <dir> <base>` + run the
  same files) before chasing them — if they fail on the base too, they are
  environment noise, not PR regressions.
- **Analyzer**: use `mounted` (State) rather than `context.mounted` when the
  lint complains about `State.context` across async gaps.

---

## Best practices before opening / updating a PR

1. **Rebase onto the target branch (dev)** — resolve conflicts before pushing;
   a 0-behind branch merges cleanly and keeps the PR diff reviewable.
2. **Run the exact CI commands locally first** (tables above), not a
   convenience subset.
3. **Use a base worktree to isolate regressions**: `git worktree add <dir>
   <merge-base>` then run the failing files there. Pass/fail on the base tells
   you instantly whether a failure is your change or environment.
4. **Make test data deterministic**: pin `enabled`, timestamps with generous
   margins (`upcoming(60)` not `upcoming(2)`), uuids long enough for
   substring truncation in debug logs, and explicit `attempt_count`.
5. **Format before every commit**: `dart format lib test` (m3u-tv),
   `vendor/bin/pint` (m3u-editor) — run pint against the CI-equivalent
   install (Node + node_modules + same lockfile).
6. **Commit logically** (feature/settings/fix splits) so reviewers and CI logs
   map to behavior, not noise.
7. **After pushing, watch the CI run** (GitHub Actions → your PR). Fork PRs
   need an approval before the run starts — if it stays `action_required`,
   ask the maintainers to approve it; "checks skipped" is that gate, not your
   branch.
8. **If CI fails**: download the failing job's log (Actions → job → download
   log), find the `FAIL`/`⨯` lines, reproduce locally, fix, re-push. Repeat
   until every job is green — the reviewer only sees the final state.
9. **Keep the deployed system in sync** while iterating: the live container
   must be rebuilt from the same commits being pushed (opcache has
   `validate_timestamps=Off` — hot-patched PHP files never load in FPM).