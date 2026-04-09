(function(window, $) {
    'use strict';

    window.LaneAssist = window.LaneAssist || {};

    window.LaneAssist.initMultiSelectDropdowns = function(options) {
        const settings = options || {};

        $('.multi-dropdown').each(function() {
            const $dropdown = $(this);
            const selectId = $dropdown.data('select-id');
            const $select = $('#' + selectId);
            const $toggle = $dropdown.find('.multi-dropdown-toggle');
            const $menu = $dropdown.find('.multi-dropdown-menu');

            const syncToggleText = function() {
                const checkedValues = $menu.find('input[type="checkbox"]:checked').map(function() {
                    return $(this).val();
                }).get();

                if (checkedValues.includes('') || checkedValues.length === 0) {
                    $toggle.text('All');
                    return;
                }

                $toggle.text(checkedValues.length + ' (' + checkedValues.join(', ') + ')');
            };

            const syncSelectValue = function(triggerChange) {
                const checkedValues = $menu.find('input[type="checkbox"]:checked').map(function() {
                    return $(this).val();
                }).get();
                const selectedValues = checkedValues.length === 0 ? [''] : checkedValues;
                $select.val(selectedValues);
                if (triggerChange) {
                    $select.trigger('change');
                }
            };

            const existingValues = $select.val() || [''];
            $menu.find('input[type="checkbox"]').each(function() {
                $(this).prop('checked', existingValues.includes($(this).val()));
            });
            syncToggleText();

            $toggle.on('click', function(e) {
                e.stopPropagation();
                $('.multi-dropdown').not($dropdown).removeClass('open');
                $dropdown.toggleClass('open');
            });

            $menu.on('click', function(e) {
                e.stopPropagation();
            });

            $menu.on('change', 'input[type="checkbox"]', function() {
                const $checkbox = $(this);
                const isAllOption = $checkbox.closest('.multi-option').hasClass('all-option');
                const $allOption = $menu.find('.all-option input[type="checkbox"]');
                const $specificOptions = $menu.find('.multi-option:not(.all-option) input[type="checkbox"]');

                if (isAllOption && $checkbox.is(':checked')) {
                    $specificOptions.prop('checked', false);
                }

                if (!isAllOption && $checkbox.is(':checked')) {
                    $allOption.prop('checked', false);
                }

                if ($specificOptions.filter(':checked').length === 0 && !$allOption.is(':checked')) {
                    $allOption.prop('checked', true);
                }

                syncToggleText();
                syncSelectValue(true);
            });
        });

        $(document).off('click.laneAssistMultiDropdown').on('click.laneAssistMultiDropdown', function(e) {
            $('.multi-dropdown').removeClass('open');
            if (typeof settings.onDocumentClick === 'function') {
                settings.onDocumentClick(e);
            }
        });
    };
})(window, jQuery);
