-- Phase 3.1-07 — Cross-context site_id symmetry — backfill stranded rows
--
-- PRECONDITIONS:
--   1. Run on each affected site (.lv, .lt, .no) BEFORE deploying v1.1.1.
--   2. Safe pre-deploy: column-fence is sole consumer of site_id + was
--      already missing affected rows (the bug). Backfill makes rows
--      visible to frontend reader moment v1.1.1 lands — operator does
--      NOT re-flip orders.
--   3. Idempotent: re-running post-deploy = no-op (no more NULL+JOIN
--      matches). Safe to leave in operator playbook.
--   4. NULL-only repair: WHERE restricts to el.site_id IS NULL AND
--      o.site_id IS NOT NULL — never overwrites deliberately-NULL
--      single-site row.
--
-- PRODUCTION INCIDENT (2026-05-14, new.nailscosmetics.lv):
--   orders 29802 + 29803 — Pixel never rendered on /lv/checkout/{slug}.
--   CAPI rows had site_id=NULL (writer admin context); reader queried
--   where site_id=1 (frontend context); gate failed.
--
-- @see plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-07-multi-site-site-id-symmetry/BRIEF.md REFAC-14
UPDATE logingrupa_metapixel_event_log el
JOIN lovata_orders_shopaholic_orders o
   ON o.id = el.subject_id
  AND el.subject_type = 'Lovata\\OrdersShopaholic\\Models\\Order'
SET el.site_id = o.site_id
WHERE el.site_id IS NULL
  AND o.site_id IS NOT NULL;
