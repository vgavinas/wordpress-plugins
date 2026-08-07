# Order Note Templates for WooCommerce — Development Notes

## Plugin Info
- **Slug:** order-note-templates-for-woocommerce
- **Version:** 1.1.4
- **GitHub:** https://github.com/vgavinas/wordpress-plugins
- **WordPress.org:** submitted August 7, 2026 — awaiting review
- **Freemius Product ID:** 36694
- **Freemius function:** ontfw_fs()

> ⚠️ The Freemius slug in the SDK snippet MUST match the WordPress.org slug exactly:
> `order-note-templates-for-woocommerce`. A mismatch breaks free-version auto-updates.

## Freemius
- **Account:** dashboard.freemius.com
- **Store:** Pro Technologies Limited
- **Plans:** Free (3 templates, orders only) + Professional ($29/year, $79 lifetime)
- **Trial:** 14 days, no credit card
- **Support email:** support@pro-webdesign.co.uk
- **Payout:** configure when first sale arrives

## Free vs Pro
| Feature | Free | Pro |
|---|---|---|
| Templates | Max 3 | Unlimited |
| Orders support | ✅ | ✅ |
| Subscriptions support | ❌ | ✅ |
| Template categories | ❌ | ✅ |
| Import/Export | ❌ | ✅ |
| Auto-insert on status change | ❌ | ✅ |
| PDF attachments | ❌ | ✅ |

## File Structure
```
order-note-templates-for-woocommerce/
├── order-note-templates-for-woocommerce.php  ← main file, Freemius init
├── readme.txt                                 ← WordPress.org listing
├── assets/
│   ├── admin.css
│   └── admin.js                              ← category grouping, variable substitution
├── includes/
│   ├── class-admin-page.php                  ← templates list, add/edit form, tabs
│   ├── class-order-meta-box.php              ← sidebar widget in order/subscription screen
│   ├── class-ajax.php                        ← get_templates, get_order_data
│   ├── class-import-export.php               ← Pro: JSON import/export
│   ├── class-categories.php                  ← Pro: category column + management tab
│   ├── class-auto-insert.php                 ← Pro: auto-insert on order status change
│   └── class-pdf-attachments.php             ← Pro: PDF upload + email attachment
├── languages/                                ← empty, ready for translations
└── vendor/freemius/                          ← Freemius SDK 2.13.4
```

## DB Table: wp_order_note_templates
| Column | Type | Notes |
|---|---|---|
| id | BIGINT | PK |
| title | VARCHAR(200) | template name |
| note_text | TEXT | template body with {variables} |
| note_type | VARCHAR(20) | 'customer' or 'internal' |
| category | varchar(100) | in main schema, used by Pro |
| pdf_attachment | varchar(500) | in main schema, used by Pro |
| sort_order | INT | display order |
| created_at | DATETIME | auto |

## Available Variables
`{order_id}`, `{subscription_id}`, `{customer_name}`, `{billing_email}`, `{total}`, `{next_payment}`, `{start_date}`

## Key Constants
- `WC_ONT_FREE_LIMIT = 3` — max templates on free plan
- `WC_ONT_VERSION = '1.1.4'`

## How to Test Pro Features
1. Install plugin on WordPress site with WooCommerce
2. Add to wp-config.php:
   ```php
   define( 'WP_FS__DEV_MODE', true );
   define( 'WP_FS__SKIP_EMAIL_ACTIVATION', true );
   ```
3. Generate license in Freemius Dashboard → Licenses
4. Activate via wp-admin/plugins.php

## Deployment Checklist
- [ ] Run Plugin Check — must show zero errors/warnings
- [ ] Upload zip to Freemius → Deployment → Add New Version
- [ ] Release in Freemius
- [ ] Push to GitHub
- [ ] If approved on WordPress.org → upload free version via SVN

## Schema Rules

The **entire** schema — including Pro-only columns — lives in
`wc_ont_create_table()` in the main plugin file. Never add columns from a
feature class.

Two things will silently break if changed:

- `CREATE TABLE` must **not** say `IF NOT EXISTS`. dbDelta() extracts the table
  name with `preg_match("|CREATE TABLE ([^ ]*)|")` and would read `IF` as the
  table name, turning the call into a no-op on existing installs.
- dbDelta() needs one field per line and **two spaces** after `PRIMARY KEY`.

Migration runs synchronously in `wc_ont_init()` before any class is loaded, and
re-checks the actual columns via `wc_ont_column_exists()` — a version bump alone
is not trusted.

## Hook Signatures — read before touching

`woocommerce_new_order_note_data` passes an **array**, not an order:

```php
apply_filters( 'woocommerce_new_order_note_data', $commentdata,
    array( 'order_id' => int, 'is_customer_note' => bool ) );
```

Calling `->get_id()` on it is a fatal error. WooCommerce itself adds notes
through this path (e.g. the email logger on status change), so the crash fires
on ordinary store activity, not just on our own code path.

`woocommerce_order_status_changed` **does** pass an order object, fourth argument.

## PDF Attachment Flow

The link between "template inserted" and "note saved" is a user-scoped
transient, because WooCommerce saves the note through its own AJAX and carries
no reference to our template:

1. `assets/admin.js` → `wc_ont_mark_template` on Insert click
2. `WC_ONT_PDF_Attachments::ajax_mark_template()` sets `wc_ont_tpl_{user}_{order}`
3. `store_pdf_in_note()` reads and deletes it, appends the link
4. `attach_pdf_to_email()` picks the file up for the customer email

If Insert is clicked but Add never is, the hint expires after 5 minutes.

## Admin Layout Classes

`.wc-ont-layout` is a **two-column grid (420px / 1fr)** built for the Templates
tab, which has a form card plus a list card. Putting a single card inside it
pins that card to 420px and leaves the rest of the screen blank — this is what
broke the Categories tab in 1.1.3.

Use the right wrapper:

| Wrapper | Use for |
|---|---|
| `.wc-ont-layout` | form card + list card (Templates) |
| `.wc-ont-layout--even` | two cards of equal weight (Import / Export) |
| `.wc-ont-panel` | one full-width card (Categories, Auto-insert) |

Also avoid `table-layout: fixed` (the `fixed` class on `wp-list-table`) on
tables holding inline controls — declared widths win over content and the text
column collapses.

## Changelog
### 1.1.4
- Categories tab moved out of the two-column grid onto `.wc-ont-panel`
- Category table switched to auto layout with a 180px minimum on the name column
- Action controls wrapped in a flex row so Rename and Delete stay on one line
- Import/Export cards evened out; inline styles moved into the stylesheet

### 1.1.3
- Fixed fatal in `store_pdf_in_note()` — filter passes an array, not an order
- Wired up template marking; `set_note_template()` was dead code and the PDF flow never ran
- Path traversal guard on emailed attachments (realpath inside uploads dir)
- `on_status_change()` now re-loads the order if the hook argument is unusable

### 1.1.2
- Fixed: saving a template silently failed when Pro was active (missing schema columns)
- Fixed: failed DB writes reported success — insert/update results are now checked
- Fixed: import/export dropped category and pdf_attachment
- Schema consolidated into the main file; per-class ALTER TABLE removed

### 1.1.1
- Fixed truncated slug in Freemius SDK config (`...woocommerc` → `...woocommerce`)
- Product title in Freemius corrected to full name

### 1.1.0
- Pro: Import/Export templates (JSON)
- Pro: Template categories with grouping in meta box dropdown
- Pro: Auto-insert template on order status change
- Pro: PDF attachments (link in note + email attachment)
- Freemius SDK integrated

### 1.0.1
- Initial release
- HPOS compatible
- WooCommerce Subscriptions support (Pro)
- Free plan: 3 templates, orders only
