=== Hold New Subscriptions Until Order Completed ===
Contributors: prowebdeignuk
Tags: woocommerce, subscriptions, order status, hpos, workflow
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.2
Requires Plugins: woocommerce
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Hold newly created WooCommerce Subscriptions until the parent order reaches a status you choose, then activate them automatically.

== Description ==

By default, WooCommerce Subscriptions activates a new subscription as soon as checkout completes — even if the store still needs to manually review, verify, or fulfil the order before the customer should actually have access. Customers see "Active" right away, try to use what they paid for, can't, and contact support.

**Hold New Subscriptions Until Order Completed** keeps a new subscription On-hold (or Pending) until its parent order reaches a status you choose — Completed by default — then switches it to Active and, optionally, emails the customer.

= Key Features =

* Puts new subscriptions on **On-hold** or **Pending** right after checkout, without interfering with payment processing or gateway callbacks
* Automatically activates the subscription once the parent order reaches any of the order statuses you select (defaults to **Completed**)
* Optionally skip renewal orders, so the hold logic only applies to a subscription's initial order
* Optionally restrict the hold behaviour to specific payment gateways
* Optional subscription notes and WooCommerce log entries for every action the plugin takes
* Optional customer emails — one when a subscription is placed on hold, one when it's activated
* Full **HPOS** (High-Performance Order Storage) compatibility
* Built-in English and Russian translations, with a Russian fallback that works even without the compiled `.mo` file

= Who Is This For? =

Stores that sell WooCommerce Subscriptions but need a manual review, verification, or fulfilment step before granting access — so subscribers don't see "Active" prematurely and don't reach out to support asking why they can't use something the system already marked as active.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/hold-new-subscriptions/`, or install directly through the WordPress plugins screen
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **WooCommerce → Hold Subscriptions** to configure the initial hold status, the order statuses that trigger activation, and the optional emails, notes, and logging
4. Requires WooCommerce and WooCommerce Subscriptions to be active

== Frequently Asked Questions ==

= Does this affect renewal orders? =

Not by default. "Skip renewal orders" is enabled out of the box, so only a subscription's initial order goes through the hold/activate flow. You can disable this if renewal orders should also be able to trigger activation.

= Does it work with WooCommerce HPOS? =

Yes. The plugin declares compatibility with WooCommerce's High-Performance Order Storage and uses the subscription's own metadata API throughout, so it works the same whether HPOS is on or off.

= Will this break my payment gateway? =

No. The plugin never changes a subscription's status while the order is still being processed at checkout. The hold is applied only after checkout has fully completed (on the thank-you page or payment-complete event), so scheduled payments and gateway callbacks are unaffected.

= Can I limit this to specific payment methods? =

Yes. Enable "Limit by payment gateways" in the settings and choose which gateways the hold logic should apply to. Orders paid through other gateways are left untouched.

= What happens if I select multiple activation statuses? =

The subscription activates as soon as the parent order reaches any one of the statuses you selected.

== Screenshots ==

1. Settings page under WooCommerce → Hold Subscriptions

== Changelog ==

= 1.3.1 =
* Fixed: Plugin Check errors — missing translators comments on strings with placeholders, and unescaped subscription/order IDs in the email templates (now cast with `absint()`).
* Fixed: Plugin Check warnings — removed the discouraged `load_plugin_textdomain()` call (WordPress auto-loads bundled translations from the Text Domain/Domain Path headers since 4.6), corrected two `phpcs:ignore` comments that weren't actually suppressing the lines they were meant to.
* Internal: `DEVELOPMENT.md` is excluded from the distributed plugin zip.

= 1.3.0 =
* Internal: added extensibility hooks (`hns_subscription_options`, `hns_subscription_held`, `hns_subscription_activated`) and a shared activation helper. No behaviour change for this (free) build — these exist so optional Pro functionality can plug in later without touching this codebase.

= 1.2.1 =
* Fixed: the duplicate-activation guard used `add_post_meta()`, which reads and writes `wp_postmeta` directly and silently stops working once a store enables WooCommerce HPOS (subscriptions then store their meta in a separate custom table). It now uses the subscription's own metadata API (`get_meta()` / `update_meta_data()` / `save_meta_data()`), matching HPOS and legacy storage alike.
* Added: `FeaturesUtil::declare_compatibility( 'custom_order_tables', ... )` so WooCommerce recognizes the plugin as HPOS-compatible instead of listing it as untested.
* Fixed: uninstall cleanup now removes the plugin's meta flags from the correct table (HPOS or `wp_postmeta`) and also removes the `_hns_hold_target` flag, which the previous uninstall routine missed entirely.
* Removed: a `Plugin URI` header pointing at a WordPress.org plugin listing this plugin was never actually submitted to.

= 1.2.0 =
* Added Russian and English translations with a `gettext`-based Russian fallback for installs missing the compiled `.mo` file.

== Upgrade Notice ==

= 1.2.1 =
Fixes a WooCommerce HPOS compatibility bug in the duplicate-activation guard and in uninstall cleanup. Update recommended for any store using HPOS.
