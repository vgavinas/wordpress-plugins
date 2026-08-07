=== Order Note Templates for WooCommerce ===
Contributors: protechnologies
Tags: woocommerce, order notes, templates, subscriptions, hpos
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Save and reuse order note templates in WooCommerce admin. Works with HPOS and WooCommerce Subscriptions.

== Description ==

**WooCommerce Order Note Templates** lets you create reusable note templates and insert them into WooCommerce orders and subscriptions with a single click — no more typing the same messages over and over.

= Key Features =

* 📝 **Save unlimited templates** — create customer-facing and internal note templates
* ⚡ **One-click insert** — select a template from a dropdown and insert it instantly
* 🔄 **Smart variables** — automatically fills in order/subscription details:
  * `{order_id}` — order number
  * `{subscription_id}` — subscription number
  * `{customer_name}` — customer's full name
  * `{billing_email}` — customer email
  * `{total}` — order total
  * `{next_payment}` — next payment date (subscriptions)
  * `{start_date}` — subscription start date
* ✅ **Full HPOS support** — works with WooCommerce High-Performance Order Storage
* 📋 **WooCommerce Subscriptions support** — works on both order and subscription screens
* 🔒 **Two note types** — customer-visible and internal (staff only) templates
* 🔢 **Custom sort order** — arrange templates in any order

= Who Is This For? =

Any WooCommerce store that adds order notes regularly — support teams, shop managers, fulfilment staff. Especially useful for stores with WooCommerce Subscriptions where you need to send consistent messages about renewals, payment issues, or account updates.

= HPOS Compatible =

This plugin is fully compatible with WooCommerce's High-Performance Order Storage (HPOS). The template selector appears on both classic and HPOS order/subscription screens.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wc-order-note-templates/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **WooCommerce → Шаблоны примечаний** to create your first template
4. Open any order or subscription — the template selector appears in the sidebar

== Frequently Asked Questions ==

= Does it work with WooCommerce HPOS? =

Yes! The plugin is fully tested and compatible with WooCommerce High-Performance Order Storage.

= Does it work with WooCommerce Subscriptions? =

Yes! Templates can be inserted into both orders and subscriptions. Subscription-specific variables like `{next_payment}` and `{start_date}` are available on subscription screens.

= Can I have both customer and internal templates? =

Yes. When creating a template, choose "Customer" (visible to the buyer via email) or "Internal" (visible to staff only). Both types appear in separate groups in the dropdown.

= Will the variables be replaced automatically? =

Yes. When you select a template, the plugin fetches the current order/subscription data and replaces all variables before inserting the text into the note field.

= Does it slow down my store? =

No. The plugin only loads its assets on WooCommerce order and subscription admin screens. There is no frontend impact.

== Screenshots ==

1. Template selector in the order sidebar
2. Template management page (WooCommerce → Order Note Templates)
3. Template with variables being inserted
4. Works on WooCommerce Subscriptions screen

== Changelog ==

= 1.0.1 =
* Added support for HPOS subscription screen (`woocommerce_page_wc-orders--shop_subscription`)
* Added subscription variables: `{subscription_id}`, `{next_payment}`, `{start_date}`
* Improved context detection for subscription vs order screens

= 1.0.0 =
* Initial release
* Template management page under WooCommerce menu
* HPOS order screen support
* Classic CPT order and subscription support
* Customer and internal note types
* Variable substitution: `{order_id}`, `{customer_name}`, `{billing_email}`, `{total}`

== Upgrade Notice ==

= 1.0.1 =
Added full WooCommerce Subscriptions support including HPOS subscription screens.
