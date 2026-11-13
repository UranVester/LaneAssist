/**
 * Change target faces: request ordering, pure (no jQuery/DOM).
 *
 * Keeps "Set all" honest: it may only act on the filters of the rows on
 * screen, and only when no newer filter or write is pending. Also drops load
 * responses that a newer load or a write has overtaken, so an old response
 * can't paint a face back that was just changed.
 */
(function(root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.ChangeTargetFacesGate = factory();
    }
}(typeof self !== 'undefined' ? self : this, function() {
    'use strict';

    function createRequestGate() {
        let seq = 0;
        let pendingFilters = {};
        let shownFilters = null;
        let pending = true;

        return {
            /** A filter input changed; its load hasn't started yet. */
            markDirty: function() {
                pending = true;
            },
            /** Returns the token to hand back to finishLoad/failLoad. */
            startLoad: function(filters) {
                pending = true;
                pendingFilters = filters;
                return ++seq;
            },
            /** True when this response is the latest and should be rendered. */
            finishLoad: function(token) {
                if (token !== seq) {
                    return false;
                }
                shownFilters = pendingFilters;
                pending = false;
                return true;
            },
            /** The screen still shows the previous rows, so stay pending. */
            failLoad: function() {},
            /** A write landed: loads already in flight may predate it. */
            noteWrite: function() {
                seq++;
                pending = true;
            },
            /** What Set all may send, or null when the screen isn't settled. */
            setAllRequest: function(count) {
                if (pending || shownFilters === null) {
                    return null;
                }
                return { filters: shownFilters, expectedCount: count };
            }
        };
    }

    return { createRequestGate: createRequestGate };
}));
