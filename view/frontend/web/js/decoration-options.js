/**
 * InkThread Decoration Pricing: product page decoration fieldset.
 *
 * UX only; the server re-validates everything (SelectionValidator).
 * - Method "None" disables locations and note, so nothing is posted and no location is required.
 * - Locations without a price table for the chosen method are disabled.
 * - At most N locations; mutually exclusive locations disable each other.
 * A greyed-out location shows its reason inline; valid selections never show a message.
 * Each checked location shows its own optional text and artwork inputs; hidden ones are disabled (not posted).
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
            artwork: {}
        },

        /** @inheritdoc */
        _create: function () {
            this.method = this.element.find('[data-role="decoration-method"]');
            this.checkboxes = this.element.find('[data-role="decoration-locations"] input[type="checkbox"]');
            this.note = this.element.find('[data-role="decoration-note"]');
            this.details = this.element.find('[data-role="decoration-detail"]');
            this.element.data('inkthreadArtwork', this.options.artwork);
            // Luma only sets multipart when the product has native file options; needed for a non-AJAX submit.
            this.element.closest('form').attr('enctype', 'multipart/form-data');

            this._on(this.method, {change: this._refresh});
            this._on(this.checkboxes, {change: this._refresh});
            this._refresh();
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

            this.details.each(function () {
                var show = decorated && checked.indexOf(this.getAttribute('data-location')) !== -1;

                this.hidden = !show;
                $(this).find('input').prop('disabled', !show);
            });

            this.note.prop('disabled', !decorated);
            this.element.find('[data-role="decoration-locations"], [data-role="decoration-note-field"]')
                .toggleClass('_disabled', !decorated);
        }
    });

    return $.inkthread.decorationOptions;
});
