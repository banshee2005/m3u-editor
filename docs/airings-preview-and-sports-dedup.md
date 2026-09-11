# Airings Preview & Sports Dedup — Key Takeaways

Session notes for PR #1490 (matched airings preview) and PR #1503 (DVR capacity).

## Airings preview (PR #1490)

- **Review blocker fixed**: `getEpisodeRecordingStatus()` must include `Purged` in
  the duplicate-check statuses. Retention deletes the recording FILE but keeps
  the DB row as a permanent "already recorded" sentinel — dropping `Purged`
  re-scheduled the same scripted episode after cleanup.
- **Edit preview fixed**: the preview previously returned early for existing
  rules and rendered the SAVED values. It now builds a temp rule from the
  form's CURRENT state (`series_title`/`channel_id`/`series_mode` via `onBlur`),
  spreading the record's attributes as the base for fields outside the form.
  Works for new and edited rules; nothing is persisted.
- **Preview mirrors the scheduler**: the dry run (`matchSeriesRuleDryRun`) runs
  the exact scheduling loop without creating rows; skip reasons are
  `already_scheduled` (in-window duplicate or pending recording) vs
  `already_recorded` (completed/purged episode).
- **Channel selector**: deduplicates by `title` (IPTV variants) and falls back
  to `name` — a null `title` previously crashed Filament's `Select` render
  (`isOptionDisabled` got a null label).

## Sports dedup window (no season/episode data)

The title-only dedup was a false dichotomy:

- Date-keyed identity → next-day replays double-recorded.
- Purge-forget → re-matches later in the season got missed.
- **Solution: title + dedup window.** A same-title airing within
  `sports_dedup_days` of an existing recording (any status incl. Purged) is a
  replay → skipped. Beyond the window → new event → recorded.
- Default window: **2 days**; per-rule override field
  (`sports_dedup_days` on the rule) wins over the per-DVR-setting default;
  **0 = record every same-title airing** (for providers using generic titles).
- **Playoff games need no special handling**: dedup keys on the PROGRAMME's
  title, and distinct titles ("Bruins at Kings - Game 2") never collide —
  every distinct game records; only identical titles within the window dedup.
- Scripted episodes (S/E present) keep exact identity + Purged blocking.

## CI checks (lint / tests / docker build)

- **Pint**: the repo's `pint.json` enables `Pint/laravel_blade`, which shells
  out to Node — run `npm ci` before `vendor/bin/pint --test` (as CI does), or
  Pint fails with "requires Node.js".
- **Pest on Postgres**: replicate CI with
  `DB_CONNECTION=pg_test` + `TEST_DB_*` env, `REDIS_PASSWORD` (Redis auth is
  required here), `REVERB_APP_SECRET` in `.env`, and `touch database/jobs.sqlite`
  (the jobs connection is SQLite).
- **Test flake lesson**: `Channel::factory()` randomizes `enabled` — a preview
  test that depended on the channel's EPG scope intermittently returned no
  airings. Always pin `'enabled' => true` in tests that assert EPG-driven UI.
- **Docker build validation**: `docker build --build-arg GIT_BRANCH=.. --build-arg GIT_COMMIT=..`
  reproduces the CI `build-check` job (image `m3u-editor:pr-check`).

## Deployment env (this dev box)

- The live container `m3u-editor2` runs image `m3u-editor:poolmerge-dev`, built
  with `docker build -t m3u-editor:poolmerge-dev .` from the working checkout
  and deployed via `C:\m3u-editor\compose.dev.yaml` (`up -d --force-recreate
  m3u-editor`). The `docker-compose.dev.yml` in the source tree is a DIFFERENT
  project (do not use it for the live deployment).
- opcache has `validate_timestamps=Off`: hot-patched files never load in FPM;
  only a full image rebuild + container recreate takes effect. Horizon queue
  workers need `php artisan horizon:terminate`.