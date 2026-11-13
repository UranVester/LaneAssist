/**
 * Shared "color by attribute" palette. The same value (a club code, a bow
 * type, a category, an event) must render as the same color in every page
 * that offers a color-by control - ManageTargets, ManageFinals and LiveView.
 * Colors are handed out in order from one fixed palette, by each key's
 * position in a tournament-wide ordered list (ColorGroups, built server-side
 * by Common/color-logic.php), never by what one page happens to show.
 * Pure, no jQuery/DOM, so it is unit-testable (tests/js/).
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

    // Validated categorical palette (adjacent CVD dE >= 9.1, normal-vision
    // dE >= 19.6). Past the end it repeats; never generate extra hues. The
    // color is a border/stripe only - three slots are under 3:1 on white,
    // so it must never be used as text color.
    const PALETTE = [
        { color: '#2a78d6', bg: '#e1ecf9' },
        { color: '#eb6834', bg: '#fceae3' },
        { color: '#1baf7a', bg: '#dff4ec' },
        { color: '#eda100', bg: '#fcf2db' },
        { color: '#e87ba4', bg: '#fcedf2' },
        { color: '#008300', bg: '#dbeedb' },
        { color: '#6250d6', bg: '#e9e6f9' },
        { color: '#e34948', bg: '#fbe6e5' }
    ];

    // The eight standard bracket phases first so they never share a color; rare ones after.
    const PHASE_ORDER = ['64', '32', '16', '8', '4', '2', '1', '0', '48', '24', '12'];

    function paletteAt(index) {
        const length = PALETTE.length;
        return PALETTE[((index % length) + length) % length];
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

    function normalizeKey(key) {
        if (key === null || key === undefined) {
            return '';
        }
        return key.toString().trim();
    }

    /**
     * Turns the server's ColorGroups payload into (group, key) -> palette
     * entry. Positions live in a Map per group so integer-like keys ('1',
     * '001') keep payload order. A key the payload lacks (an entry added
     * after the page loaded) is appended on first sight, so it stays stable
     * for the life of the page.
     */
    function createColorIndex(groups) {
        const payload = (groups && typeof groups === 'object') ? groups : {};
        const positions = {};

        function addAll(group, keys) {
            const map = positions[group] || (positions[group] = new Map());
            (Array.isArray(keys) ? keys : []).forEach(function(key) {
                const normalized = normalizeKey(key);
                if (normalized !== '' && !map.has(normalized)) {
                    map.set(normalized, map.size);
                }
            });
        }

        Object.keys(payload).forEach(function(group) {
            if (group !== 'division') {
                addAll(group, payload[group]);
            }
        });
        addAll('bow', (Array.isArray(payload.division) ? payload.division : []).map(function(division) {
            return extractBowType(division && division.description, division && division.id);
        }));
        addAll('phase', PHASE_ORDER);

        function positionOf(group, key) {
            const normalized = normalizeKey(key);
            if (normalized === '') {
                return null;
            }
            const map = positions[group] || (positions[group] = new Map());
            if (!map.has(normalized)) {
                map.set(normalized, map.size);
            }
            return map.get(normalized);
        }

        function colorFor(group, key) {
            const position = positionOf(group, key);
            return position === null ? null : paletteAt(position);
        }

        return { positionOf: positionOf, colorFor: colorFor };
    }

    /** The single distinct non-empty value, or null when there are none or several. */
    function keyForMulti(values) {
        const distinct = [];
        (Array.isArray(values) ? values : []).forEach(function(value) {
            const normalized = normalizeKey(value);
            if (normalized !== '' && distinct.indexOf(normalized) === -1) {
                distinct.push(normalized);
            }
        });
        return distinct.length === 1 ? distinct[0] : null;
    }

    return {
        PALETTE: PALETTE,
        PHASE_ORDER: PHASE_ORDER,
        hashString: hashString,
        paletteAt: paletteAt,
        createColorIndex: createColorIndex,
        keyForMulti: keyForMulti,
        extractBowType: extractBowType
    };
}));
