#!/usr/bin/env bash
# Storefront QA for InkThread_DecorationPricing: real add-to-cart / cart update / edit requests as a guest (curl),
# asserting unit prices and breakdown text from the cart customer-data section.
#
# Requires Magento sample data with the decoration flag set on 24-MB01 (id 1) and MS09 (id 478), and 24-UG06 (id 15)
# left unflagged. IDs below are the sample-data ones: color 93 (Black 49), size 142 (S 167, M 168).
# Stock: sample data ships 100 units per product; give 24-MB01, MS09-S-Black and MS09-M-Black a large qty (S4 adds 100).
# Usage: BASE=https://magento.test docs/qa/storefront-qa.sh   (needs curl, php, openssl, base64)
set -u
BASE=${BASE:-https://magento.test}
CURL_OPTS=${CURL_OPTS:--sk}
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
PASS=0; FAIL=0
D='inkthread_decoration'; L="$D[locations][]"; SA='super_attribute'

new_session() { JAR="$WORK/jar.$1"; FK=$(openssl rand -hex 8); curl $CURL_OPTS -c "$JAR" -b "$JAR" -o /dev/null "$BASE/"; }
c() { curl $CURL_OPTS -c "$JAR" -b "$JAR" --cookie "form_key=$FK" "$@"; }
post() { local url=$1; shift; local data="form_key=$FK"; for p in "$@"; do data="$data&$p"; done; c -o /dev/null -d "$data" "$BASE$url"; }
add() { local id=$1 qty=$2; shift 2; post /checkout/cart/add/ "product=$id" "qty=$qty" "$@"; }
# cart lines as: item_id|sku|qty|unit|option, option, ...
cart() { c "$BASE/customer/section/load/?sections=cart&force_new_section_timestamp=true" | php -r '
    $d = json_decode(stream_get_contents(STDIN), true);
    foreach ($d["cart"]["items"] ?? [] as $i) {
        $o = array_map(fn ($x) => $x["label"] . ": " . html_entity_decode(strip_tags((string)$x["value"])), $i["options"]);
        printf("%s|%s|%s|%.2f|%s\n", $i["item_id"], $i["product_sku"], $i["qty"], $i["product_price_value"], implode(", ", $o));
    }'; }
line() { cart | awk -F'|' -v s="$1" '$2 == s' | sed -n "${2:-1}p"; }
check() { if [ "$2" = "$3" ]; then PASS=$((PASS + 1)); echo "PASS  $1"; else FAIL=$((FAIL + 1)); echo "FAIL  $1  -- expected [$3] got [$2]"; fi; }
contains() { case "$2" in *"$3"*) PASS=$((PASS + 1)); echo "PASS  $1";; *) FAIL=$((FAIL + 1)); echo "FAIL  $1  -- [$2] lacks [$3]";; esac; }

echo "== S1 brief worked example, simple product (Joust Duffle Bag \$34)"
new_session s1
add 1 12 "$D[method]=embroidery" "$L=left_chest" "$L=sleeve"
l=$(line 24-MB01); check "unit price 34 + 6.00" "$(cut -d'|' -f4 <<<"$l")" "40.00"
contains "breakdown" "$l" "Left chest: \$3.25 / unit, Sleeve: \$2.75 / unit, Decoration fee: \$6.00 / unit × 12 = \$72.00"

echo "== S2 qty changes in cart re-tier at the boundaries"
id=$(cut -d'|' -f1 <<<"$l")
for pair in "11 41.50" "48 38.50" "47 40.00" "12 40.00"; do
    set -- $pair; post /checkout/cart/updatePost/ "cart[$id][qty]=$1" "update_cart_action=update_qty"
    check "qty $1 -> unit $2" "$(line 24-MB01 | cut -d'|' -f4)" "$2"
done

echo "== S3 same product, different decoration = separate line"
add 1 5 "$D[method]=screen_print" "$L=full_back"
check "two bag lines" "$(cart | grep -c '|24-MB01|')" "2"

echo "== S4 brief worked example 2: screen print, 100 units, full back"
new_session s4
add 1 100 "$D[method]=screen_print" "$L=full_back"
l=$(line 24-MB01); check "unit price 34 + 2.50" "$(cut -d'|' -f4 <<<"$l")" "36.50"
contains "fee line" "$l" "Decoration fee: \$2.50 / unit × 100 = \$250.00"

echo "== S5 left + right chest charged as two locations (client Q5)"
new_session s5
add 1 24 "$D[method]=screen_print" "$L=left_chest" "$L=right_chest"
check "unit 34 + 1.50 + 1.50" "$(line 24-MB01 | cut -d'|' -f4)" "37.00"

echo "== S6 pooled tier: 6 S + 6 M of one tee, same decoration (client Q1)"
new_session s6
add 478 6 "$SA[93]=49" "$SA[142]=167" "$D[method]=embroidery" "$L=left_chest" "$L=sleeve"
add 478 6 "$SA[93]=49" "$SA[142]=168" "$D[method]=embroidery" "$L=sleeve" "$L=left_chest" "$D[note]=different note"
check "S line at 12+ tier" "$(line MS09-S-Black | cut -d'|' -f4)" "38.00"
check "M line at 12+ tier (location order and note do not split the pool)" "$(line MS09-M-Black | cut -d'|' -f4)" "38.00"
contains "pooled tier shown" "$(line MS09-S-Black)" "(tier for 12 matching units)"
m=$(line MS09-M-Black | cut -d'|' -f1); s=$(line MS09-S-Black | cut -d'|' -f1)
post /checkout/cart/updatePost/ "cart[$m][qty]=5" "update_cart_action=update_qty"
check "M -> 5: S line drops to 1-11 tier" "$(line MS09-S-Black | cut -d'|' -f4)" "39.50"
check "M -> 5: M line drops to 1-11 tier" "$(line MS09-M-Black | cut -d'|' -f4)" "39.50"
post /checkout/cart/updatePost/ "cart[$m][qty]=6" "update_cart_action=update_qty"
post /checkout/cart/delete/ "id=$s"
check "S removed: M alone at 6 re-tiers" "$(line MS09-M-Black | cut -d'|' -f4)" "39.50"

echo "== S7 invalid requests are rejected server-side (nothing added)"
new_session s7
reject() { local name=$1; shift; add "$@"; check "$name" "$(cart | wc -l | tr -d ' ')" "0"; }
reject "method without location" 1 3 "$D[method]=embroidery"
reject "4 locations" 1 3 "$D[method]=embroidery" "$L=left_chest" "$L=right_chest" "$L=sleeve" "$L=upper_back"
reject "upper back + full back" 1 3 "$D[method]=screen_print" "$L=upper_back" "$L=full_back"
reject "unknown location" 1 3 "$D[method]=embroidery" "$L=pocket"
reject "note of 256 chars" 1 3 "$D[method]=embroidery" "$L=sleeve" "$D[note]=$(printf 'a%.0s' $(seq 256))"
reject "note with markup" 1 3 "$D[method]=embroidery" "$L=sleeve" "$D[note]=%3Cimg+src%3Dx+onerror%3D1%3E"
reject "decoration on unflagged product" 15 3 "$D[method]=embroidery" "$L=sleeve"
add 15 2 "$D[method]=none"
check "unflagged product without decoration still adds" "$(line 24-UG06 | cut -d'|' -f4)" "7.00"

echo "== S8 edit cart item: prefill, re-price, re-validate"
new_session s8
add 478 6 "$SA[93]=49" "$SA[142]=168" "$D[method]=embroidery" "$L=left_chest" "$L=sleeve" "$D[note]=Logo v2"
id=$(line MS09-M-Black | cut -d'|' -f1)
page=$(c "$BASE/checkout/cart/configure/id/$id/product_id/478/" | tr -d '\n')
contains "edit page preselects method" "$page" '<option value="embroidery"                        selected="selected">'
check "edit page prechecks 2 locations" "$(grep -o '<input type="checkbox"[^>]*checked="checked"' <<<"$page" | grep -c inkthread_decoration)" "2"
post "/checkout/cart/updateItemOptions/id/$id/" "product=478" "id=$id" "qty=6" "$SA[93]=49" "$SA[142]=168" "$D[method]=screen_print" "$L=full_back"
check "edited to screen print full back: 32 + 4.00" "$(line MS09-M-Black | cut -d'|' -f4)" "36.00"
id=$(line MS09-M-Black | cut -d'|' -f1)
post "/checkout/cart/updateItemOptions/id/$id/" "product=478" "id=$id" "qty=6" "$SA[93]=49" "$SA[142]=168" "$D[method]=screen_print" "$L=full_back" "$L=upper_back"
check "invalid edit rejected, line unchanged" "$(line MS09-M-Black | cut -d'|' -f4)" "36.00"

echo "== S9 per-location text and artwork uploads (multipart, as the AJAX form posts)"
printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' | base64 -d > "$WORK/logo.png"
printf '%%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%%%EOF\n' > "$WORK/back.pdf"
echo '<svg xmlns="http://www.w3.org/2000/svg"></svg>' > "$WORK/logo.svg"
echo '<html><body>x</body></html>' > "$WORK/fake.png"
head -c 11534336 /dev/zero > "$WORK/big.png"
postf() { local url=$1; shift; local args=(-F "form_key=$FK"); for p in "$@"; do args+=(-F "$p"); done; c -o /dev/null "${args[@]}" "$BASE$url"; }
new_session s9
postf /checkout/cart/add/ product=1 qty=12 "$D[method]=embroidery" "$D[locations][]=left_chest" "$D[locations][]=full_back" \
    "$D[location_texts][left_chest]=ACME" "$D[location_texts][sleeve]=not selected" \
    "inkthread_artwork_left_chest=@$WORK/logo.png" "inkthread_artwork_full_back=@$WORK/back.pdf" "inkthread_artwork_sleeve=@$WORK/logo.png"
l=$(line 24-MB01)
contains "left chest text + PNG on the location line" "$l" 'Left chest: $3.25 / unit · Text: "ACME" · Artwork: logo.png'
contains "full back PDF on the location line" "$l" 'Full back: $6.50 / unit · Artwork: back.pdf'
case "$l" in *"not selected"*|*Sleeve*) check "unselected location's text/upload ignored" "present" "absent";; *) check "unselected location's text/upload ignored" "absent" "absent";; esac
check "fee unaffected by text/artwork: 34 + 3.25 + 6.50" "$(cut -d'|' -f4 <<<"$l")" "43.75"
for t in "SVG|logo.svg" "HTML renamed .png|fake.png" "over 10 MB|big.png"; do
    new_session "s9${t%%|*}"; postf /checkout/cart/add/ product=1 qty=3 "$D[method]=embroidery" "$D[locations][]=sleeve" "inkthread_artwork_sleeve=@$WORK/${t##*|}"
    check "artwork rejected: ${t%%|*}" "$(cart | wc -l | tr -d ' ')" "0"
done
new_session s9t
add 1 3 "$D[method]=embroidery" "$L=sleeve" "$D[location_texts][sleeve]=$(printf 'a%.0s' $(seq 101))"
check "location text over 100 chars rejected" "$(cart | wc -l | tr -d ' ')" "0"
add 1 3 "$D[method]=embroidery" "$L=sleeve" "$D[location_texts][sleeve]=TEAM+%3C2026%3E"
check "location text with < > rejected" "$(cart | wc -l | tr -d ' ')" "0"

echo "== S10 edit: keep artwork via token, forged token ignored, remove"
new_session s10
postf /checkout/cart/add/ product=1 qty=12 "$D[method]=embroidery" "$D[locations][]=left_chest" "$D[location_texts][left_chest]=ACME" "inkthread_artwork_left_chest=@$WORK/logo.png"
token_of() { c "$BASE/checkout/cart/configure/id/$1/product_id/1/" | tr -d '\n' \
    | grep -o 'name="inkthread_decoration\[artwork_tokens\]\[left_chest\]"\s*value="[^"]*"' | sed 's/.*value="//;s/"$//' \
    | php -r 'echo html_entity_decode(stream_get_contents(STDIN), ENT_QUOTES | ENT_HTML5);'; }
edit() { local id; id=$(line 24-MB01 | cut -d'|' -f1); postf "/checkout/cart/updateItemOptions/id/$id/" product=1 "id=$id" qty=12 "$D[method]=embroidery" "$D[locations][]=left_chest" "$@"; }
id=$(line 24-MB01 | cut -d'|' -f1); token=$(token_of "$id")
contains "edit page offers the current artwork" "$(c "$BASE/checkout/cart/configure/id/$id/product_id/1/")" "Current artwork: logo.png"
edit "$D[location_texts][left_chest]=ACME CORP" "$D[artwork_tokens][left_chest]=$token"
contains "text changed, artwork kept" "$(line 24-MB01)" 'Text: "ACME CORP" · Artwork: logo.png'
edit "$D[artwork_tokens][left_chest]=${token%????}AAAA"
case "$(line 24-MB01)" in *Artwork*) check "forged token ignored" "kept" "dropped";; *) check "forged token ignored" "dropped" "dropped";; esac
postf "/checkout/cart/updateItemOptions/id/$(line 24-MB01 | cut -d'|' -f1)/" product=1 "id=$(line 24-MB01 | cut -d'|' -f1)" qty=12 \
    "$D[method]=embroidery" "$D[locations][]=left_chest" "inkthread_artwork_left_chest=@$WORK/back.pdf"
contains "new upload replaces" "$(line 24-MB01)" "Artwork: back.pdf"
token=$(token_of "$(line 24-MB01 | cut -d'|' -f1)")
edit "$D[artwork_tokens][left_chest]=$token" "$D[artwork_remove][left_chest]=1"
case "$(line 24-MB01)" in *Artwork*) check "remove drops the artwork" "kept" "dropped";; *) check "remove drops the artwork" "dropped" "dropped";; esac

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
