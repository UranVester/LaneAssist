/**
 * Shared "color by attribute" palette. The same value (a club code, a class
 * id, a division's bow type) must render as the same color in every module
 * that offers a color-by control - ManageTargets and LiveView both key their
 * grouping through this module so a club's color never depends on which page
 * happens to be open. Pure, no jQuery/DOM, so it is unit-testable (tests/js/).
 */
(function(root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LaneAssist = root.LaneAssist || {};
        root.LaneAssist.colorBy = factory();
    }
}(typeof self !== 'undefined' ? self : this, function() {
    'use strict';

    function hashString(text) {
        let hash = 0;
        for (let i = 0; i < text.length; i++) {
            hash = ((hash << 5) - hash) + text.charCodeAt(i);
            hash |= 0;
        }
        return Math.abs(hash);
    }

    function getColorPalette(key) {
        const seed = hashString((key || '').toString());
        const hue = seed % 360;
        return {
            color: 'hsl(' + hue + ', 60%, 40%)',
            bg: 'hsl(' + hue + ', 85%, 94%)'
        };
    }

    const BOW_TOKENS = [
        { token: 'recurve', label: 'Recurve' },
        { token: 'compound', label: 'Compound' },
        { token: 'barebow', label: 'Barebow' },
        { token: 'longbow', label: 'Longbow' },
        { token: 'traditional', label: 'Traditional' },
        { token: 'instinctive', label: 'Instinctive' }
    ];

    function extractBowType(divisionDescription, fallbackId) {
        const text = (divisionDescription || '').toString().trim();
        const lower = text.toLowerCase();

        for (let i = 0; i < BOW_TOKENS.length; i++) {
            if (lower.indexOf(BOW_TOKENS[i].token) !== -1) {
                return BOW_TOKENS[i].label;
            }
        }

        if (text) {
            const firstWord = text.split(/\s+/)[0];
            if (firstWord) {
                return firstWord;
            }
        }

        const code = (fallbackId || '').toString().trim();
        return code || 'Unknown Bow';
    }

    return {
        hashString: hashString,
        getColorPalette: getColorPalette,
        extractBowType: extractBowType
    };
}));
