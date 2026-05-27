---
created: 2026-05-27T10:22:35.986Z
title: Enable optional queue for CAPI server events
area: general
files:
  - classes/queue/SendCapiEvent.php:38-77
  - components/PixelHead.php:60-130
  - classes/event/adapter/shopaholic/CartPositionWatcher.php:30-88
  - classes/event/adapter/shopaholic/OrderStatusWatcher.php:30-70
  - models/settings/fields.yaml:1-60
  - Plugin.php:108-180
  - .env (QUEUE_CONNECTION key)
---

## Problem

v2.0 plugin runs with `QUEUE_CONNECTION=sync` so every CAPI dispatch executes
inside the originating HTTP request:

  PixelHead.onRun       -> SendCapiEvent::dispatch -> handle() -> MetaClient
    -> Guzzle POST https://graph.facebook.com/v23.0/<pixel>/events
    -> Default timeout 5 seconds, typical latency 50-200ms

Every page-load now waits for the Meta API round-trip before the response
flushes. Multiply by the four trigger points (PixelHead base PageView,
EventPixel server-confirmed pixel, CartPositionWatcher AddToCart, and
OrderStatusWatcher Purchase) and a slow Meta upstream cascades directly into
user-visible page-load latency.

Operator constraint: site slowness reduces conversion. Even +100ms median TTFB
measurably reduces e-commerce buy-through (Cloudflare published benchmarks +
Google Web Vitals research). We cannot ship a plugin that adds an unconditional
5-second Meta tail-latency to every PageView.

Reference precedent inside the same OctoberCMS ecosystem: Lovata.Shopaholic XML
import ships a Settings tab with two fields that toggle queue dispatch for the
slow per-row import work:

  <input ... id="Form-field-Settings-import_queue_on">
    <label>Use queue when processing import items</label>

  <input ... id="Form-field-Settings-import_queue_name">
    <label>The name of the queue for processing import items</label>

The pattern: a single switch + a named queue field. When the switch is off,
imports run inline (the v1.x default); when on, each row dispatches as a
Laravel queue job onto the named queue, which a `php artisan queue:work
<name>` worker drains in the background. Plugin docs nudge the operator to
configure Forge's queue daemon for the named queue.

We need the same affordance for CAPI server events. SendCapiEvent already
implements `Illuminate\Contracts\Queue\ShouldQueue` and uses `Dispatchable +
InteractsWithQueue + Queueable + SerializesModels` -- so the dispatch is
already queue-shaped. The remaining work is:

  1. Settings UI: add a `use_queue` (switch, default off) field + an
     optional `queue_name` (text) field on the Hosts & Cookies tab (or a new
     Performance tab), localised in lang.php for lv/en/no/lt.
  2. SendCapiEvent shape: read Settings.use_queue + Settings.queue_name at
     dispatch time. If `use_queue=false`, force the job onto the `sync`
     connection regardless of the .env QUEUE_CONNECTION (use
     `SendCapiEvent::dispatch(...)->onConnection('sync')`). If
     `use_queue=true`, route through the configured queue connection +
     optional named queue (`onQueue($sQueueName)`).
  3. Documentation (plan 05-09 README): document the toggle, the implied
     `php artisan queue:work` requirement, and the trade-off (slower per-
     request CAPI write vs. background drain with potential drift between
     browser event_time and server event_time).
  4. Failed-event flow: queued failures already land in
     `logingrupa_metapixel_failed_events` via writeFailedEvent. Confirm
     transient `MetaApiTransientException` retries still work under the
     async path (Laravel queue native retry, `$tries = 3` already set).
  5. Test coverage: extend `tests/Feature/Queue/SendCapiEvent*Test.php`
     with both modes -- assert `dispatch()->onConnection('sync')` runs
     inline AND assert `dispatch()->onQueue($name)` pushes onto the
     configured queue when Settings has `use_queue=true`. Use
     `Bus::fake()` + `Bus::assertDispatched` with the queue / connection
     predicate.

Concern surfaced from current architecture: PixelHead.collectRequestUserData
captures the user agent + IP + fbp/fbc cookies inside the in-request component
context. Once the dispatch moves to a queue worker (no request context), the
adapter rehydrate must REUSE the captured user_data from the serialised job
payload rather than re-resolving from Request::ip() / Cookie::get('_fbp').
SendCapiEvent.handle already reads $this->arPayload which carries the snapshot
-- this is the right design and continues to work under the async path,
provided we keep capturing at the boundary (components + watchers) and never
late-resolve at handle() time. Worth a dedicated test to lock the invariant.

## Solution

Phase 4 already shipped Multisite settings + per-site credentials. Plumbing
this toggle is straightforward additive work:

1. Settings migration v1.0.5 (or v1.1.0 if we want to bundle this into the
   first post-v2.0.0 release):
     -- add `use_queue` BOOLEAN DEFAULT 0 column (or store as part of the
        existing JSON settings blob via the standard October pattern -- check
        how `ensure_fbp_fbc_server_side` is stored; mirror that exactly).
     -- add `queue_name` VARCHAR(64) NULL column or JSON blob entry, same
        storage pattern as the existing string fields.

2. fields.yaml additions:

   ```yaml
   use_queue:
       tab: logingrupa.metapixel::lang.tab.performance
       label: logingrupa.metapixel::lang.field.use_queue_label
       commentAbove: logingrupa.metapixel::lang.field.use_queue_comment
       type: switch
       default: false
   queue_name:
       tab: logingrupa.metapixel::lang.tab.performance
       label: logingrupa.metapixel::lang.field.queue_name_label
       commentAbove: logingrupa.metapixel::lang.field.queue_name_comment
       type: text
       span: full
       trigger:
           action: show
           field: use_queue
           condition: checked
   ```

3. Lang keys in `lang/{lv,en,no,lt}/lang.php`:
     -- `tab.performance` = "Performance" / "Veiktspeja" etc.
     -- `field.use_queue_label` = "Use background queue for Meta CAPI"
     -- `field.use_queue_comment` -> explain the requirement to run
        `php artisan queue:work` and the latency vs. responsiveness trade.
     -- `field.queue_name_label` = "Queue name"
     -- `field.queue_name_comment` -> "Defaults to the Laravel default queue.
        Set a dedicated name (e.g. metapixel) to isolate the CAPI worker."

4. SendCapiEvent dispatch routing:

   ```php
   public static function dispatchForCurrentSettings(
       string $sEventName,
       array $arPayload,
       object $obSubject,
       string $sAdapterClass,
   ): void {
       $bUseQueue = (bool) Settings::get('use_queue', false);
       $mQueueName = Settings::get('queue_name', '');
       $sQueueName = is_string($mQueueName) && $mQueueName !== ''
           ? $mQueueName
           : null;

       $obPendingDispatch = self::dispatch($sEventName, $arPayload, $obSubject, $sAdapterClass);
       if (!$bUseQueue) {
           $obPendingDispatch->onConnection('sync');

           return;
       }
       if ($sQueueName !== null) {
           $obPendingDispatch->onQueue($sQueueName);
       }
   }
   ```

   Then change every existing `SendCapiEvent::dispatch(...)` call site to
   `SendCapiEvent::dispatchForCurrentSettings(...)`. Locations:
     - components/PixelHead.php:dispatchBasePageViewCapi
     - components/PixelHead.php:dispatchCapiMirror
     - classes/event/adapter/shopaholic/CartPositionWatcher.php:dispatchAddToCart
     - classes/event/adapter/shopaholic/OrderStatusWatcher.php:handle (Purchase dispatch)
     - controllers/FailedEvents.php:replayOne (replay path -- consider whether
       replay should also honour the toggle or stay synchronous so the operator
       gets immediate Flash::success/error feedback on the admin click).

5. Forge / production note (README + CHANGELOG): when use_queue is enabled, the
   operator MUST set up the queue worker as a Forge daemon:

     php /home/forge/<site>/artisan queue:work --queue=<name> --sleep=3 --tries=3

   And ensure `QUEUE_CONNECTION` in .env is set to `database` or `redis`. With
   the toggle off, the .env value is ignored (we override onConnection('sync')
   explicitly so .env mistakes do not silently queue events that nobody is
   draining).

6. Threat-model addition (gsd-secure-phase rerun on the implementing phase):
     T-XX-X-01 Information Disclosure -- queued payload is serialised to the
       jobs table including user_data (em, ph, fbp, fbc, client_ip_address,
       client_user_agent). Encrypt the queue connection or restrict DB read
       access. Already mitigated by the Settings-level pixel_id/capi_token
       being out-of-band, but the per-event PII matters too.
     T-XX-X-02 Tampering -- queue worker crash mid-handle could double-
       dispatch. SendCapiEvent already has the EventLogWriter race-fence
       UNIQUE on (subject_type, subject_id, event_name, channel, site_id) so
       this is already mitigated for Purchase/AddToCart; PageView uses uuid-
       in-action-key so each retry has its own subject_id and would
       legitimately re-fire. Acceptable.

Estimate: ~1 day work (migration + 2 fields.yaml entries + lang keys for 4
locales + SendCapiEvent.dispatchForCurrentSettings helper + 5 call-site
updates + 2 new test cases + README/CHANGELOG paragraphs).

Defer to: post-v2.0.0 marketplace launch is fine. The current sync mode is the
v2.0.0 ship-state. Add this in v2.1 alongside the deferred extension hooks
(adapter.resolve, value.resolve, user_data.resolve, pixel.before_render,
settings.lookup) that Phase 2 ROADMAP also flagged for v2.1.
