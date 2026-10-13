# InkThread_DecorationPricing

Lets a customer choose a decoration method (None / Embroidery / Screen print), 1–3 print locations and an optional
note on a product, and prices the decoration per unit by method, location and quantity tier. Prices are set in admin.
The decoration and a per-location breakdown carry from cart to checkout to the placed order.

**Beyond the brief (requested extension):** each selected location can also have its own **text to decorate**
(≤ 100 chars) and an **artwork file** (PNG, JPG or PDF, ≤ 10 MB). Both are optional and don't change the fee. Files
are listed per item/location with a download link on the admin order view.

Tested on Magento Open Source **2.4.7-p9**, PHP **8.3**, Luma, with sample data.

## Install

```bash
# 1. Put the module in place (this repository is the module root)
git clone git@github.com:tinchodev/ink-thread-and-tech-corp-trial.git app/code/InkThread/DecorationPricing
#    or as a composer path repository: "inkthread/module-decoration-pricing"

# 2. Enable and install
bin/magento module:enable InkThread_DecorationPricing
bin/magento setup:upgrade
bin/magento setup:di:compile                                   # production mode only
bin/magento setup:static-content:deploy -f en_US               # production mode, or whenever static files are not served on demand
bin/magento cache:flush
```

3. **Flag products:** Catalog > Products > select products > Actions > Update attributes > **Allow Decoration = Yes**.
   For configurable products, set it on the parent. Only flagged products show the options.
4. **Prices:** Stores > Configuration > Catalog > **Decoration Pricing**. The brief's tables ship as defaults.

The attribute patch is revertable (`bin/magento module:uninstall` for a composer install removes it).

## How pricing works

* Per-unit fee = sum of each selected location's price at the quantity tier. No setup fees. The fee is added to the
  item's unit price, so `row total = (product price + fee) × qty`.
* Tier = the highest "From quantity" row that is ≤ the tier quantity.
* **Tier quantity (client decision, differs from the brief):** cart lines with the **same product, method and set of
  locations** are pooled. 6 × S + 6 × M of one tee with the same decoration are both priced at the 12+ tier.
  Location order and the note do not split a pool. Different products never pool. A qty change or removal re-prices
  the sibling lines.
* Left chest + Right chest together are charged as two locations.
* Tax applies to garment + decoration (the fee is part of the item price). %-off cart rules discount the fee as well.
* **Fails closed:** if a location has no price for the method, or the qty is below its lowest tier, add to cart is
  refused (or the line gets an error that blocks checkout). It is never charged $0.

Brief worked examples, verified in unit tests and on the storefront: Embroidery, 12 units, Left chest + Sleeve =
($3.25 + $2.75) × 12 = **$72.00**. Screen print, 100 units, Full back = $2.50 × 100 = **$250.00**.

## Design choices

| Concern | Extension point | Why / rejected alternatives |
|---|---|---|
| Fee in price | `after` plugin on `Catalog\Model\Product\Type\Price::getFinalPrice` (inherited by the configurable price model) | The same place core custom options add their price. Every totals collection calls it, so cart qty changes re-tier with no extra code. Rejected: `setCustomPrice` (clobbers admin custom price and catalog rules), a separate quote/order total (invoice/credit memo/refund work, harder per-line breakdown), the `catalog_product_get_final_price` event (fires with the configurable *child*, which lacks the parent's options). |
| Tier pooling | observer `sales_quote_collect_totals_before` → `Model\Quote\ItemFeeProvider` | Computes pooled tiers once per totals run and keeps each line's `FeeResult` **on the quote item**. Not on the product: lines of the same product share one product instance. |
| Capture + validate | observer `catalog_product_type_prepare_full_options` / `_lite_options` | Runs wherever core prepares a product for the cart: add to cart and cart edit (verified), reorder and wishlist (same path, untested). Rejects unflagged products and broken rules with a clear message. Stores the selection as a quote item option, so differently decorated lines stay separate. Skips the configurable child pass, which shares the parent's buy request. |
| Cart / minicart / checkout display | `after` plugin on `Catalog\Helper\Product\Configuration::getCustomOptions` | One helper feeds all three (the configurable helper delegates to it). Computed live, so it follows qty changes. |
| Order persistence | `after` plugin on `Quote\Model\Quote\Item\ToOrderItem::convert` | Frozen snapshot in `product_options`: `inkthread_decoration` (structured) plus `additional_options` (display lines). Core renders those in admin/customer/guest order views, emails, invoices, credit memos and PDFs, with no template overrides. Later price changes never alter placed orders. No new DB columns. Trade-off: the fee isn't SQL-queryable for reports. |
| Edit prefill | `after` plugin on `Catalog\Model\Product::processBuyRequest` | Core drops non-core buy request keys from the preconfigured values. |
| Admin prices | `system.xml` dynamic rows (`ArraySerialized`) with a validating backend model | One table per method, own tier boundaries, defaults in `config.xml`. Saving or resetting flags active quotes `trigger_recollect` (as core does for catalog price changes), so open carts never show a stale unit price. Rejected: a custom table + admin grid (better for big matrices, too much UI work for the timebox). Scope is global (single store, client decision). |
| Artwork upload | `Model/Artwork/*`, called from the capture observer | Flat file inputs `inkthread_artwork_<location>` in the add-to-cart form (Luma's AJAX submit already posts `FormData`; the widget sets `multipart/form-data` for a non-AJAX submit). `UploaderFactory` stores the file under `var/inkthread_decoration/artwork/` with a random name, outside the web root. The validator checks size, extension, and the *real* MIME type via `finfo`, so an HTML file renamed `.png` is refused. SVG isn't accepted (scriptable). |
| Keeping artwork on edit / reorder | encrypted token in the buy request (`ArtworkToken`, Magento encryptor = libsodium AEAD) | The observer writes the stored file back into the buy request as a token before core serialises `info_buyRequest`. The edit page posts it back (with a "Remove" option), and reorder reuses it. A tampered or foreign token is ignored; stored paths are only accepted inside the artwork directory. Request data can never set a path directly (`SelectionFactory::createFromRequestData` vs `createFromStored`). |
| Artwork download (admin) | `adminhtml` route + `Controller/Adminhtml/Artwork/Download` (ACL `Magento_Sales::actions_view`), block in `order_additional_info` | Served as `application/octet-stream` attachment, never inline. Unknown item/location goes back to the order with an error. |
| Pricing core | `Selection`, `SelectionValidator`, `FeeCalculator`, `TierQuantityResolver`, `PriceTableParser` | Plain service classes with no app state, unit-tested in isolation. Plugins and observers are thin adapters around them. |
| Product page | layout + template + view model + jQuery widget | Fieldset in the add-to-cart form, added to both form containers (with/without product options). The widget disables impossible choices and says why inline. Client validation for UX only; the server is authoritative. CSP-safe: module JS file + `x-magento-init` only, no inline script. |

### Security

The note and per-location texts are the free-text inputs. Core passes non-native item option values unescaped to the
checkout summary, which renders them with a knockout `html` binding. So texts containing `<` or `>` are refused at
input (server and client). Other characters are allowed and are escaped by core on the pages that escape. Verified with
an `<img onerror>` payload. Uploaded file names are reduced to `[A-Za-z0-9 ._-]` for display. The stored file name is
random. Uploads are content-checked, stored outside the web root, and only downloadable by admins with order-view
permission.

## Checks

| Check | Command | Output |
|---|---|---|
| Unit tests (132) | `vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/InkThread/DecorationPricing/Test/Unit` | `docs/checks/phpunit.txt` |
| Magento coding standard | `vendor/bin/phpcs --standard=Magento2 --extensions=php,phtml,xml,js,less app/code/InkThread/DecorationPricing` | `docs/checks/phpcs.txt` |
| Static analysis | `vendor/bin/phpstan analyse -l 6 -a app/autoload.php app/code/InkThread/DecorationPricing` | `docs/checks/phpstan.txt` |
| DI compile | `bin/magento setup:di:compile` | `docs/checks/di-compile.txt` |
| Storefront QA (42 assertions, curl, incl. multipart uploads) | `docs/qa/storefront-qa.sh` | `docs/checks/storefront-qa.txt` |
| Browser QA (16 checks, headless Chrome, incl. a real file upload) | `node docs/qa/browser/pdp-test.mjs` | `docs/checks/browser-qa.txt`, `docs/screenshots/` |

Unit tests cover both worked examples, every tier boundary (11/12, 47/48, 23/24, 95/96), pooled boundaries across
lines (6+5 vs 6+6 …), multi-location sums, all location rules, per-location text rules,
artwork validation against real PNG/JPEG/PDF/SVG/HTML bytes, file-name sanitising, and artwork tokens against
tampering with Magento's real encryptor. Their prices are read from the module's own
`etc/config.xml`, so they also prove the shipped defaults match the brief. The tests were checked against deliberate
breakage (tier comparison, back-conflict rule, pooling key): each mutation fails them.

Also verified by hand: admin artwork download (attachment, byte-identical to the upload; a logged-out request gets
the login page), guest and logged-in orders, guest-cart merge on login, admin and customer order views, guest
order lookup, confirmation email, invoice, credit memo, invoice PDF, admin price change after ordering, and a
sample-data cart rule discounting the fee.

## What could break on a Magento upgrade, and why I think it won't

* **Touch points:** the 4 `after` plugins and 3 events above, `Framework\File\Uploader`, the encryptor, the
  `order_additional_info` admin container, `additional_options` rendering in sales templates,
  `AbstractFieldArray` / `ArraySerialized` in admin, the quote `trigger_recollect` column, and two Luma layout
  containers (`product.info.form.content`, `product.info.options.wrapper`).
* **Why it should hold:** these are the same hooks core's own custom options and configurable products use, and they
  have kept their signatures since 2.0. There are no preferences or rewrites of core classes, no `around` plugins and
  no template overrides, so a core change can't be silently swallowed by a copied method.
* **Most fragile:** the product page layout container names (Luma-specific; Hyvä/PWA don't use them) and the
  configurable price model still extending `Product\Type\Price`.
* **How to know:** after an upgrade, run `setup:di:compile` (signature changes), the unit tests, and the two QA scripts.
  Storefront scenario S6 fails if configurable pricing or pooling stops going through the plugin.

## Unfinished / known limitations

* REST / GraphQL add to cart with a decoration (the fields aren't part of the API cart item). Headless and Hyvä are out of scope (client: Luma only).
* Admin "Create order": the admin product configuration popup has no decoration fields (reorder of a decorated order passes the stored buy request but is untested).
* Multi-address checkout, bundle products, grouped products, wishlist with decoration: untested or unsupported.
* Per-website/store pricing and multi-currency (single store by client decision; prices are base currency).
* No integration/MFTF tests. The Magento-facing adapters (observers, plugins) are covered by the scripted storefront and browser QA, not unit tests.
* Admin UX is plain dynamic rows; a location × tier matrix grid would be easier to maintain.
* The fee is not in its own order column, so reporting needs the JSON snapshot.
* Artwork: no customer-side download link; no cleanup of files from abandoned carts (needs a cron); stored on local
  `var/`, so multi-node or remote storage (S3) setups need a shared or remote filesystem. Files are not virus-scanned.

## Sandbox notes (not module issues)

In the trial sandbox, PHP-FPM forces `MAGE_MODE=production` for web requests, so static files must be deployed from
the CLI after frontend changes. With `Yireo_DisableCsp` disabled, Magento's ~4 KB CSP header exceeds the TLS
nginx's proxy buffer (502). Neither affects a clean install.
