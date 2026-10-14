/**
 * InkThread Decoration Pricing: product page decoration fieldset.
 *
 * UX only; the server re-validates everything (SelectionValidator).
 * - Method "None" disables locations and note, so nothing is posted and no location is required.
 * - Locations without a price table for the chosen method are disabled.
 * - At most N locations; mutually exclusive locations disable each other.
 * A greyed-out location shows its reason inline; valid selections never show a message.
 * Each checked location shows one required field: text for embroidery, an artwork file for screen print.
 * A chosen artwork file is previewed under its input (image thumbnail for PNG/JPG, type and name for PDF).
 * Hidden fields are disabled, so they are neither validated nor posted.
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
            artworkMethods: []
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
            this._on(this.element.find('input[type="file"]'), {change: this._preview});
            this._refresh();
        },

        /**
         * Show the chosen file under its input: a local image preview for PNG/JPG, the file type and name for PDF.
         * Nothing is uploaded here; the object URL only lives in this page.
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
                image,
                label;

            if ($(input).data('inkthreadPreviewUrl')) {
                URL.revokeObjectURL($(input).data('inkthreadPreviewUrl'));
                $(input).removeData('inkthreadPreviewUrl');
            }
            preview.empty().prop('hidden', true);
            if (!file || (config.extensions || []).indexOf(extension) === -1 || file.size > config.maxSize) {
                return;
            }

            label = document.createElement('span');
            label.className = 'inkthread-decoration-preview-name';
            label.textContent = file.name;
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
            preview.append(label).prop('hidden', false);
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

            this.note.prop('disabled', !decorated);
            this.element.find('[data-role="decoration-locations"], [data-role="decoration-note-field"]')
                .toggleClass('_disabled', !decorated);
        }
    });

    return $.inkthread.decorationOptions;
});
