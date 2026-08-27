# Order Note Templates for WooCommerce — Development Notes

## Plugin Info
- **Freemius slug (immutable):** order-note-templates-for-woocommerce
- **WordPress.org slug:** pro-web-design-order-note-templates-for-woocommerce
- **WordPress.org:** live — https://wordpress.org/plugins/pro-web-design-order-note-templates-for-woocommerce/
  (submitted August 7, 2026; approved and published to SVN some time after —
  exact approval/publish date not recorded here, confirmed live by directly
  opening the URL. New listings can take ~6–14 days after the SVN commit to
  show up in wordpress.org's on-site search, even once the page itself is
  live — don't read "not in search yet" as "not published.")
- **Version:** 1.2.4
- **GitHub:** https://github.com/vgavinas/wordpress-plugins
- **Freemius Product ID:** 36694
- **Freemius function:** ontfw_fs()

> ⚠️ The Freemius slug (`order-note-templates-for-woocommerce`) and the
> WordPress.org-assigned slug (`pro-web-design-order-note-templates-for-woocommerce`)
> are DIFFERENT — same situation as Order Tags & Labels. The file name,
> folder name, and the `'slug'` value in the Freemius SDK snippet must stay on
> the original Freemius slug forever (changing it would orphan existing
> installs); only the `Plugin Name` header, readme title, and `Text Domain`
> use the WordPress.org-assigned slug/branding.

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
- `WC_ONT_VERSION = '1.1.6'`

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
- [x] Approved on WordPress.org → free version uploaded via SVN — live at
      https://wordpress.org/plugins/pro-web-design-order-note-templates-for-woocommerce/

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

## Premium Code Separation — do not break this

WordPress.org guideline 5 forbids shipping feature code in the free plugin and
gating it behind a licence check. The premium implementation must be **absent**
from the free build, not merely unreachable.

Freemius strips by **filename**, so every Pro module lives in
`includes/class-<name>__premium_only.php`. A `__premium_only` marker buried
inside a function body is not enough — that was the 1.1.4 mistake, and the free
build shipped the full Pro implementation.

`WC_ONT_Admin_Page::load_pro_modules()` checks `file_exists()` then
`class_exists()`, and every call site is guarded with `class_exists()`, so the
plugin runs correctly whether or not the files are present.

**After every deploy:** download the generated free build from Freemius and
confirm the four `__premium_only` files are gone:

```bash
unzip -l <free-build>.zip | grep premium_only   # expect no output
```

`DEVELOPMENT.md` is excluded from the shipped archive but kept in the repo.

## phpcs Suppressions

Always put `// phpcs:ignore` on its **own line**, never trailing after code.
The Freemius free-build processor rewrites the `fs_dynamic_init()` block and
drops trailing comments, so trailing suppressions vanish from the free build
and Plugin Check flags warnings that pass locally.

Run Plugin Check against the **generated free build**, not the dev source —
they differ.

## Changelog
### 1.2.4
- Bumped `Tested up to` (readme header) from 7.0 to 7.1 following the
  WordPress core update. Plugin Check flags a stale `Tested up to` as an
  **error** (`outdated_tested_upto_header`), not just a warning — WordPress.org
  excludes plugins with this error from on-site search results, so this
  needs to be kept current after every WordPress core release, not just at
  submission time. (Note: this dev-notes changelog had a gap between 1.1.6
  and 1.2.4 — the plugin's own `readme.txt` changelog has the entries for
  1.1.7–1.2.3 that weren't mirrored here; not backfilling that gap now since
  I wasn't present for those changes.)

### 1.1.6
- phpcs suppressions moved onto standalone lines so the free build keeps them

### 1.1.5
- Pro modules renamed to `__premium_only.php` so Freemius removes them from the free build
- Pro loading and every call site guarded with `class_exists()`
- DEVELOPMENT.md excluded from the distributed archive

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
