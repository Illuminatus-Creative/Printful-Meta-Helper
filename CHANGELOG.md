# Changelog

The `Version` header in `printful-meta-helper.php` and the `PMH_VERSION`
constant move together, on every change that ships to a site: patch for a
fix, minor for a feature, major for a change that needs data or template
work on install. A test fails when the two disagree.

## 1.0.0

First complete release. Everything the build plan called for, plus the
additions made against the live store:

- `pmh_blank` taxonomy with a single-select product metabox, single-blank
  enforcement, list filter, Blank Groups seeding screen.
- Blank screen: applies-to categories, kind, materials (base, colour
  exceptions, weight, construction, care, disclaimers), companion-link
  wording, garment and body charts in a grid editor, four import paths.
- `[pmh_size_chart]`: sizes filtered to the product's variations, inches/
  centimetres toggle, body-chart rows as extra columns (`body_rows`, default
  Chest), fixed supplier line, one-decimal display.
- `[pmh_materials]`: colour exceptions and disclaimers limited to the
  product's colours; `filter_colours="0"` to show all.
- `[pmh_companion_link]`: one-to-one, two-way product link with wording from
  the product's own blank and admin diagnostics.
- `[pmh_blank_name]`.
- Admin help: tips, Help tabs, descriptions on every screen.
- Round trips per product page flat at two regardless of variation count;
  size and colour results cached under WooCommerce's product cache prefix.
