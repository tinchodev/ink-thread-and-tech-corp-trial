# Plan: InkThread_DecorationPricing

Trial task: decoration pricing by print location (Magento Open Source 2.4.7-p9, PHP 8.3).
This is the agent-produced plan **before any code**. Edits made after review are in git history.

---

## 1. Goal (restated)

On the product page, a customer picks a decoration method (None / Embroidery / Screen print),
1–3 print locations and an optional note. The per-unit decoration fee is the sum of each selected
location's price at the quantity tier. **Client clarification (Q1):** the tier qty is the combined qty of all
cart lines with the same product and the same decoration (e.g. 6 S + 6 M of one tee = 12+ tier), not the single
line qty. See §2.0. Prices are configured in admin. The fee
re-prices on qty/option changes, and method/locations/note/fee (with a per-location breakdown)
flow cart → checkout → order, visible in cart, admin order view and customer order view,
for guests and logged-in customers. No core edits, unit-tested, Magento coding standard clean.

## 2. Key design decisions

### 2.0 Tier quantity: pooled across matching cart lines (client decision)
- **Group key:** visible (top-level) quote item's product id (the parent id for configurables) + method +
  sorted set of locations. Lines in the same group share one tier qty = sum of their qtys.
- **Not in the key:** the note, and the configurable child (size/colour). Different products never pool, even
  with identical decoration. *Assumption, as Q&A was one round. Documented in the README.*
- Lines with method None, and deleted items, are excluded.
- `Model/TierQuantityResolver` computes the groups from a list of quote items. It is pure logic over a small item
  adapter, so it can be unit-tested.
- Observer on `sales_quote_collect_totals_before` stamps each item's product with its group tier qty
  (`inkthread_decoration_tier_qty`) right before totals run. It is re-stamped on every collection,
  so it is never stale.
- Consequence: changing the qty of, or removing, one line **re-prices its sibling lines**. Every totals pass covers
  all items, so this is automatic. It is called out in QA and in the cart breakdown text.

### 2.1 Where the fee lives: inside the item unit price (chosen)
The fee is added to the quote item's final price, the same way native custom options add
their price. `row_total = (product final price + decoration unit fee at group tier) × line qty`.

**Extension point:** `after` plugin on `Magento\Catalog\Model\Product\Type\Price::getFinalPrice($qty, $product)`.
- The quote's `Subtotal` collector calls `$product->getFinalPrice($quoteItem->getQty())` on every
  totals collection, so qty changes are picked up. The plugin uses the stamped group tier qty (§2.0) when present
  and falls back to `$qty`.
- Configurable's `Price` subclass inherits the plugin. Its internal `parent::getFinalPrice()` call on the
  child does not go through the interceptor, so the fee is added exactly once, on the parent line.
  This must be verified (see §6).
- The plugin is a no-op when the product has no `inkthread_decoration` custom option, so catalog and
  listing prices are untouched.

**Rejected alternatives:**
| Option | Why not |
|---|---|
| `setCustomPrice()` in an observer | Clobbers admin "custom price", bypasses catalog price rules and tier prices of the base product, and must be manually redone on every qty change. |
| Separate quote/order total ("Decoration fee" line in totals) | Needs order/invoice/creditmemo collectors, new columns, PayPal line-item handling, and refund logic. Much bigger. It also makes a *per-line* breakdown harder, not easier. |
| Native product custom options | Option prices are flat/percent per unit and per product, with no qty tiers. They also would have to be created on every product. |
| `catalog_product_get_final_price` event | For configurables it fires with the *child* product, which doesn't carry the parent line's options. Fragile. |

Side effects to state in the README: the fee is taxed with the product's tax class, and %-discount cart
rules apply to it too (see §8 questions).

### 2.2 Capturing the selection: product type prepare event
Observer on `catalog_product_type_prepare_full_options` (and `_lite_` for admin/wishlist paths).
- Reads `inkthread_decoration[method|locations][]|note]` from the buy request.
- Runs the server-side validator. On failure it throws `LocalizedException`, so add-to-cart is aborted with a
  clear message (the message is shown on the PDP / cart by core).
- Stores the normalized selection as a quote item custom option `inkthread_decoration` (JSON).
  This makes lines with different decorations distinct cart lines (core compares item options),
  and "Edit item" in the cart re-runs the same path, so changing locations re-validates and re-prices.
- Guard: attach only to the top-level product (not the configurable child) to avoid duplicates.

### 2.3 Admin-configurable pricing
`Stores > Configuration > Catalog > InkThread Decoration Pricing`
- One **dynamic-rows** field per method (`ArraySerialized` backend): columns *Location*, *Min qty*, *Unit price*.
  Each method gets its own tier boundaries (Embroidery 1/12/48, Screen 1/24/96).
- Defaults from the brief ship in `etc/config.xml`, so it works right after install. Admin can edit.
- Custom backend model validates on save: price ≥ 0, min qty ≥ 1 integer, no duplicate (location, min qty).
- **Fail closed:** if a selected location has no tier covering the qty, add-to-cart is rejected
  ("Decoration pricing is not configured for …"). It is never silently charged $0.
- Scope: default + website. Prices are in **base currency**, and Magento converts for display as with any price.
- Methods and locations are fixed lists (source models). They are injectable via `di.xml` arguments,
  so a project can extend them without editing the module.

Rejected: custom DB table + admin UI grid. It would be nicer for large matrices but it is 1–2 h of UI-component
work that doesn't fit the timebox. It is noted as a future improvement.

### 2.4 Pricing core: pure PHP, no Magento dependencies
- `Model/Selection` – immutable value object (method, locations[], note).
- `Model/SelectionValidator` – rules:
  - method must be known;
  - method ≠ none ⇒ ≥ 1 location;
  - ≤ 3 locations, no duplicates, all known;
  - not both Upper back + Full back;
  - note ≤ 255 chars, stripped of tags;
  - method = none ⇒ locations/note ignored (normalised to "no decoration", no option stored).
- `Model/FeeCalculator::calculate(Selection, float $qty): FeeResult` returns the per-location unit price,
  the unit fee and the line fee. It depends only on `PriceTableProviderInterface`, so it is unit-testable with arrays.
- `Model/Config/PriceTableProvider` – reads/parses the config rows (the only Magento-aware part).

The price plugin, the cart display and the order snapshot all call the same calculator, giving one source of truth.

### 2.5 Display and persistence
- **Quote (cart, minicart, checkout summary):** `after` plugin on
  `Magento\Catalog\Helper\Product\Configuration::getCustomOptions`. The simple and configurable
  configuration helpers, the minicart customer-data and checkout `ItemConverter` all go through it.
  The breakdown is computed live from the current qty, so it never goes stale.
- **Order:** `after` plugin on `Magento\Quote\Model\Quote\Item\ToOrderItem::convert` writes a **frozen snapshot**
  into the order item's `product_options`:
  - `inkthread_decoration` – structured: method, locations with unit prices, note, unit fee, line fee, qty;
  - `additional_options` – label/value lines. Core renders these in admin order view, customer order view,
    emails, invoices and PDFs with no templates overridden.
  Snapshotting means later admin price changes never rewrite historical orders.
- No new DB columns. Trade-off: the fee is not SQL-queryable for reporting (noted in the README).
- The snapshot also records the tier qty and the line qty (they differ when lines pooled).
- Example breakdown lines:
  `Decoration: Embroidery` · `Left chest: $3.25 / unit` · `Sleeve: $2.75 / unit` ·
  `Decoration fee: $6.00 / unit ($36.00 for 6; 12+ tier from 12 matching units in cart)` · `Note: …`

### 2.6 Product page UI
- Block + ViewModel + template, added to the add-to-cart form via `catalog_product_view.xml`
  so the fields post in the buy request. Works for simple and configurable products (Luma).
- Prefills from `getPreconfiguredValues()` so cart "Edit" shows the current selection.
- Client-side validation via a `mage/validation` rule mixin (same rules as the server, server is authoritative).
  Locations are disabled when method = None, and a message is shown for back conflict / >3.
- **CSP-safe:** no inline JS, only `x-magento-init`. (This sandbox has `Yireo_DisableCsp` enabled, and a clean
  install won't, so this must be checked with CSP on.)
- All output escaped (the note is user input → XSS).
- Which products show it: a `Yes/No` product attribute `inkthread_decoration_enabled` (data patch)
  set on sample-data tees/hoodies. *(Open question §8.3. If the answer is "all products", drop the attribute.)*

### 2.7 Guests and logged-in customers
No special code: everything hangs off quote items. Verify the guest → login quote merge keeps
lines and options, and that reorder works (the buy request is stored on the order item).

## 3. Module layout

```
app/code/InkThread/DecorationPricing/
  registration.php, composer.json, README.md, PLAN.md, AGENT_NOTES.md
  etc/module.xml, di.xml, config.xml, acl.xml, adminhtml/system.xml, events.xml (frontend+adminhtml)
  Api/PriceTableProviderInterface.php
  Model/Selection.php, SelectionFactory.php (from buy request / JSON), SelectionValidator.php
  Model/FeeCalculator.php, FeeResult.php, TierQuantityResolver.php
  Observer/StampTierQuantity.php
  Model/Source/DecorationMethod.php, PrintLocation.php
  Model/Config/PriceTableProvider.php, Backend/PriceTable.php, Block/Adminhtml/PriceTableField.php
  Observer/CaptureDecorationSelection.php
  Plugin/AddDecorationFeeToFinalPrice.php, AddDecorationToItemOptions.php, CopyDecorationToOrderItem.php
  Setup/Patch/Data/AddDecorationEnabledAttribute.php
  ViewModel/DecorationOptions.php
  view/frontend/layout/catalog_product_view.xml, templates/product/decoration.phtml,
  view/frontend/web/js/decoration-options.js, requirejs-config.js
  i18n/en_US.csv
  Test/Unit/Model/FeeCalculatorTest.php, SelectionValidatorTest.php, PriceTableProviderTest.php
```
Delivered as its own git repo (`inkthread/module-decoration-pricing`), installable via `app/code` or composer path repo.

## 4. Build order (timeboxed, ~4 h)

| # | Step | Est. | Commit |
|---|---|---|---|
| 1 | Skeleton, sources, config.xml defaults, system.xml + backend validation | 30m | `Add module skeleton and admin price tables` |
| 2 | Selection, validator, calculator, provider + **unit tests first** | 60m | `Add fee calculator and selection rules with tests` |
| 3 | Prepare observer, tier-qty resolver + stamping observer, final price plugin; verify simple & configurable totals | 55m | `Price decoration into cart items` |
| 4 | Cart breakdown plugin + order snapshot plugin | 30m | `Show breakdown in cart and persist to order` |
| 5 | PDP block/template/JS validation, edit-prefill, attribute patch | 45m | `Add product page decoration options` |
| 6 | QA pass (§6), static checks (§5), README + agent notes | 35m | `Docs and QA fixes` |

If time runs short, cut in this order: attribute patch → client-side JS validation (server rules remain) →
admin backend validation. Pricing, persistence and tests are never cut.

## 5. Checks run on every step

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/InkThread/DecorationPricing/Test/Unit
vendor/bin/phpcs --standard=Magento2 --extensions=php,phtml app/code/InkThread/DecorationPricing
vendor/bin/phpstan analyse -l 6 app/code/InkThread/DecorationPricing
bin/magento setup:upgrade && bin/magento setup:di:compile   # catches DI/plugin signature errors
```
Outputs are saved to `docs/checks/` for the deliverable.

## 6. Unit test matrix and manual QA

**Unit (FeeCalculator / SelectionValidator):**
- Worked examples: Embroidery 12 × (Left chest + Sleeve) = **$72.00**; Screen 100 × Full back = **$250.00**.
- Tier boundaries: embroidery 11/12, 47/48; screen 23/24, 95/96. Also qty 1 and a very large qty.
- Multi-location sums (2 and 3 locations); Left + Right chest together = 2 × chest price.
- Method None ⇒ fee 0 and no option; unknown method/location rejected.
- Rules: none selected with method ≠ None; 4 locations; duplicates; Upper + Full back; note too long.
- Missing tier / unconfigured location ⇒ exception (fail closed).
- Config parsing: unordered rows, string numbers, empty config.

**Unit (TierQuantityResolver):**
- 6 S + 6 M of the same configurable, same decoration ⇒ both lines tier qty 12 (client example).
- Pooled boundaries: 6 + 5 = 11 vs 6 + 6 = 12 (embroidery). 23/24 and 95/96 split across lines (screen).
- Same product with a different method or locations ⇒ not pooled. Different products with the same decoration ⇒ not pooled.
- Location order irrelevant (L chest + Sleeve == Sleeve + L chest). A different note is still pooled.
- Method None and deleted items are excluded. A single line ⇒ its own qty.

**Manual QA (browser, recorded):**
1. Guest, simple product, Embroidery L chest + Sleeve, qty 12 → line = base×12 + $72.00.
2. Change qty in cart 12 → 11 → 48 → 47: fee re-tiers each time and the breakdown text updates.
3. Same product, a different decoration → separate cart line.
4. Configurable tee (choose size/colour) → fee counted once and shown on the parent line.
4b. Same tee + same decoration: 6 × S and 6 × M → both lines at the 12+ tier. Change M to 5 → both drop to 1–11.
    Remove the S line → M re-tiers alone.
5. Edit item in cart → form prefilled → switch to Screen print Full back → re-priced.
6. Invalid combos on the PDP (no location, 4 locations, Upper + Full back) → message; also bypass the JS (curl) → server rejects.
7. Place order as guest and as a logged-in customer → breakdown in success/customer order view, admin order view, email (Mailhog).
8. Change admin prices after ordering → old order unchanged; new cart uses new prices.
9. Login with a guest cart → lines and decorations merged intact.

## 7. Upgrade risk (for README)
Touch points: `Product\Type\Price::getFinalPrice`, `catalog_product_type_prepare_*_options` event,
`Catalog\Helper\Product\Configuration::getCustomOptions`, `Quote\Item\ToOrderItem::convert`,
`additional_options` rendering, and PDP layout container names. All have been stable since 2.0 and are
the same hooks core's own custom options use. The PDP layout is the most fragile (Luma-specific,
**Hyvä not supported**). Unit tests plus `di:compile` will catch signature changes.

## 8. Clarifying questions to send before starting (one round)
1. ~~**Tier quantity basis.**~~ **Answered:** the client says 6 S + 6 M should get the 12+ tier → pool matching lines (§2.0).
2. **Tax and discounts.** Should the fee be taxed like the garment, and should %-off cart rules discount it?
   *(Default: yes to both, as a consequence of putting it in item price.)*
3. **Which products** get the options: all, or only flagged ones? *(Default: Yes/No attribute.)*
4. **Note**: required, max length, only when a method is chosen? *(Default: optional, 255 chars, ignored for None.)*
5. **Left + Right chest** together is allowed and charged twice? *(Default: yes.)*
6. **Scope**: is global/website-level pricing enough, or are per-store prices needed? Multi-currency?
7. **Frontend**: Luma only, or Hyvä / headless? Must REST/GraphQL add-to-cart support decorations?
   *(Default: Luma storefront only. API out of scope.)*

## 9. Out of scope / known limitations (initial)
- REST / GraphQL add-to-cart with decorations.
- Hyvä / PWA themes.
- Bundle products (simple + configurable + grouped children only).
- Live fee preview on the product page (not required. Would duplicate pricing logic in JS or need an endpoint).
- Integration/MFTF tests (unit + scripted manual QA instead, timebox).
