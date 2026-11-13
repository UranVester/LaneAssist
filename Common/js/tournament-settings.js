/**
 * Pure logic for the Manage Tournament "Tournament settings" card.
 *
 * Mirrors Settings/api.php getSettings(): a tournament finals-length override
 * of 0 means "none", and the default it falls back to is the user's value,
 * which itself falls back to the admin value (30 when unset).
 */
(function(root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LaneAssist = root.LaneAssist || {};
        root.LaneAssist.tournamentSettings = factory();
    }
}(typeof self !== 'undefined' ? self : this, function() {
    'use strict';

    function positiveInt(value) {
        var parsed = parseInt(value, 10);
        return parsed > 0 ? parsed : 0;
    }

    function describeFinalsLength(settings) {
        var s = settings || {};
        var defaultValue = positiveInt((s.user || {}).defaultFinalsLength)
            || positiveInt((s.admin || {}).defaultFinalsLength)
            || 30;
        var override = positiveInt((s.tournament || {}).defaultFinalsLength);
        return { value: override || defaultValue, defaultValue: defaultValue, isDefault: override === 0 };
    }

    /** The override to store: 0 (none) when the input is empty or equals the default. */
    function finalsLengthToSave(inputValue, defaultValue) {
        var value = positiveInt(inputValue);
        return value === defaultValue ? 0 : value;
    }

    return {
        describeFinalsLength: describeFinalsLength,
        finalsLengthToSave: finalsLengthToSave
    };
}));
