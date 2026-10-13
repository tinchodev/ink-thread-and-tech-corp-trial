# Agent notes

> **DRAFT written by the agent from the session log. To be reviewed, cut down and rewritten by Martin.**
> Each entry: what the agent did, who caught it, and what was done. Commits are in this repository.

## Where the agent was wrong

1. **Checkout summary XSS from its own design decision.** In step 2 the agent overrode the plan ("note stripped of
   tags") and decided to store the note raw and rely on output escaping. In step 4 it found that core does *not*
   escape non-native item options for the checkout summary (knockout `html` binding), so an `<img onerror>` note
   was a stored XSS there. *Caught by the agent* while checking how each page escapes option values.
   *Fix:* notes with `<` or `>` are refused at input (server + client), with tests. `6063ef1`

2. **Design bug in the plan: tier qty stored on the product.** The plan said to stamp each item's *product* with
   its pooled tier qty. Quote items of the same product share one product instance, so values would leak between
   lines with different decorations. *Caught by the agent* reading core before coding step 3.
   *Fix:* the fee is kept on the quote item. `8aea6a8`

3. **Cart "Edit" didn't prefill.** The agent assumed the product page's preconfigured values contain the whole
   buy request. Core's `processBuyRequest()` keeps only core keys. *Caught by an explicit prefill check* in step 5.
   *Fix:* `after` plugin on `Product::processBuyRequest`. `5091938`

4. **Location hint UX: a warning banner on a valid selection.** With Left chest + Upper back selected, a yellow
   banner said "Upper back and Full back cannot both be selected", which reads as an error. The agent had approved
   it from its own screenshot. *Caught by Martin* in manual review. The agent's first fix only reworded the banner
   (`114c1f7`). Martin had to ask again whether a message should appear at all before it was replaced by an inline
   reason on the disabled option ("Full back (not with Upper back)"). `2d01026`

5. **Overclaim in the plan.** The plan said methods and locations were "injectable via `di.xml`". Adding a method
   also needs a price-table field in `system.xml`, so that wasn't true. *Caught by the agent* while implementing. It
   wasn't built, and the plan text remains as written.

6. **"Tests first" not followed.** The plan said step 2 would be test-first. The code was written first, then the
   tests. *Mitigated:* the tests were checked against deliberate breakage (tier comparison, exclusive-pair rule,
   pooling key); each mutation fails them.

7. **The agent's own QA gave wrong results several times** (all caught by the agent, but worth knowing):
   - The curl harness captured the cookie-jar path at source time. A whole batch of "rejected as expected" results
     were really invalid-form-key failures. Caught because a valid control request also failed.
   - A "missing order snapshot" was the MySQL CLI escaping a `"` in the note and breaking the JSON parse.
   - A crashed headless Chrome stayed on its debug port. The next run attached to it and reported stale 404s.
     The driver now refuses an occupied port.
   - It ran `bin/magento config:delete`, which doesn't exist in 2.4.7, and briefly left a test price active.
   - It deleted the compiled Luma CSS to force a LESS rebuild, not knowing web requests run in production mode
     in this sandbox (no on-demand static generation). The storefront was unstyled until it redeployed.

8. **Artwork extension (requested after step 6): QA and UI slips, caught by the agent.**
   - The curl edit test posted the artwork token straight from the HTML attribute. `escapeHtmlAttr` encodes base64
     characters as entities, so "keep the file" seemed broken. A browser decodes them; the test now does too.
   - A download check right after login hit the admin's first-request redirect to the dashboard and looked like a
     failure.
   - The admin artwork table showed a 45-byte file as "0.0 KB". Noticed in a screenshot.
   - The download controller first redirected errors to the referer, which for a direct link was the storefront
     home page. It now goes back to the order.
   - Luma only sets `multipart/form-data` on the add-to-cart form for products with native file options. The AJAX
     submit didn't need it, but a non-AJAX submit would have silently dropped files. The widget now sets it.

9. **Built "text and artwork on every location, for both methods" without asking which goes with which.**
   Martin had asked about per-location text (for embroidery) and an image upload (for screen print). The agent
   generalised to both fields everywhere, optional. *Caught by Martin* when he wrote his decisions into the plan.
   Fixed to: embroidery requires text per location, screen print requires artwork per location, only that field
   shown (`c511ba4`, `648bfda`, and the code commit after them).

10. **Test-side slips while making the fields required** (caught by the agent): the browser test assumed the AJAX
    add-to-cart clears the form (it doesn't, so a file from an earlier step was still selected). It also assumed
    `DOM.setFileInputFiles` with an empty list clears an input (it doesn't). The client-side "file required" rule was
    first a custom validator; it was switched to the native `required` attribute, which jQuery Validate always
    evaluates on empty fields. The test bug was found first, so it's unproven whether the custom rule alone would
    have failed.

## Where the agent went beyond the requirement

Kept, but worth a reviewer's judgement:

* **Open carts recollect after a price-table change** (`trigger_recollect`). Not asked for. Added after the agent
  found an open cart showing a new fee in the breakdown next to an old unit price. `6063ef1`
* **Fail-closed messages** for unpriced locations / quantities below the lowest tier, and the product page disabling
  locations without a price table. The brief has no such case with the shipped prices.
* **"(tier for N matching units)"** in the breakdown when lines are pooled.
* **Revertable data patch**, and `@api` on the price-table provider interface.
* **Per-location text and artwork upload**: requested by Martin after the core build (not in the brief), see
  PLAN.md §10.

Sandbox change made by the agent (not in the module): admin secret URL keys turned off
(`admin/security/use_form_key = 0`) to script admin checks.
