/**
 * Fixed-distance lane allocation for finals auto assignment.
 */
(function(root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LaneAssist = root.LaneAssist || {};
        root.LaneAssist.distanceLanePlan = factory();
    }
}(typeof self !== 'undefined' ? self : this, function() {
    'use strict';

    function getDistanceKey(bundle) {
        const key = (bundle.distanceProfile || '').toString().trim();
        return key !== '' ? key : 'unknown';
    }

    function buildDistanceLanePlan(teamEvent, bundles, targetNumbers) {
        const streamBundles = (bundles || []).filter(function(bundle) {
            const bundleTeamEvent = parseInt(bundle.rows[0] ? bundle.rows[0].teamEvent : 0, 10) || 0;
            return bundleTeamEvent === teamEvent;
        });

        if (!streamBundles.length || !targetNumbers.length) {
            return { targetToDistance: {}, ranges: {} };
        }

        const stats = {};
        streamBundles.forEach(function(bundle) {
            const distanceKey = getDistanceKey(bundle);
            if (!stats[distanceKey]) {
                stats[distanceKey] = {
                    key: distanceKey,
                    minSort: Number.MAX_SAFE_INTEGER,
                    maxBundleTargets: 0,
                    weight: 0,
                    count: 0
                };
            }

            const distanceSort = parseInt(bundle.distanceSort, 10);
            if (!Number.isNaN(distanceSort)) {
                stats[distanceKey].minSort = Math.min(stats[distanceKey].minSort, distanceSort);
            }

            stats[distanceKey].maxBundleTargets = Math.max(stats[distanceKey].maxBundleTargets, Math.max(1, parseInt(bundle.targetsUsed, 10) || 1));
            stats[distanceKey].weight += Math.max(1, parseInt(bundle.targetsUsed, 10) || 1);
        });

        const groups = Object.keys(stats).map(function(key) {
            return stats[key];
        }).filter(function(group) {
            return group.key !== 'unknown';
        }).sort(function(a, b) {
            if (a.minSort !== b.minSort) {
                return a.minSort - b.minSort;
            }

            return a.key.localeCompare(b.key);
        });

        if (!groups.length) {
            return { ranges: {} };
        }

        const totalTargets = targetNumbers.length;
        const totalWeight = groups.reduce(function(sum, group) {
            return sum + group.weight;
        }, 0);

        const minSlotsByCapacity = Math.max(1, Math.ceil(totalWeight / totalTargets));
        let chosenCounts = null;

        for (let slotCount = minSlotsByCapacity; slotCount <= totalWeight; slotCount++) {
            const proposedCounts = [];
            let sumCounts = 0;

            groups.forEach(function(group) {
                const requiredByLoad = Math.ceil(group.weight / slotCount);
                const lanes = Math.max(group.maxBundleTargets, requiredByLoad);
                proposedCounts.push(lanes);
                sumCounts += lanes;
            });

            if (sumCounts <= totalTargets) {
                chosenCounts = proposedCounts;
                break;
            }
        }

        if (!chosenCounts) {
            return null;
        }

        groups.forEach(function(group, index) {
            group.count = chosenCounts[index];
        });

        const ranges = {};
        let cursor = 0;

        groups.forEach(function(group) {
            const slice = targetNumbers.slice(cursor, cursor + group.count);
            cursor += group.count;
            if (!slice.length) {
                return;
            }

            ranges[group.key] = {
                start: slice[0],
                end: slice[slice.length - 1]
            };
        });

        return { ranges: ranges };
    }

    return {
        buildDistanceLanePlan: buildDistanceLanePlan
    };
}));