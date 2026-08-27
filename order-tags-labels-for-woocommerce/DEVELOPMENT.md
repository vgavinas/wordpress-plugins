# Order Tags & Labels for WooCommerce — Development Notes

## Plugin Info
- **Freemius slug (immutable):** order-tags-labels-for-woocommerce
- **WordPress.org slug:** pro-web-design-order-tags-labels-for-woocommerce
- **Version:** 1.1.6
- **GitHub:** https://github.com/vgavinas/wordpress-plugins
- **WordPress.org:** approved August 13, 2026
- **Freemius Product ID:** 36737 (Store ID 18989)
- **Freemius function:** wc_otl_fs()

> ⚠️ The file name, folder name, and the `'slug'` value in `fs_dynamic_init()`
> are tied to the original Freemius product registration and must stay
> `order-tags-labels-for-woocommerce` forever — changing them would orphan
> any site that has already installed the plugin. Only the displayed
> `Plugin Name` header, the readme title, and the `Text Domain` use the newer
> "Pro Web Design" branding (matching the WordPress.org-assigned slug, which
> is what enables WordPress.org's automatic per-slug translation loading).
> This is the same convention already used by Order Note Templates.

## Single source, two distribution channels
This repo is the **only** source for both Freemius and WordPress.org — do not
maintain a separate branch or copy per channel. Each channel builds its own
distributable from this same tree:
- **Freemius** auto-generates the Free zip from the uploaded Paid-source zip,
  stripping `__premium_only` files.
- **WordPress.org (SVN)** is *not* automatic — whoever publishes to SVN must
  manually strip the same files before committing to `trunk`/`tags`. Nothing
  enforces this, which is exactly how the free build ended up containing
  Professional code once already (see Changelog, 1.1.5).

## Premium Code Separation — do not break this
WordPress.org guideline 5 forbids shipping feature code in the free plugin and
gating it behind a licence check. The premium implementation must be
**absent** from the free distribution, not merely unreachable.

Freemius strips by **filename**: every Pro module lives in
`includes/class-<name>__premium_only.php`:
- `class-auto-tag-rules__premium_only.php`
- `class-bulk-actions__premium_only.php`
- `class-export__premium_only.php`
- `class-order-list-filter__premium_only.php`

`wc_otl_bootstrap()` loads these defensively (`file_exists()` +
`can_use_premium_code()`), and every call site is guarded with
`class_exists()`, so the plugin runs correctly whether or not the files are
present.

**Before every WordPress.org SVN commit:**
1. Copy the repo's plugin folder into the SVN working copy.
2. Delete the four `__premium_only.php` files from `includes/` in the copy.
3. Sanity check: `find trunk -iname "*premium_only*"` → expect no output.

**After every Freemius deploy:** download the generated free build and
confirm the same:
```bash
unzip -l <free-build>.zip | grep premium_only   # expect no output
```

Run Plugin Check against the **generated free build** (or the stripped SVN
copy), not the dev source — they differ.

## Changelog (dev notes, not the plugin readme)
### 1.1.6
- Bumped `Tested up to` (readme header) from 7.0 to 7.1 following the
  WordPress core update. Plugin Check flags a stale `Tested up to` as an
  **error** (`outdated_tested_upto_header`), not just a warning — WordPress.org
  excludes plugins with this error from on-site search results, so this
  needs to be kept current after every WordPress core release, not just at
  submission time.

### 1.1.5
- Text domain aligned to `pro-web-design-order-tags-labels-for-woocommerce`
  to match the WordPress.org-assigned slug (file/folder/Freemius-slug stay
  original) — same convention as Order Note Templates.
- **Mistake caught and fixed same day:** the very first WordPress.org SVN
  publish of this plugin copied the raw repo (full Paid source) straight
  into `trunk`, including all four `__premium_only` files — Professional
  code was live in the public free repository until it was caught and
  `svn rm`'d. Added this file, and the pre-commit checklist above, so it
  doesn't happen again (Order Note Templates hit the identical mistake in
  its own 1.1.4, see its DEVELOPMENT.md).
