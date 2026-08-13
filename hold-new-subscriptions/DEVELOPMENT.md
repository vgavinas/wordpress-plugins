# Hold New Subscriptions Until Order Completed — Development Notes

## Plugin Info
- **Slug (working):** hold-new-subscriptions
- **Version:** 1.3.1
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

## 1.3.0 — Pro architecture + first four Pro modules

Freemius still isn't connected (see "Monetization" above — unchanged). This
pass adds the *code* for a Pro tier so it exists, is reviewable, and is
architecturally ready, while staying completely inert on every current
install:

```php
function hns_is_pro() {
    return (bool) apply_filters( 'hns_is_pro', false );
}
```

`hns_load_pro_modules()` (hooked at `plugins_loaded` priority 6, right after
the free `hns_boot()` at priority 5) only `require_once`s files under
`includes/pro/class-hns-*__premium_only.php` when `hns_is_pro()` is true.
Since nothing currently makes it true, **none of the Pro code below runs on
any real install today.** To exercise it locally: `add_filter( 'hns_is_pro',
'__return_true' );` in a must-use plugin or `wp-config.php`. When Freemius is
eventually wired up, `hns_is_pro()` becomes the only place that changes — it
starts checking the Freemius license instead of the filter default.

The `__premium_only` naming is deliberate and matches Order Note Templates /
Order Tags & Labels: whenever this plugin eventually goes through Freemius
and/or WordPress.org SVN, these four files are excluded from the free
build/free SVN copy exactly the same way those two plugins' premium modules
already are. **Nothing needs to change about this plugin's structure when
that day comes** — it was built with that split from the start, unlike ONT
and OTL where the split was retrofitted (and where the retrofit briefly leaked
Pro code into the public free WordPress.org SVN once — see OTL's own
DEVELOPMENT.md). The `languages/*.po`/`.pot` files were **not** updated with
strings from the Pro modules, for the same reason — those strings belong to
the eventual Pro-only build's own translation files, not the free one.

### Free-file changes needed to support this (all behaviour-preserving)

Three extension points were added to the free `hold-new-subscriptions.php`
so Pro code never has to duplicate or fork the core hold/activate logic:

- **`hns_activate_subscription( $sub, $order, $reason )`** — the
  guard+status-change+note+email+log sequence that used to live only inline
  inside the `woocommerce_order_status_changed` handler is now a standalone
  function. That handler calls it, and so does the new Pro "send info"
  action, and the new Pro escalation timer's auto-activate option. One
  guard, one place it can go wrong, not three.
- **`apply_filters( 'hns_subscription_options', $opts, $sub, $order )`** —
  applied at every point the free code reads `initial_status` /
  `activate_on_statuses` for a specific subscription (checkout, hold
  application, order-status-changed activation). With no filter registered
  (i.e. every install today) this returns `$opts` unchanged. The Pro
  product-rules module hooks it to override those two keys per subscription
  product. Note: the order-status-changed handler's cheap early-exit (skip
  entirely if `$new_status` isn't in the globally configured list) is now
  gated behind `! hns_is_pro()`, since a Pro per-product rule can make a
  status trigger activation that the global settings wouldn't — free
  installs keep the original fast path unchanged.
- **`do_action( 'hns_subscription_held', $sub, $order, $target )`** and
  **`do_action( 'hns_subscription_activated', $sub, $order, $reason )`** —
  fired from the free hold/activate code paths (including from inside
  `hns_activate_subscription()`, so every activation path fires it exactly
  once). Consumed by the Pro escalation timer (to timestamp when a hold
  started) and the Pro notifications module (to fire a webhook). Free
  installs have nothing listening, so these are no-ops.
- One `do_action( 'hns_after_settings_page', $opts )` call was added to the
  end of `HNS_Admin::render_settings_page()`, right after the free
  settings `</form>`. Every Pro module renders its own separate
  `<form>`/settings group here — deliberately kept as its own WordPress
  Settings API group and option (`hns_pro_send_info`, `hns_pro_product_rules`,
  `hns_pro_escalation`, `hns_pro_notifications`) rather than folding Pro
  fields into the free `hns_options` array, so `HNS_Admin::sanitize()` never
  needs to know Pro-only keys exist.

None of this changes behaviour for a free install: no new filter/action has a
listener unless Pro modules are loaded, and `hns_activate_subscription()` is
a straight extraction of the exact code that was already inline.

### The four Pro modules

**`class-hns-send-info__premium_only.php`** — "Send subscription info &
activate". Adds a meta box to the order and subscription edit screens
(classic and HPOS screen IDs both registered). One click: inserts a
configured text as a **customer-facing order note** on the subscription
(`$sub->add_order_note( $text, 1, true )` — WooCommerce's own `customer_note`
email fires from this automatically, no custom mailer needed) and
immediately calls `hns_activate_subscription()`, **regardless of the parent
order's status** — this was a deliberate answer from the client to two
design questions asked before building this: the trigger is a dedicated
button in HNS (not event-sniffing on notes added elsewhere), and sending the
info is itself what activates the subscription, it doesn't just wait for the
order status too.

Integration with **Order Note Templates (ONT)** is read-only and soft: this
class runs `SHOW TABLES LIKE '{prefix}order_note_templates'` and, if it
exists, `SELECT id, title, note_text FROM ... WHERE note_type = 'customer'`
to populate a template picker in this plugin's own Pro settings. **ONT
itself is not modified.** This is a private-contract dependency on ONT's
table shape, not a public API — reasoned trade-off: ONT is already live on
WordPress.org/Freemius, and touching it to expose a proper public hook would
mean a full re-publish cycle (the exact process that already went wrong once
for OTL) for a feature that doesn't need it yet. If ONT ever changes that
table's schema, only this one `get_ont_customer_templates()` method needs
updating. Works with zero changes even if ONT isn't installed — falls back
to a plain-text field in this plugin's own settings.

**`class-hns-product-rules__premium_only.php`** — per-product/per-plan
overrides for `initial_status` / `activate_on_statuses`, stored as a list of
rules (`hns_pro_product_rules` option) and applied via the
`hns_subscription_options` filter. First matching rule (by product ID
present in the subscription's line items) wins; no match falls through to
the global settings unchanged. Settings UI intentionally kept simple (an
existing-rules-plus-one-empty-row table with its own `admin-post.php`
handler, matching the pattern already used by ONT's own auto-insert-rules
Pro feature) rather than a full drag-and-drop rule builder.

**`class-hns-escalation__premium_only.php`** — hourly WP-Cron
(`hns_pro_escalation_check`, scheduled idempotently on `init`, cleared on
deactivation) that finds subscriptions on hold longer than a configured
threshold via `wc_get_orders()` with a `meta_key`/`meta_value` query — **not**
a raw SQL query — specifically because `WC_Order_Query` resolves meta
queries against the correct storage automatically (HPOS custom tables or
`wp_postmeta`), which is exactly the class of bug this plugin shipped with
originally (see the 1.2.1 section above). Records `_hns_held_at` via the
`hns_subscription_held` action; clears both `_hns_held_at` and the
`_hns_escalated` re-notify guard via `hns_subscription_activated` and via its
own `woocommerce_subscription_status_changed` listener when a subscription
leaves hold for any terminal state. Three configurable actions: notify only
(fires `hns_subscription_escalated`, once per hold — doesn't re-fire every
hour), auto-activate as a safety net, or auto-cancel as abandoned.

**`class-hns-notifications__premium_only.php`** — posts a JSON payload
(`{"text": "...", "event": "...", ...}`) to a configured webhook URL on
`hns_subscription_held` / `hns_subscription_activated` /
`hns_subscription_escalated`, each independently toggleable. The `text` field
is Slack/Discord/Mattermost-incoming-webhook-compatible directly; other
services (Telegram etc.) are expected to sit behind a small relay
(Zapier/Make) that reads the same JSON. Delivery failures are logged via the
free `hns_log()` helper, not surfaced to the customer-facing flow at all —
a notification failure must never block activation.

### New meta keys (Pro only, but cleaned up unconditionally)
`_hns_held_at` (timestamp) and `_hns_escalated` (guard flag), both written
through subscription CRUD methods only (never `*_post_meta()` — see the
1.2.1 lesson above, applied from the start here). `uninstall.php` now removes
these two keys and all four `hns_pro_*` options unconditionally — harmless on
a site that never activated Pro, since deleting an option/meta key that was
never set is a no-op.

## 1.3.1 — first real Plugin Check run
The client ran the actual Plugin Check plugin against the built zip on a real
WordPress + WooCommerce + WooCommerce Subscriptions install (not a static
inspection) and sent back the report. 11 errors, 5 warnings:

- **Missing `translators:` comments** on every `sprintf()`/`printf()` call
  whose format string has a placeholder (`hold-new-subscriptions.php`, both
  `class-hns-email-*.php`, both plain-text templates). Added a
  `/* translators: ... */` comment directly above each one, describing what
  each `%s`/`%1$d`/etc. placeholder is.
- **Unescaped `$subscription`/`$order` IDs in the email templates**
  (`emails/*.php`, `emails/plain/*.php`) — passed straight into
  `printf( esc_html__( '...%d...' ), $sub->get_id() )` without wrapping the
  *argument* itself in an escaping function. `esc_html__()` only escapes the
  translated format string, not the interpolated values. Fixed by wrapping
  every ID argument in `absint()`.
- **`load_plugin_textdomain()` discouraged since WP 4.6** — removed
  entirely, matching Order Tags & Labels' existing convention: WordPress
  auto-loads a plugin's bundled `.mo` files from its `Text Domain`/`Domain
  Path` headers alone, no manual call needed, whether or not the plugin ends
  up hosted on WordPress.org. Confirmed the bundled `ru_RU`/`en_US` files
  will still load correctly without it (the header-based auto-loader covers
  bundled files, not only translate.wordpress.org-hosted ones).
- **Two `phpcs:ignore` comments weren't actually suppressing anything.**
  In the escalation module, one combined ignore comment sat several lines
  above a multi-line `wc_get_orders( array( ... ) )` call — `phpcs:ignore`
  only suppresses the line immediately below it, not a whole following
  block, so the `meta_key`/`meta_value` warnings fired anyway. Moved each
  ignore comment to sit directly above its own array line. **Same root cause
  as the very lesson this project was built to apply** ("phpcs:ignore only
  on its own line, not trailing") — turns out "own line" isn't sufficient on
  its own either; it also has to be the line *immediately* before the
  flagged one, not just somewhere above it.
- **`$_POST['hns_pro_rules']` flagged as unsanitized input** even though
  every field of every row is individually validated a few lines later
  (`absint()`, `sanitize_key()` + a real status whitelist) — phpcs's sniff
  doesn't do that kind of data-flow analysis across a loop, so it flags the
  raw array access. Added a scoped `phpcs:ignore` with a comment explaining
  where the real sanitization happens.
- **`DEVELOPMENT.md` flagged as an unexpected file in the plugin root** —
  Plugin Check expects only specific markdown files (like `readme.txt`,
  which isn't markdown anyway) in a plugin meant for distribution. This file
  stays in the git repo for history/context but is now excluded from the
  zip built for distribution (see the build command below).
- Two `WordPress.DB.SlowDBQuery.slow_db_query_meta_key`-family warnings were
  judged as acceptable false positives rather than "fixed": one is
  `WC_Order_Query`'s only supported way to query arbitrary custom meta (no
  indexed alternative exists), scoped to a small on-hold/pending subset; the
  other is `$wpdb->delete()` matching a literal `meta_key` *column*, which
  the sniff can't distinguish from a slow `WP_Query` meta lookup. Both are
  suppressed with an inline comment explaining why, not silently ignored.

**Lesson for future plugins:** static review (even careful, file-by-file
review) reliably misses the two things a real Plugin Check run against a
real WordPress install catches: translator-comment coverage across every
placeholder string in the codebase, and `phpcs:ignore` comments that look
correctly placed but are scoped to the wrong line in a multi-line
expression. Both classes of issue are invisible without actually running the
tool — this is the concrete case for the standing "Plugin Check must run
against a real WP install, not a static/cached inspection" rule already in
this file.

### Distribution build command (excludes dev-only files)
```bash
cd gh-wordpress-plugins
zip -r -X hold-new-subscriptions-<version>.zip hold-new-subscriptions \
  -x "*.DS_Store" -x "__MACOSX*" -x "*/DEVELOPMENT.md"
```

## Still open before this is publish-ready
- **Re-run Plugin Check on the 1.3.1 build** to confirm the fixes above
  actually clear the reported errors/warnings and that nothing new surfaces.
  This wasn't done yet in this pass (no live WordPress + WooCommerce
  Subscriptions install available here) — same as before, needs the real
  test site.
- Freemius/monetization integration is deliberately not part of this pass.
- `readme.txt` deliberately does **not** describe the Pro modules yet (no
  "Professional Features" section like ONT/OTL have). Those features aren't
  purchasable — `hns_is_pro()` hard-codes false — so advertising them on a
  real WordPress.org listing right now would be describing something that
  doesn't work for anyone who reads it. Add that section only once Freemius
  is actually wired up and Pro is buyable.
- No admin-facing "Upgrade to Pro" upsell exists anywhere yet (unlike ONT/OTL,
  which show one via `wc_ont_fs()->get_upgrade_url()`-style links). Add this
  once Freemius is connected, following the same pattern.
- Text Domain currently stays `hold-new-subscriptions` — if/when this is
  submitted to WordPress.org and WordPress.org assigns a different slug
  (as happened with both other plugins), the Text Domain will need to be
  realigned to match, following the same convention documented in the other
  two plugins' DEVELOPMENT.md files (file/folder name and any Freemius slug
  stay on the original identifier forever; only `Plugin Name`, the readme
  title, and `Text Domain` pick up the WordPress.org-assigned slug).

## Changelog (dev notes, not the plugin readme)
### 1.3.0
- Added the Pro architecture (`hns_is_pro()`, conditional `__premium_only.php`
  loading) and three free-file extension points
  (`hns_activate_subscription()`, `hns_subscription_options` filter,
  `hns_subscription_held`/`hns_subscription_activated` actions), all
  behaviour-preserving for free installs.
- Added four Pro modules (all inert until `hns_is_pro()` is wired to a real
  license check): send-info-and-activate with soft ONT integration,
  per-product hold rules, an escalation timer, and webhook notifications.
- `uninstall.php` now also removes the Pro modules' options and meta keys.

### 1.2.1
- Fixed HPOS-breaking `add_post_meta()` duplicate-activation guard (see
  above).
- Added HPOS `declare_compatibility()`.
- Fixed `uninstall.php` to clean up meta correctly on both legacy and HPOS
  storage, and to also remove `_hns_hold_target` (previously missed).
- Removed false `Plugin URI` header.
- Rewrote `readme.txt` for WordPress.org (Installation, FAQ, proper
  Changelog/Upgrade Notice sections, Contributors, tag count).
