---
status: resolved
resolved_on: 2026-05-27
resolved_by: |
  Operational fix — stale OPcache (PHP 8.4 FPM workers started May 14, predating commit 6b2cd09 that wired App::singleton(HostIndexResolver::class)). On-disk code was correct; CLI bootstrap resolved cleanly. Operator ran `sudo systemctl reload php8.4-fpm` per parent CLAUDE.md OPcache flush protocol; Settings save then succeeded with all fields persisted (pixel_id 2291486191076331, CAPI token, Test Events Code TEST58466, paid_status, default_currency EUR).
verified_by: 2026-05-22 operator save success on http://new.nailscosmetics.lv/back/system/settings/update/logingrupa/metapixel/settings + 2026-05-27 cutover UAT items 4+5 PASS (Multisite per-site pixel_id + Cookie kill switch + TrustedHosts allowlist all behave correctly).
trigger: "when I try to save Unresolvable dependency resolving [Parameter #0 [ <required> string $sPslPath ]] in class Logingrupa\\Metapixel\\Classes\\Helper\\HostIndexResolver — at http://new.nailscosmetics.lv/back/system/settings/update/logingrupa/metapixel/settings#primarytab-pixel-capi"
created: 2026-05-22T21:05:00Z
updated: 2026-05-27T00:00:00Z
---

## Current Focus

hypothesis: Backend FPM workers serve a stale compiled Plugin.php (compiled before the May 21 binding edit) — Plugin::register() runs the old code path that lacks the HostIndexResolver singleton binding; Settings::beforeSave() calls App::make(HostIndexResolver::class), Laravel autowires, sees primitive string $sPslPath, throws BindingResolutionException.
test: Compared CLI bootstrap (resolves OK) vs FPM stack trace from storage/logs/system.log at 20:49:47 today (fails).
expecting: confirmed
next_action: Operator must reload php8.4-fpm to flush OPcache (sudo systemctl reload php8.4-fpm) — per parent CLAUDE.md PHP edit protocol.

## Symptoms

expected: Settings page POST to /back/system/settings/update/logingrupa/metapixel/settings saves successfully.
actual: BindingResolutionException "Unresolvable dependency resolving [Parameter #0 [ <required> string $sPslPath ]] in class Logingrupa\Metapixel\Classes\Helper\HostIndexResolver".
errors: BindingResolutionException — Container.php:1425 unresolvablePrimitive() — triggered from Settings.php:239 (App::make in partitionHosts).
reproduction: Backend → System → Settings → Meta Pixel → tab pixel-capi → Save.
started: After Phase 4 wiring (commit 6b2cd09 — feat(04-02): HostIndexResolver + Plugin singleton wiring). Surfaced now during Phase 5 UAT because the binding code is correct on disk but the FPM workers are stale.

## Eliminated

- hypothesis: HostIndexResolver singleton binding missing from Plugin::register()
  evidence: Plugin.php lines 63-68 contain the binding — `$this->app->singleton(HostIndexResolver::class, fn () => new HostIndexResolver(base_path('plugins/logingrupa/metapixel/resources/data/public_suffix_list.dat')))`. Committed as 6b2cd09.
  timestamp: 2026-05-22T21:00Z

- hypothesis: PSL data file missing on disk
  evidence: `resources/data/public_suffix_list.dat` exists, 332KB, mtime May 20.
  timestamp: 2026-05-22T21:00Z

- hypothesis: Plugin disabled (PluginBase->disabled = true skips register())
  evidence: storage/cms/disabled.php is empty arrays. `php artisan plugin:list` shows plugin as Enabled=Yes. No `disabled = true` assignment anywhere in plugin source.
  timestamp: 2026-05-22T21:00Z

- hypothesis: Auto-resolution failure due to broken classmap
  evidence: storage/framework/classes.php (regenerated May 22) contains HostIndexResolver mapped to correct path.
  timestamp: 2026-05-22T21:00Z

- hypothesis: Stale Lovata.Toolbox cache prevents Plugin::register() from running
  evidence: Toolbox 2.3.0 loaded per plugin:list. CLI bootstrap successfully resolves HostIndexResolver from container, proving Plugin::register() ran in that process. The bug is FPM-process-specific, not Toolbox-related.
  timestamp: 2026-05-22T21:05Z

## Evidence

- timestamp: 2026-05-22T20:49:47Z
  checked: storage/logs/system.log
  found: Stack trace — Container.php:1425 unresolvablePrimitive() ← Settings.php:239 App::make(HostIndexResolver::class) ← Settings.php:174 partitionHosts ← Settings.php:158 beforeSaveTrustedHosts ← Settings.php (beforeSave) ← HasEvents.php:42 (October model lifecycle).
  implication: App::make falls back to autowiring → reflective construction of HostIndexResolver → primitive string param has no default → throw. Proves the singleton binding is not active in the request's container.

- timestamp: 2026-05-22T21:00Z
  checked: Plugin.php register() (lines 59-71)
  found: Singleton binding for HostIndexResolver is correctly present with PSL path closure.
  implication: The disk source code is correct. The failure is execution-environment-specific, not source-code-specific.

- timestamp: 2026-05-22T21:00Z
  checked: FPM worker process list (ps -ef | grep php-fpm)
  found: PHP 8.4 FPM workers PIDs 1519388 / 1519389 / 1519454 started **May 14** at the master process PID 1047389. Plugin.php was edited May 21 at 10:38. So three FPM workers compiled Plugin.php BEFORE the binding existed. Three additional workers (2455770/71/73) started May 21 — likely after a partial reload, but coexist with the stale May-14 workers.
  implication: When a backend Settings POST routes to a stale May-14 worker, that worker's OPcached Plugin.php lacks the binding. Same backend URL hitting a May-21 worker would succeed. Intermittent failure pattern matches.

- timestamp: 2026-05-22T21:05Z
  checked: CLI bootstrap (`php -r` with full app boot + `app(HostIndexResolver::class)`)
  found: Returns OK — `Logingrupa\Metapixel\Classes\Helper\HostIndexResolver`.
  implication: The CLI process compiled Plugin.php fresh (opcache.enable_cli=Off, so no OPcache for CLI). Binding works in fresh process. FPM workers with stale OPcache are the broken environment.

- timestamp: 2026-05-22T21:05Z
  checked: OPcache INI (opcache.enable=On, opcache.validate_timestamps=On, opcache.revalidate_freq=2)
  found: Timestamp revalidation is enabled in theory, but the May-14 workers cached Plugin.php seven days before the edit. revalidate_freq=2 means re-stat at most every 2s; if no request touched Plugin.php in the window between the edit (May 21 10:38) and FPM-worker restarts on May 21, OR if the stale workers never recompiled because their existing bytecode resolved before reaching the new code branch — OPcache holds the pre-edit bytecode indefinitely.
  implication: A `sudo systemctl reload php8.4-fpm` (graceful restart of pool workers) flushes worker memory and recompiles Plugin.php from current disk content. This is mandated by parent CLAUDE.md whenever PHP files are edited on production.

## Resolution

root_cause: PHP 8.4 FPM workers (PIDs 1519388/1519389/1519454, started May 14) are running OPcache-compiled bytecode of `plugins/logingrupa/metapixel/Plugin.php` from BEFORE the Phase-4 edit on May 21 that added the `HostIndexResolver` singleton binding. When the Settings save POST routes to one of these stale workers, `Plugin::register()` executes the pre-edit code path that omits the binding. `Settings::beforeSave()` → `partitionHosts()` → `App::make(HostIndexResolver::class)` then falls back to Laravel's autowiring, which cannot resolve the primitive `string $sPslPath` constructor parameter and throws `BindingResolutionException`. Source code on disk is correct; CLI bootstrap (no OPcache) confirms the binding resolves cleanly.

fix: `sudo systemctl reload php8.4-fpm` on the host. This gracefully recycles all PHP 8.4 FPM pool workers; new workers compile `Plugin.php` from current disk content and the singleton binding becomes active for every subsequent request.

verification: After the FPM reload, retry the backend Settings save on the pixel-capi tab. The exception should not recur. Confirmable by tailing `storage/logs/system.log` and checking that no new `BindingResolutionException` rows appear for `HostIndexResolver`.

files_changed: [] (no source code changes required — the bug is operational, not source-level)
