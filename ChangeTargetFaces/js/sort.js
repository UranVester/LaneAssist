/**
 * Change target faces: row order and the Target cell, pure (no jQuery/DOM).
 *
 * Mid-event an archer switches face and the organiser looks them up by mat,
 * so the list sorts by Target (session, mat, letter) or by Archer (last name).
 * Archers without a target always go last, whichever way Target is sorted.
 */
(function(root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.ChangeTargetFacesSort = factory();
    }
}(typeof self !== 'undefined' ? self : this, function() {
    'use strict';

    const DEFAULT_SORT = { key: 'archer', dir: 'asc' };

    function assigned(a) {
        return !!(a.session && a.target);
    }

    // Danish collation: Æ, Ø, Å after Z, and "Aa" counted as Å.
    function text(a, b) {
        return String(a || '').localeCompare(String(b || ''), 'da', { sensitivity: 'base' });
    }

    function byName(a, b) {
        return text(a.lastName, b.lastName) || text(a.firstName, b.firstName) || (a.id - b.id);
    }

    function bySpot(a, b) {
        return (a.session - b.session) || (a.target - b.target) || text(a.letter, b.letter);
    }

    function sortArchers(archers, key, dir) {
        const sign = dir === 'desc' ? -1 : 1;
        return archers.slice().sort(function(a, b) {
            if (key === 'target') {
                const ua = assigned(a);
                const ub = assigned(b);
                if (ua !== ub) {
                    return ua ? -1 : 1;
                }
                return (ua ? sign * bySpot(a, b) : 0) || byName(a, b);
            }
            return sign * byName(a, b);
        });
    }

    function formatTarget(a, multiSession) {
        if (!assigned(a)) {
            return '';
        }
        return (multiSession ? a.session + '-' : '') + a.target + (a.letter || '');
    }

    function hasMultipleSessions(archers) {
        let first = 0;
        return archers.some(function(a) {
            if (!assigned(a)) {
                return false;
            }
            first = first || a.session;
            return a.session !== first;
        });
    }

    /** The sort saved in localStorage, or the default if it is missing or not one we know. */
    function parseSort(stored) {
        try {
            const s = JSON.parse(stored);
            if (s && (s.key === 'archer' || s.key === 'target') && (s.dir === 'asc' || s.dir === 'desc')) {
                return { key: s.key, dir: s.dir };
            }
        } catch (e) {
            // fall through to the default
        }
        return { key: DEFAULT_SORT.key, dir: DEFAULT_SORT.dir };
    }

    return {
        sortArchers: sortArchers,
        formatTarget: formatTarget,
        hasMultipleSessions: hasMultipleSessions,
        parseSort: parseSort
    };
}));
