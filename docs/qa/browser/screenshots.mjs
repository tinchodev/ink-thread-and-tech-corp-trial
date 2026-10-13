// Documentation screenshots on the Chaz Kangeroo Hoodie (MH01, id 62, $52), headless Chrome.
// Requires the decoration flag on MH01 (sample data). Usage: QA_OUT=docs/screenshots node docs/qa/browser/screenshots.mjs
import { launch } from './cdp.mjs';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';

const BASE = 'https://magento.test';
const OUT = process.env.QA_OUT || '.';
const PID = 62;
const COLOR = { black: 49, gray: 52 };
const SIZE = { m: 168, l: 169 };

const FIXTURES = mkdtempSync(join(process.env.QA_TMP || tmpdir(), 'shots-'));
const PNG = join(FIXTURES, 'acme-logo.png');
const PDF = join(FIXTURES, 'team-back.pdf');
writeFileSync(PNG, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64'));
writeFileSync(PDF, '%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n');

const b = await launch({ port: 9337, width: 1280, height: 1100 });
const $ = (js) => b.evaluate(`(function () { var $ = require('jquery'), root = $('#inkthread-decoration-${PID}'); ${js} })()`);

const openHoodie = async () => {
    await b.goto(`${BASE}/chaz-kangeroo-hoodie.html`);
    await b.waitFor(`window.require && require.defined && require.defined('jquery')
        && !!require('jquery')('#inkthread-decoration-${PID}').data('inkthreadDecorationOptions')
        && !!document.querySelector('.swatch-option[data-option-id="${SIZE.m}"]')`);
};
// Swatches toggle: only click an option that is not already selected.
const pick = (color, size, qty) => b.evaluate(`[${color}, ${size}].forEach(function (id) {
        var el = document.querySelector('.swatch-option[data-option-id="' + id + '"]');
        if (!el.classList.contains('selected')) { el.click(); }
    });
    document.getElementById('qty').value = ${qty}; true`);
const decorate = (method, locations) => $(`root.find('[data-role=decoration-method]').val('${method}').trigger('change');
    root.find('input[type=checkbox]:checked').each(function () { this.click(); });
    ${JSON.stringify(locations)}.forEach(function (l) { root.find('input[value=' + l + ']')[0].click(); }); return true;`);
const text = (loc, value) => $(`root.find('#inkthread-decoration-${PID}-details-${loc}-text').val(${JSON.stringify(value)}); return true;`);
const note = (value) => $(`root.find('[data-role=decoration-note]').val(${JSON.stringify(value)}); return true;`);
const file = (loc, path) => b.setFiles(`#inkthread-decoration-${PID}-details-${loc}-file`, [path]);
const galleryLoaded = () => b.waitFor(`(function () {
    var img = document.querySelector('.fotorama__stage .fotorama__active img');
    return !!img && img.complete && img.naturalWidth > 0;
})()`, 20000);
const scrollTo = (selector, offset = 120) => b.evaluate(`window.scrollTo(0, document.querySelector('${selector}').getBoundingClientRect().top + window.scrollY - ${offset}); true`);
const addToCart = async () => { await b.evaluate(`document.getElementById('product-addtocart-button').click(); true`); await b.sleep(3500); };

// 1. Embroidery: text per location; Upper back blocks Full back.
await openHoodie();
await pick(COLOR.black, SIZE.m, 6);
await decorate('embroidery', ['left_chest', 'upper_back']);
await text('left_chest', 'ACME');
await text('upper_back', 'TEAM 2026');
await note('White thread please');
await galleryLoaded();
await scrollTo('.product-info-main .page-title-wrapper', 20);
await b.screenshot(`${OUT}/hoodie-pdp-embroidery.png`);
await addToCart();
await pick(COLOR.black, SIZE.l, 6);
await addToCart(); // same decoration on another size: pooled into the 12+ tier

// 2. Screen Print: artwork per location.
await openHoodie();
await pick(COLOR.gray, SIZE.l, 12);
await decorate('screen_print', ['left_chest', 'full_back']);
await file('left_chest', PNG);
await file('full_back', PDF);
await galleryLoaded();
await scrollTo('.product-info-main .page-title-wrapper', 20);
await b.screenshot(`${OUT}/hoodie-pdp-screen-print.png`);
await addToCart();

// 3. Cart page.
await b.goto(`${BASE}/checkout/cart/`);
await scrollTo('#shopping-cart-table', 20);
await b.screenshot(`${OUT}/hoodie-cart.png`);

// 4. Checkout summary with item details expanded.
await b.goto(`${BASE}/checkout/`, `!!document.querySelector('.opc-block-summary .items-in-cart')`);
await b.evaluate(`document.querySelector('.opc-block-summary .items-in-cart .title').click(); true`);
await b.waitFor(`!!document.querySelector('.opc-block-summary .product-item .item-options')`);
// Expand the first item's details only, list scrolled to the top.
await b.evaluate(`document.querySelector('.opc-block-summary .product-item .options .toggle').click();
    document.querySelector('.opc-block-summary .minicart-items-wrapper').scrollTop = 0; true`);
await b.sleep(800);
await scrollTo('.opc-block-summary', 20);
await b.screenshot(`${OUT}/hoodie-checkout-summary.png`);

const lines = await b.evaluate(`fetch('/customer/section/load/?sections=cart&force_new_section_timestamp=true').then(r => r.json())
    .then(d => d.cart.items.map(i => i.product_sku + ' x' + i.qty + ' @ ' + i.product_price_value))`);
console.log('cart:', lines.join(' | '));
console.log('console errors:', b.consoleErrors.length);
await b.close();
