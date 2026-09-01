/**
 * Pair-playability rules for ManageFinals.
 *
 * getCurrent() synthesizes bracket rows for every event with a configured first
 * phase, whether or not anyone can actually play them, so the UI decides which
 * of those rows are real work. That decision lives here as pure functions so it
 * can be unit-tested without a browser or a DB (tests/js/).
 *
 * A "pair" is the two bracket rows of one match (matchNo >> 1 shared).
 */
(function(root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LaneAssist = root.LaneAssist || {};
        root.LaneAssist.finalsPlayability = factory();
    }
}(typeof self !== 'undefined' ? self : this, function() {
    'use strict';

    /**
     * Seeds the pair draws from. GrPosition is preferred; GrPosition2 only fills
     * in when the primary column cannot supply two distinct seeds.
     */
    function collectSeedPositions(pairRows) {
        const seedPositions = [];

        const collect = function(field) {
            (pairRows || []).forEach(function(row) {
                const seed = parseInt(row && row[field], 10);
                if (!Number.isNaN(seed) && seed > 0 && seedPositions.indexOf(seed) === -1) {
                    seedPositions.push(seed);
                }
            });
        };

        collect('gridPosition');
        if (seedPositions.length < 2) {
            collect('gridPosition2');
        }

        return seedPositions;
    }

    /** Seated (actual) participants on the pair. */
    function countParticipants(pairRows) {
        return (pairRows || []).filter(function(row) {
            return parseInt(row && row.hasParticipant, 10) > 0;
        }).length;
    }

    /**
     * Highest projected finalist count across the pair, or null when no row
     * carries a numeric projection at all (i.e. genuinely unknown, as opposed to
     * a known zero, which means nobody is entered).
     */
    function maxProjectedParticipants(pairRows) {
        let projected = null;

        (pairRows || []).forEach(function(row) {
            const value = parseInt(row && row.projectedParticipants, 10);
            if (Number.isNaN(value)) {
                return;
            }
            const clamped = Math.max(0, value);
            projected = (projected === null) ? clamped : Math.max(projected, clamped);
        });

        return projected;
    }

    /**
     * Can this pair be played, on projection alone?
     *
     * - Unknown projection (null) => yes. Planning escape hatch: never hide work
     *   just because the projection could not be computed.
     * - Zero => no. Nobody is entered in the event, so the whole bracket is
     *   phantom. This is the common case for team events at a tournament with no
     *   team entries: without this branch all 448 team bracket rows of a 14-event
     *   tournament land in the unassigned pool as if they needed scheduling.
     * - Otherwise => needs at least two finalists, and both of the two lowest
     *   seeds must fall inside the projected field.
     */
    function isProjectedPlayable(pairRows) {
        const projected = maxProjectedParticipants(pairRows);
        if (projected === null) {
            return true;
        }
        if (projected < 2) {
            return false;
        }

        const seedPositions = collectSeedPositions(pairRows);
        if (seedPositions.length < 2) {
            return false;
        }

        return seedPositions.slice(0, 2).every(function(seed) {
            return seed <= projected;
        });
    }

    /** Seated participants win over any projection; otherwise fall back to it. */
    function isPairPlayable(pairRows) {
        if (countParticipants(pairRows) >= 2) {
            return true;
        }
        return isProjectedPlayable(pairRows);
    }

    return {
        collectSeedPositions: collectSeedPositions,
        countParticipants: countParticipants,
        maxProjectedParticipants: maxProjectedParticipants,
        isProjectedPlayable: isProjectedPlayable,
        isPairPlayable: isPairPlayable
    };
}));
