/**
 * InkThread Decoration Pricing: product page decoration fieldset.
 *
 * UX only; the server re-validates everything (SelectionValidator).
 * - Method "None" disables locations and note, so nothing is posted and no location is required.
 * - Locations without a price table for the chosen method are disabled.
 * - At most N locations; mutually exclusive locations disable each other.
 * A greyed-out location shows its reason inline; valid selections never show a message.
 * Each checked location shows one required field: text for embroidery, an artwork file for screen print.
 * A chosen artwork file is previewed under its input (a thumbnail for PNG/JPG or the type for PDF) with a Remove
 * button; the current file on the cart edit page can be removed the same way.
 * Hidden fields are disabled, so they are neither validated nor posted.
 * The product price shows the decoration fee per unit for the entered quantity, as the cart's unit price does
 * (pooling with matching cart lines can only lower it in the cart).
 */
define([
    'jquery',
    'mage/translate',
    'jquery-ui-modules/widget',
    'mage/validation'
], function ($, $t) {
    'use strict';

    $.validator.addMethod(
        'inkthread-decoration-no-markup',
        function (value) {
            return !/[<>]/.test(value);
        },
        $t('The decoration note cannot contain the characters < or >.')
    );

    $.validator.addMethod(
        'inkthread-decoration-artwork',
        function (value, element) {
            var file = element.files && element.files[0],
                config = $(element).closest('.inkthread-decoration').data('inkthreadArtwork') || {},
                extension;

            if (!file) {
                return true;
            }
            extension = file.name.split('.').pop().toLowerCase();

            return (config.extensions || []).indexOf(extension) !== -1 && file.size <= config.maxSize;
        },
        $t('Artwork must be a PNG, JPG or PDF file of at most 10 MB.')
    );

    $.widget('inkthread.decorationOptions', {
        options: {
            noneMethod: 'none',
            maxLocations: 3,
            exclusivePairs: [],
            offeredLocations: {},
            messages: {},
            labels: {},
            artwork: {},
            textMethods: [],
            artworkMethods: [],
            priceTables: {},
            priceBoxSelector: '[data-role="priceBox"]'
        },

        /** @inheritdoc */
        _create: function () {
            this.method = this.element.find('[data-role="decoration-method"]');
            this.checkboxes = this.element.find('[data-role="decoration-locations"] input[type="checkbox"]');
            this.note = this.element.find('[data-role="decoration-note"]');
            this.details = this.element.find('[data-role="decoration-detail"]');
            this.qty = this.element.closest('form').find('input[name="qty"]');
            this.priceBox = $(this.options.priceBoxSelector).filter(
                '[data-product-id="' + this.element.closest('form').find('input[name="product"]').val() + '"]'
            ).first();
            this.element.data('inkthreadArtwork', this.options.artwork);
            // Luma only sets multipart when the product has native file options; needed for a non-AJAX submit.
            this.element.closest('form').attr('enctype', 'multipart/form-data');

            this._on(this.method, {change: this._refresh});
            this._on(this.checkboxes, {change: this._refresh});
            this._on(this.element.find('input[type="file"]'), {change: this._preview});
            this._on(this.element, {'click [data-role="decoration-artwork-remove"]': this._removeArtwork});
            this._on(this.qty, {input: this._updatePrice, change: this._updatePrice});
            // The price box may initialise after this widget; its own first update then includes the fee.
            this._on(this.priceBox, {'price-box-initialized': this._updatePrice});
            this._refresh();
        },

        /**
         * Per-unit decoration fee for the current selection and quantity: the sum of each checked location's tier
         * price. A location with no tier for the quantity adds nothing here; add to cart then refuses it.
         *
         * @returns {Number}
         * @private
         */
        _fee: function () {
            var tables = this.options.priceTables[this.method.val()] || {},
                qty = parseFloat(this.qty.val()) > 0 ? parseFloat(this.qty.val()) : 1;

            return this.checkboxes.filter(':checked').get().reduce(function (fee, checkbox) {
                var price = null;

                (tables[checkbox.value] || []).forEach(function (tier) {
                    if (qty >= tier.min_qty) {
                        price = tier.price;
                    }
                });

                return price === null ? fee : fee + price;
            }, 0);
        },

        /**
         * Add the fee to the product price box, alongside core's own adjustments (configurable child, tier price).
         * Prices that include tax get the fee scaled by the product's displayed tax ratio.
         *
         * @private
         */
        _updatePrice: function () {
            var prices, ratio, fee, changes = {};

            if (!this.priceBox.length || !this.priceBox.data('magePriceBox')) {
                return;
            }
            prices = this.priceBox.priceBox('option').prices || {};
            ratio = prices.basePrice && prices.basePrice.amount > 0 && prices.finalPrice
                ? prices.finalPrice.amount / prices.basePrice.amount
                : 1;
            fee = this._fee();
            Object.keys(prices).forEach(function (code) {
                changes[code] = {amount: code.indexOf('base') === 0 ? fee : fee * ratio};
            });
            this.priceBox.trigger('updatePrice', {inkthreadDecoration: changes});
        },

        /**
         * Show the chosen file under its input: a local image preview for PNG/JPG or the type for PDF, then a Remove
         * button (the file input already shows the name). Nothing is uploaded here; the object URL only lives in this
         * page.
         *
         * @param {jQuery.Event} event
         * @private
         */
        _preview: function (event) {
            var input = event.target,
                preview = $(input).siblings('[data-role="decoration-artwork-preview"]'),
                file = input.files && input.files[0],
                config = this.options.artwork,
                extension = file ? file.name.split('.').pop().toLowerCase() : '',
                image;

            if ($(input).data('inkthreadPreviewUrl')) {
                URL.revokeObjectURL($(input).data('inkthreadPreviewUrl'));
                $(input).removeData('inkthreadPreviewUrl');
            }
            preview.empty().prop('hidden', true);
            if (!file || (config.extensions || []).indexOf(extension) === -1 || file.size > config.maxSize) {
                return;
            }

            if (extension === 'pdf') {
                preview.append($('<span class="inkthread-decoration-preview-type"></span>').text('PDF'));
            } else {
                image = document.createElement('img');
                image.alt = file.name;
                image.onerror = function () {
                    $(image).remove();
                };
                image.src = URL.createObjectURL(file);
                $(input).data('inkthreadPreviewUrl', image.src);
                preview.append(image);
            }
            preview.append(this._removeButton(file.name)).prop('hidden', false);
        },

        /**
         * Remove button for one location's artwork.
         *
         * @param {String} name file name, for the accessible label
         * @returns {jQuery}
         * @private
         */
        _removeButton: function (name) {
            return $('<button type="button" class="action inkthread-decoration-remove"></button>')
                .attr('data-role', 'decoration-artwork-remove')
                .attr('aria-label', $t('Remove %1').replace('%1', name))
                .text($t('Remove'));
        },

        /**
         * Remove a location's artwork in place, without reloading or submitting: clears a newly chosen file, or drops
         * the current file on the cart edit page (applied when the cart item is updated). The field is then required
         * again, so the location needs a new file before the item can be added.
         *
         * @param {jQuery.Event} event
         * @private
         */
        _removeArtwork: function (event) {
            var button = $(event.currentTarget),
                current = button.closest('[data-role="decoration-artwork-current"]'),
                input = button.closest('.control').find('input[type="file"]');

            if (current.length) {
                current.remove();
            } else {
                input.val('');
                this._preview({target: input[0]});
            }
            this._refresh();
            input.trigger('focus');
        },

        /**
         * Enable/disable inputs from the current state and show why options are unavailable.
         *
         * @private
         */
        _refresh: function () {
            var method = this.method.val(),
                decorated = method !== this.options.noneMethod,
                offered = this.options.offeredLocations[method] || [],
                checked,
                blockedBy = {};

            this.checkboxes.each(function () {
                if (!decorated || offered.indexOf(this.value) === -1) {
                    this.checked = false;
                }
            });
            checked = this.checkboxes.filter(':checked').map(function () {
                return this.value;
            }).get();

            this.options.exclusivePairs.forEach(function (pair) {
                if (checked.indexOf(pair[0]) !== -1) {
                    blockedBy[pair[1]] = pair[0];
                }
                if (checked.indexOf(pair[1]) !== -1) {
                    blockedBy[pair[0]] = pair[1];
                }
            });

            this.checkboxes.each(function (index, checkbox) {
                var notOffered = decorated && offered.indexOf(checkbox.value) === -1,
                    blocker = blockedBy[checkbox.value],
                    full = checked.length >= this.options.maxLocations && !checkbox.checked,
                    reason = '';

                if (notOffered) {
                    reason = this.options.messages.notOffered;
                } else if (decorated && blocker) {
                    reason = this.options.messages.exclusive.replace('%1', this.options.labels[blocker]);
                }

                checkbox.disabled = !decorated || notOffered || !!blocker || full;
                $(checkbox).closest('.field.choice')
                    .toggleClass('_disabled', checkbox.disabled)
                    .find('[data-role="decoration-reason"]').text(reason);
            }.bind(this));

            this.details.each(function (index, row) {
                var show = decorated && checked.indexOf(row.getAttribute('data-location')) !== -1,
                    parts = {
                        'decoration-detail-text': show && this.options.textMethods.indexOf(method) !== -1,
                        'decoration-detail-artwork': show && this.options.artworkMethods.indexOf(method) !== -1
                    };

                row.hidden = !show;
                Object.keys(parts).forEach(function (role) {
                    var part = $(row).find('[data-role="' + role + '"]');

                    part.prop('hidden', !parts[role]).find('input').prop('disabled', !parts[role]);
                });
                // Native "required" (an empty field is otherwise treated as optional and skips custom rules); on cart
                // edit the current file counts, so a new upload is only needed when there is none.
                $(row).find('input[type="file"]').prop(
                    'required',
                    parts['decoration-detail-artwork'] && !$(row).find('[data-role="decoration-artwork-token"]').length
                );
            }.bind(this));

            this._updatePrice();
            this.note.prop('disabled', !decorated);
            this.element.find('[data-role="decoration-locations"], [data-role="decoration-note-field"]')
                .toggleClass('_disabled', !decorated);
        }
    });

    return $.inkthread.decorationOptions;
});
