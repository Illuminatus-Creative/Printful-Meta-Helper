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
| 3b | Import from a product's `pf_advanced_size_chart` meta by ID or SKU | done |
| 4 | `[pmh_size_chart]` renderer and unit toggle | done |
| 5 | Variation filter | done |
| 6 | `[pmh_materials]`, `[pmh_blank_name]` | done |
| 7 | Product metabox: filtered single select with preview, and a mismatch warning when the product's own Printful chart differs from the blank | done |
| 8 | Size grid editor | done |
| 9 | Grouping tool: scan products carrying `pf_advanced_size_chart`, group by identical inch rows, propose one blank per group, bulk-assign | done |

## Printful's own copy of the chart

Printful's sync writes the size-guide JSON to the product meta key
`pf_advanced_size_chart` on products it pushes. Legacy products do not have
it. The plugin never renders from it: it is a per-product snapshot and the
blank is the single source of truth. It is used only as an import source
(phase 3b), as a cross-check in the product metabox (phase 7), and to seed
blanks in bulk (phase 9).

## Install

Copy or clone this folder into `wp-content/plugins/` and activate. There is
no update mechanism by design (`Update URI: false`). Bump `Version` in the
plugin header by hand.

## Assigning blanks

Products → Blanks to create terms. On the product edit screen the Blank box
is a single select. It lists only blanks whose "applies to" categories
intersect the product's ticked categories, re-filtering as you tick; "Show
all blanks" bypasses that. The panel under it previews the chosen blank:
material, weight, the sizes that will render given the saved variations, a
warning when nothing would render, and, on products Printful has pushed,
whether the blank's chart matches the product's own Printful chart. If the
saved blank falls outside the filter it stays selected and is flagged rather
than silently dropped.

Bulk Edit on the products list assigns across many products at once. The
list has a "No blank assigned" filter to find stragglers. A product can only
ever hold one blank; if more than one is submitted, the most recently added
one wins.

## Seeding blanks from the catalogue

Products → Blank Groups scans every product carrying Printful's chart meta
and groups them by identical inch rows, largest group first. For each group,
tick Apply and either pick an existing blank (one with an identical chart is
preselected and marked ✓) or type a name for a new one. A new blank is
created as apparel with the group's garment chart, body chart and the union
of the products' categories, and is fully editable afterwards. Products that
already have a blank are left alone unless you untick that option. Legacy
products without the meta are counted on the screen and stay in the "No
blank assigned" filter for hand assignment.

Two different garments with byte-identical size charts would land in one
group. Check the product list under each group before applying.

## Editing a blank

Products → Blanks → edit. Three import boxes are processed when you save:

- **Paste from Printful** (materials): the bulleted paragraph is split into
  base material, colour exceptions, fabric weight and construction.
- **Import Printful JSON**: the size-guide JSON. Inch rows from
  `productMeasurements` become the garment chart and from
  `modelMeasurements` the body chart. Centimetre rows are ignored because
  they are exact conversions.
- **Import from product**: a product ID or SKU. Reads the size-guide JSON
  Printful's sync stored on that product and runs it through the same
  importer. Legacy products carry no such meta and fail with a notice.
- **Import pasted table**: the tab-separated table copied from the Printful
  page, with a radio for the unit it was showing.

Precedence when more than one box is filled: JSON, then product, then
pasted table.

Each box is ignored when empty, so saving again never clobbers data. The two
chart fields are grids: columns are sizes, rows are measurements, a cell takes
`28`, `34-37` or `16 ½`, and sizes and measurements can be added, renamed and
removed. "Edit as JSON" exposes the stored structure; the grid writes to that
JSON on every change, so it is what gets saved either way. Cells that cannot
be parsed are outlined and dropped on save. Import results
and errors show as a notice on the edit screen; the add-new form saves via
AJAX, so open the blank afterwards to see what was imported.

## Shortcodes

```
[pmh_size_chart]
[pmh_size_chart product_id="123" unit="cm" toggle="0" note="0" table="body" class="extra classes"]
[pmh_materials]
[pmh_materials fields="material,weight" labels="0" class="extra classes"]
[pmh_blank_name]
```

`[pmh_materials]` renders a definition list of material (with colour
exceptions as a sub-list), fabric weight, construction and care, skipping
empty fields, for any blank kind. `[pmh_blank_name]` is the blank's name as
plain text for use inside a sentence. All three return an empty string when
the product has no blank.

Defaults to the product in the loop or the queried product. Renders the
blank's garment chart (`table="body"` for body measurements) with one row per
size, an inches/centimetres toggle, and the note. Returns an empty string,
never a message, when the product has no blank, the blank is not apparel,
the chart is empty, or no chart size matches the product's variations.

The chart shows only sizes the product has a visible variation for. A
product with no size attribute, or with an "Any size" variation, shows the
full chart. Stock is ignored; the variation buttons already show that.
Filters: `pmh_size_attribute` (which attribute is the size),
`pmh_product_sizes` (the resolved list), `pmh_chart_unit_suffix`,
`pmh_size_chart_html`.

### Styling

Markup is a contract; style it from the theme or a page-builder module and
never edit the plugin stylesheet.

```
.pmh-chart.pmh-chart--{blank-slug}.pmh-chart--unit-in|cm
  .pmh-chart__toggle > .pmh-chart__unit[data-unit][aria-pressed]
  .pmh-chart__scroll > table.pmh-chart__table
    th.pmh-chart__head (--size on the first)
    tr.pmh-chart__row[data-size] > th.pmh-chart__size, td.pmh-chart__cell
      span.pmh-chart__val--in / span.pmh-chart__val--cm (one shown by CSS)
  p.pmh-chart__note
```

```
.pmh-materials.pmh-materials--{blank-slug} > dl.pmh-materials__list
  div.pmh-materials__item--material|weight|construction|care
    dt.pmh-materials__label, dd.pmh-materials__value
      span.pmh-materials__base, ul.pmh-materials__exceptions, ul.pmh-materials__lines
```

Custom properties on `.pmh-chart`: `--pmh-head-bg`, `--pmh-head-color`,
`--pmh-value-color`, `--pmh-size-color`, `--pmh-rule`, `--pmh-cell-padding`,
`--pmh-toggle-bg`, `--pmh-toggle-color`, `--pmh-toggle-border`,
`--pmh-toggle-active-bg`, `--pmh-toggle-active-color`, `--pmh-note-color`,
`--pmh-font-size`. On `.pmh-materials`: `--pmh-materials-label-color`,
`--pmh-materials-gap`.

The stylesheet and toggle script are enqueued in the head on product pages
that have a blank, and at render time anywhere else the shortcode appears.
The chosen unit is remembered per browser and applied to every chart on the
page; `window.pmhApplyUnit(root)` re-applies it to charts injected later.

## Tests

```
composer install && composer test     # PHP
npm install && npm test               # browser scripts under jsdom
```

Neither suite needs WordPress. `tests/wp-stubs.php` is a small in-memory
WordPress (posts, terms, meta, transients, capabilities) that the PHP tests
run the plugin against, so the save layer, the single-blank guard, the
grouping scan and the data access are covered alongside the pure classes.
The JavaScript tests load each admin and public script into a jsdom page
and drive it through clicks and input events.

`php tests/bench/profile.php [variations] [products] [blanks]` profiles the
front-end render, the product-screen data and the Blank Groups scan against
the fakes, reporting wall time and the calls that are a query or cache round
trip on a real site. Cold-cache cost per product page is one meta prime and
one term query regardless of variation count; the Blank Groups scan primes
product meta 200 products per query.

## Conventions

- Prefix `pmh_` / `PMH_`, text domain `printful-meta-helper`.
- Inches are stored; centimetres are computed at render.
- No page builder is referenced anywhere in the code.
- Classes autoload from `includes/class-pmh-{name}.php`; new classes need
  no `require`.
- `PMH_Blank` is the only reader and writer of term meta. `PMH_Util` holds
  the shared helpers, `PMH_Notices` the cross-redirect notices, and
  `PMH_Admin_Assets` every admin enqueue.
