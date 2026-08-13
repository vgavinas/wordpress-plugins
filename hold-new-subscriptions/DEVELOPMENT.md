# Hold New Subscriptions Until Order Completed — Development Notes

## Plugin Info
- **Slug (working):** hold-new-subscriptions
- **Version:** 1.2.1
- **Author:** Vitalijus Gavinas, for Pro Technologies Limited
- **Monetization:** none yet — Freemius is intentionally NOT integrated. Code
  correctness and WordPress.org readiness come first; monetization is a
  separate future step.
- **Live sites this plugin runs on:** russiantvonline.co.uk, arlekino.live,
  vipmedia.tv
- **Requires:** WooCommerce, WooCommerce Subscriptions

## What it does
WooCommerce Subscriptions activates a new subscription immediately at
checkout. These stores need a manual review/fulfilment step first, so the
plugin holds the new subscription (On-hold or Pending) until its parent order
reaches a chosen status (default: Completed), then activates it and can email
the customer at both steps.

## 1.2.1 — HPOS compatibility fixes (this pass)
The plugin as originally written had **no** HPOS compatibility declaration
and one meta-storage bug that broke silently under HPOS:

1. **Duplicate-activation guard used `add_post_meta()`.** The
   `woocommerce_order_status_changed` handler guarded against double
   activation with:
   ```php
   if ( ! add_post_meta( $sub->get_id(), '_hns_activated', '1', true ) ) {
       continue;
   }
   ```
   `add_post_meta()` reads/writes `wp_postmeta` directly, keyed by
   `$sub->get_id()`. When WooCommerce HPOS (custom order tables) is enabled,
   subscription meta lives in `wp_wc_orders_meta` instead — so this guard
   silently stopped working: it always returned true (key never existed in
   `wp_postmeta`), meaning **the duplicate-activation guard was dead code**
   on any HPOS store. Worse, the *cleanup* handler
   (`woocommerce_subscription_status_changed`) already correctly used the
   subscription's CRUD API (`get_meta()` / `delete_meta_data()` /
   `save_meta_data()`), so the set and clear operations were targeting two
   completely different storage backends under HPOS — the guard could
   neither block a duplicate activation nor ever be cleared.

   **Fix:** the guard now uses the same CRUD meta API as the cleanup handler
   (`get_meta()` to check, `update_meta_data()` + `save_meta_data()` to set),
   so both operations always target the same storage regardless of HPOS.

2. **No `FeaturesUtil::declare_compatibility( 'custom_order_tables', ... )`
   declaration at all.** Added via `before_woocommerce_init`, matching the
   pattern already used in Order Note Templates and Order Tags & Labels.

3. **`uninstall.php` used `delete_post_meta_by_key()`**, which only touches
   `wp_postmeta` — on an HPOS store this left `_hns_activated` (and
   `_hns_hold_target`, which the original uninstall script didn't clean up
   at all) behind in `wp_wc_orders_meta`. Fixed to check
   `OrderUtil::custom_orders_table_usage_is_enabled()` and delete from the
   correct table.

**Lesson for future plugins:** whenever a plugin sets a meta flag in one
place and reads/clears it in another, grep for every `*_post_meta(` call
against an order/subscription ID and confirm all of them agree on CRUD vs.
direct-table access. A guard using the wrong API doesn't throw — it just
quietly stops doing its job, and worse, gives a false sense that duplicate
protection exists.

## Other fixes in 1.2.1
- Removed a `Plugin URI` header pointing at
  `https://wordpress.org/plugins/hold-new-subscriptions/` — the plugin was
  never actually submitted there; the link was false.
- Added `Requires Plugins: woocommerce` header (WP 6.5+ dependency
  declaration) alongside the existing manual `hns_dependencies_ok()` runtime
  check.

## Lessons applied from Order Note Templates / Order Tags & Labels
Checked against every lesson from the previous plugin's own DEVELOPMENT.md
before calling this done:
- `dbDelta()` + `CREATE TABLE IF NOT EXISTS` — not applicable, this plugin
  creates no custom database tables.
- `woocommerce_new_order_note_data` filter — not used anywhere in this
  plugin.
- `phpcs:ignore` placement — the two suppressions added in `uninstall.php`
  (for the direct `$wpdb->delete()` calls against `wc_orders_meta`) are each
  on their own line, not trailing a line of code.
- `$wpdb->insert()` / `->update()` silent-failure risk — the only direct
  `$wpdb` calls added are `->delete()` calls in uninstall cleanup, which is
  fire-and-forget teardown code with no admin-facing success/failure notice,
  so there's no "green notice on silent failure" risk to guard against here.
- Plugin Check must run against the actual **distributable build**, not just
  the dev source, and (per the earlier correction on this point) against a
  **real WordPress + WooCommerce + WooCommerce Subscriptions install**, not
  a static/cached zip inspection. This plugin has no premium/free split (no
  `__premium_only` files, no Freemius yet), so the distributable and the dev
  source are currently identical — but the live-site Plugin Check run itself
  still needs to happen on an actual test site, since this sandbox has no
  way to install WooCommerce Subscriptions (a paid extension) or run the
  Plugin Check plugin itself.
- Not zipping via macOS Finder — the build zip for this release was created
  with `zip -r -X ... -x "*.DS_Store" -x "__MACOSX*"` from the command line;
  verified with `unzip -l` that no `__MACOSX/` or `.DS_Store` entries are
  present.

## Still open before this is publish-ready
- **Plugin Check has not actually been run yet.** It needs a real WordPress
  install with WooCommerce + WooCommerce Subscriptions active — do this the
  same way it was done for Order Note Templates and Order Tags & Labels
  (install the built zip on a real test site, run Plugin Check there, fix
  anything it flags, re-zip).
- Freemius/monetization integration is deliberately not part of this pass.
- Text Domain currently stays `hold-new-subscriptions` — if/when this is
  submitted to WordPress.org and WordPress.org assigns a different slug
  (as happened with both other plugins), the Text Domain will need to be
  realigned to match, following the same convention documented in the other
  two plugins' DEVELOPMENT.md files (file/folder name and any Freemius slug
  stay on the original identifier forever; only `Plugin Name`, the readme
  title, and `Text Domain` pick up the WordPress.org-assigned slug).

## Changelog (dev notes, not the plugin readme)
### 1.2.1
- Fixed HPOS-breaking `add_post_meta()` duplicate-activation guard (see
  above).
- Added HPOS `declare_compatibility()`.
- Fixed `uninstall.php` to clean up meta correctly on both legacy and HPOS
  storage, and to also remove `_hns_hold_target` (previously missed).
- Removed false `Plugin URI` header.
- Rewrote `readme.txt` for WordPress.org (Installation, FAQ, proper
  Changelog/Upgrade Notice sections, Contributors, tag count).
