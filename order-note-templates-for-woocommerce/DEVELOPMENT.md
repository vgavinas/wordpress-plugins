# Order Note Templates for WooCommerce — Development Notes

## Plugin Info
- **Slug:** order-note-templates-for-woocommerce
- **Version:** 1.1.1
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
| category | VARCHAR(100) | Pro only, added via ALTER TABLE |
| pdf_attachment | VARCHAR(500) | Pro only, added via ALTER TABLE |
| sort_order | INT | display order |
| created_at | DATETIME | auto |

## Available Variables
`{order_id}`, `{subscription_id}`, `{customer_name}`, `{billing_email}`, `{total}`, `{next_payment}`, `{start_date}`

## Key Constants
- `WC_ONT_FREE_LIMIT = 3` — max templates on free plan
- `WC_ONT_VERSION = '1.1.1'`

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

## Changelog
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
