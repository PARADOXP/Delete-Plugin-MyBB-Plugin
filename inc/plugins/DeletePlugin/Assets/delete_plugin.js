/**
 * Delete Plugin - plugin list and scan page helper.
 *
 * Adds a Delete link next to the Activate / Deactivate links of every plugin
 * row. Configuration is read from the #delete_plugin_config element, no inline
 * script is used.
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        var $selectAll = $('#delete_plugin_select_all');

        if ($selectAll.length) {
            $selectAll.on('change', function () {
                $('#delete_plugin_form input[type="checkbox"][name="delete[]"]')
                    .prop('checked', this.checked);
            });
        }

        var config = document.getElementById('delete_plugin_config');

        if (!config) {
            return;
        }

        var canDelete = config.getAttribute('data-can-delete') === '1';
        var canLink = config.getAttribute('data-can-link') === '1';
        var base = config.getAttribute('data-base') || '';
        var label = config.getAttribute('data-label') || 'Delete';

        if (!canDelete || !canLink) {
            return;
        }

        $('a[href*="module=config-plugins"]').filter(function () {
            return /action=(activate|deactivate)/.test($(this).attr('href') || '');
        }).each(function () {
            var $link = $(this);
            var $row = $link.closest('tr');

            if ($row.find('.delete_plugin_link').length) {
                return;
            }

            var match = ($link.attr('href') || '').match(/[?&]plugin=([^&]+)/);

            if (!match) {
                return;
            }

            var codename = decodeURIComponent(match[1]);

            if (codename === 'delete_plugin') {
                return;
            }

            $('<a class="delete_plugin_link" />')
                .attr('href', base + encodeURIComponent(codename))
                .attr('title', $row.find('td').first().text().replace(/\s+/g, ' ').trim())
                .text(label)
                .insertAfter($link);

            $link.after(' | ');
        });
    });
}(jQuery));
