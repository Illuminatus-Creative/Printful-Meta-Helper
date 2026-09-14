# Printful Meta Helper

Internal WordPress plugin. Not for distribution. Requires WooCommerce.

Defines a base garment or item ("blank") once as a `pmh_blank` taxonomy term,
attaches it to products, and renders the blank's size chart and materials on
product pages through shortcodes. Products store only a term reference; the
chart and materials are read from the blank at render time, and the chart is
filtered to the sizes the product actually has variations for.

## Status

| Phase | Delivers | State |
|---|---|---|
| 0 | Bootstrap, WooCommerce guard, feature declarations | done |
| 1 | `pmh_blank` taxonomy, single-blank enforcement, products-list filter | done |
| 2 | Term meta: applies-to categories, materials, kind, size chart | done |
| 3 | Importer: Printful size JSON, page text paste, materials paste | done |
| 4 | `[pmh_size_chart]` renderer and unit toggle | todo |
| 5 | Variation filter | todo |
| 6 | `[pmh_materials]`, `[pmh_blank_name]` | todo |
| 7 | Product metabox: filtered single select with preview | todo |
| 8 | Size grid editor | todo |

## Install

Copy or clone this folder into `wp-content/plugins/` and activate. There is
no update mechanism by design (`Update URI: false`). Bump `Version` in the
plugin header by hand.

## Assigning blanks

Products → Blanks to create terms. Assign on the product edit screen, or use
Bulk Edit on the products list. The list has a "No blank assigned" filter to
find stragglers. A product can only ever hold one blank; if more than one is
submitted, the most recently added one wins.

## Editing a blank

Products → Blanks → edit. Three import boxes are processed when you save:

- **Paste from Printful** (materials): the bulleted paragraph is split into
  base material, colour exceptions, fabric weight and construction.
- **Import Printful JSON**: the size-guide JSON. Inch rows from
  `productMeasurements` become the garment chart and from
  `modelMeasurements` the body chart. Centimetre rows are ignored because
  they are exact conversions.
- **Import pasted table**: the tab-separated table copied from the Printful
  page, with a radio for the unit it was showing.

Each box is ignored when empty, so saving again never clobbers data. The two
chart fields are editable JSON until the grid editor lands. Import results
and errors show as a notice on the edit screen; the add-new form saves via
AJAX, so open the blank afterwards to see what was imported.

## Tests

```
composer install
composer test
```

Tests cover the pure classes (size chart normalising, cell parsing, the
three importers) and run without WordPress.

## Conventions

- Prefix `pmh_` / `PMH_`, text domain `printful-meta-helper`.
- Inches are stored; centimetres are computed at render.
- No page builder is referenced anywhere in the code.
