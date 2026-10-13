// Browser QA for the product page decoration fieldset (guest, headless Chrome via the DevTools protocol, no deps).
// Requires: Node 22+, google-chrome, sample data with the decoration flag on 24-MB01 (id 1) and MS09 (id 478).
// Usage: QA_OUT=docs/screenshots node docs/qa/browser/pdp-test.mjs
import { launch } from './cdp.mjs';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';

// Artwork fixtures
const FIXTURES = mkdtempSync(join(process.env.QA_TMP || tmpdir(), 'artwork-'));
const PNG = join(FIXTURES, 'logo.png');
const SVG = join(FIXTURES, 'logo.svg');
writeFileSync(PNG, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64'));
writeFileSync(SVG, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

const BASE = 'https://magento.test';
const OUT = process.env.QA_OUT || '.';
const results = [];
const check = (name, ok, detail = '') => {
    results.push(ok);
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  -- ' + detail : ''}`);
};

const b = await launch({ port: 9333 });

// In-page helpers for the decoration fieldset of a given product id.
const helpers = (pid) => `
    window.__d = (function () {
        var $ = require('jquery'), root = $('#inkthread-decoration-${pid}');
        return {
            ready: function () { return !!root.data('inkthreadDecorationOptions'); },
            method: function (v) { root.find('[data-role=decoration-method]').val(v).trigger('change'); },
            click: function (loc) { root.find('input[value=' + loc + ']')[0].click(); },
            note: function (v) { root.find('[data-role=decoration-note]').val(v); },
            state: function () {
                var s = {disabled: {}, checked: []};
                root.find('input[type=checkbox]').each(function () {
                    s.disabled[this.value] = this.disabled;
                    if (this.checked) { s.checked.push(this.value); }
                });
                s.reasons = {};
                root.find('[data-role=decoration-reason]').each(function () {
                    var v = $(this).closest('.field.choice').find('input').val();
                    if ($(this).text()) { s.reasons[v] = $(this).text(); }
                });
                s.banners = root.find('.message:visible').length;
                s.detailRows = root.find('[data-role=decoration-detail]:visible').map(function () {
                    return this.getAttribute('data-location');
                }).get();
                s.noteDisabled = root.find('[data-role=decoration-note]').prop('disabled');
                s.errors = root.find('.mage-error:visible').map(function () { return $(this).text(); }).get();
                return s;
            }
        };
    })(); true`;

const state = async () => b.evaluate('__d.state()');
const cart = async () => b.evaluate(`fetch('/customer/section/load/?sections=cart&force_new_section_timestamp=true')
    .then(r => r.json()).then(d => d.cart.items.map(i => ({sku: i.product_sku, qty: i.qty, price: i.product_price_value,
        options: i.options.map(o => o.label + ': ' + o.value)})))`);
const submit = async () => {
    await b.evaluate(`document.getElementById('product-addtocart-button').click(); true`);
    await b.sleep(3000);
};

// ---- A. Simple product: Joust Duffle Bag (id 1, $34) ----
await b.goto(`${BASE}/joust-duffle-bag.html`);
await b.waitFor(`window.require && require.defined && require.defined('jquery') && !!document.getElementById('inkthread-decoration-1')`);
await b.evaluate(helpers(1));
await b.waitFor('__d.ready()');

let s = await state();
check('A1 method None: all locations and note disabled',
    Object.values(s.disabled).every(Boolean) && s.noteDisabled, JSON.stringify(s.disabled));

await b.evaluate(`__d.method('embroidery')`);
s = await state();
check('A2 Embroidery: all locations enabled, note enabled',
    Object.values(s.disabled).every((d) => !d) && !s.noteDisabled);

await b.evaluate(`__d.click('upper_back')`);
s = await state();
check('A3 Upper back checked: Full back disabled, reason shown inline, no banner',
    s.disabled.full_back && s.reasons.full_back === '(not with Upper back)' && Object.keys(s.reasons).length === 1 && s.banners === 0,
    JSON.stringify(s.reasons));

await b.evaluate(`__d.click('left_chest'); __d.click('sleeve')`);
s = await state();
check('A4 Three checked: remaining location disabled, no extra message',
    s.checked.length === 3 && s.disabled.right_chest && !s.reasons.right_chest && s.banners === 0, JSON.stringify(s.reasons));
await b.screenshot(`${OUT}/pdp-embroidery-three-locations.png`);

await b.evaluate(`__d.click('upper_back'); __d.click('left_chest'); __d.click('sleeve'); __d.method('screen_print')`);
s = await state();
check('A5 Unchecked all: Full back enabled again, no reasons', !s.disabled.full_back && Object.keys(s.reasons).length === 0 && s.checked.length === 0);

await submit();
s = await state();
let items = await cart();
check('A6 Submit with method but no location: client error, nothing added',
    s.errors.some((e) => /at least one print location/.test(e)) && items.length === 0, JSON.stringify(s.errors));

await b.evaluate(`__d.click('full_back'); __d.note('size < 10cm'); document.getElementById('qty').value = 12; true`);
await submit();
s = await state();
items = await cart();
check('A7 Note with "<": client error, nothing added',
    s.errors.some((e) => /cannot contain the characters/.test(e)) && items.length === 0, JSON.stringify(s.errors));

await b.evaluate(`__d.note('Logo v2 & "white"'); true`);
await submit();
items = await cart();
const bag = items.find((i) => i.sku === '24-MB01');
check('A8 Valid submit: added at $34 + $4.00 (screen print full back, 12 units)',
    bag && bag.qty === 12 && Math.abs(bag.price - 38) < 0.001, JSON.stringify(bag));

await b.evaluate(`__d.method('none')`);
s = await state();
check('A9 Back to None: locations cleared and disabled', s.checked.length === 0 && Object.values(s.disabled).every(Boolean));

// ---- A10-A12. Per-location text and artwork (extension) ----
await b.evaluate(`__d.method('embroidery'); __d.click('left_chest'); __d.click('full_back'); true`);
s = await state();
check('A10 Detail rows shown only for checked locations', JSON.stringify(s.detailRows) === '["left_chest","full_back"]',
    JSON.stringify(s.detailRows));

await b.setFiles('#inkthread-decoration-1-details-left_chest-file', [SVG]);
await submit();
s = await state();
items = await cart();
check('A11 SVG artwork: client error, nothing new added',
    s.errors.some((e) => /PNG, JPG or PDF/.test(e)) && items.length === 1, JSON.stringify(s.errors));

await b.setFiles('#inkthread-decoration-1-details-left_chest-file', [PNG]);
await b.evaluate(`require('jquery')('#inkthread-decoration-1-details-left_chest-text').val('ACME');
    require('jquery')('#inkthread-decoration-1-details-full_back-text').val('TEAM 2026');
    require('jquery')('#inkthread-decoration-1 [data-role=decoration-note]').val(''); document.getElementById('qty').value = 3; true`);
await submit();
items = await cart();
const art = items.find((i) => i.options.some((o) => /Artwork: logo\.png/.test(o)));
check('A12 Upload through the real form: text + artwork on the cart line',
    art && art.options.some((o) => o === 'Left chest: $4.00 / unit · Text: "ACME" · Artwork: logo.png')
        && art.options.some((o) => o === 'Full back: $7.50 / unit · Text: "TEAM 2026"'), JSON.stringify(art));
await b.evaluate(`document.getElementById('inkthread-decoration-1').scrollIntoView({block: 'center'}); true`);
await b.screenshot(`${OUT}/pdp-location-text-artwork.png`);

// ---- B. Configurable product: Ryker Tee (id 478, $32), Black / M ----
await b.goto(`${BASE}/ryker-lumatech-trade-tee-crew-neck.html`);
await b.waitFor(`window.require && require.defined && require.defined('jquery') && !!document.querySelector('.swatch-option[data-option-id="168"]')`);
await b.evaluate(helpers(478));
await b.waitFor('__d.ready()');
await b.evaluate(`document.querySelector('.swatch-option[data-option-id="49"]').click();
    document.querySelector('.swatch-option[data-option-id="168"]').click();
    __d.method('embroidery'); __d.click('left_chest'); __d.click('sleeve');
    document.getElementById('qty').value = 6; true`);
await b.screenshot(`${OUT}/pdp-configurable-decorated.png`);
await submit();
items = await cart();
const tee = items.find((i) => i.sku === 'MS09-M-Black');
check('B1 Configurable Black/M x6 embroidery L chest + sleeve: $32 + $7.50',
    tee && tee.qty === 6 && Math.abs(tee.price - 39.5) < 0.001, JSON.stringify(tee));

// ---- C. Cart and checkout pages render the breakdown ----
await b.goto(`${BASE}/checkout/cart/`);
const cartText = await b.evaluate(`document.querySelector('#shopping-cart-table').innerText`);
const decoratedLines = (await cart()).filter((i) => i.options.some((o) => /^Decoration fee/.test(o))).length;
check(`C1 Cart page shows the breakdown for every decorated line (${decoratedLines})`,
    decoratedLines === 3 && (cartText.match(/Decoration fee/g) || []).length === decoratedLines
        && /Decoration note\s*Logo v2 & "white"/.test(cartText) && /Text: "ACME" · Artwork: logo\.png/.test(cartText));
await b.screenshot(`${OUT}/cart-breakdown.png`);

await b.goto(`${BASE}/checkout/`, `!!document.querySelector('.opc-block-summary .items-in-cart')`);
await b.evaluate(`document.querySelector('.opc-block-summary .items-in-cart .title').click(); true`);
await b.waitFor(`!!document.querySelector('.opc-block-summary .product-item .item-options')`);
await b.evaluate(`document.querySelectorAll('.opc-block-summary .product-item .options .toggle').forEach(t => t.click()); true`);
await b.sleep(500);
const summary = await b.evaluate(`document.querySelector('.opc-block-summary').innerText`);
check('C2 Checkout summary shows decoration lines', /Decoration fee/.test(summary) && /Screen print/.test(summary), summary.replace(/\s+/g, ' ').slice(0, 300));
await b.screenshot(`${OUT}/checkout-summary.png`);

// ---- D. Console / CSP ----
const csp = await b.evaluate('window.__csp || []');
const ours = b.consoleErrors.filter((e) => /inkthread|decoration/i.test(e));
check('D1 No console errors from the module', ours.length === 0, ours.join(' | '));
console.log(`INFO  all console errors on visited pages: ${b.consoleErrors.length}`);
b.consoleErrors.slice(0, 5).forEach((e) => console.log('      ' + e.slice(0, 200)));
console.log(`INFO  CSP violations on last page: ${csp.length}`);
csp.slice(0, 5).forEach((e) => console.log('      ' + e));

await b.close();
console.log(`\n${results.filter(Boolean).length}/${results.length} passed`);
process.exit(results.every(Boolean) ? 0 : 1);
