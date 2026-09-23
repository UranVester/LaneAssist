(function(root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LaneAssist = root.LaneAssist || {};
        root.LaneAssist.badges = factory();
    }
}(typeof self !== 'undefined' ? self : this, function() {
    'use strict';

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Badges come from third-party provider modules, so a colour never reaches a
    // style attribute unless it is a plain hex value.
    function safeColor(value, fallback) {
        return /^#[0-9a-fA-F]{6}$/.test(String(value || '')) ? String(value) : fallback;
    }

    // Refuse rather than sanitise: the value goes straight into a class attribute.
    function safeIcon(value) {
        return /^fa-[a-z0-9-]{1,40}$/.test(String(value || '')) ? String(value) : '';
    }

    function titleAttr(badge) {
        return badge.title ? ' title="' + escapeHtml(badge.title) + '"' : '';
    }

    /**
     * Split badges into the two DOM slots the competitor row has.
     *
     * 'edge' badges share a 4px strip down the right border, one equal segment
     * each, mirroring the pace border on the left. 'pill' and 'icon' badges go
     * inline beside the name, in the order the providers supplied them.
     */
    function render(badgeList) {
        if (!Array.isArray(badgeList) || badgeList.length === 0) {
            return {edge: '', inline: ''};
        }

        var segments = '';
        var inline = '';

        badgeList.forEach(function(badge) {
            if (!badge || badge.label === null || badge.label === undefined
                || badge.label === '') {
                return;
            }

            var kind = badge.kind ? String(badge.kind).toLowerCase() : 'pill';
            var background = safeColor(badge.color, '#eeeeee');

            if (kind === 'edge') {
                // No inline style beyond the colour: the outline that keeps a
                // near-white segment visible belongs to the stylesheet.
                segments += '<span class="competitor-edge-seg" style="background:'
                    + background + '"' + titleAttr(badge) + '></span>';
                return;
            }

            if (kind === 'pill') {
                inline += '<span class="badge-pill" style="background:' + background
                    + ';color:' + safeColor(badge.textColor, '#000000') + '"'
                    + titleAttr(badge) + '>' + escapeHtml(badge.label) + '</span>';
                return;
            }

            if (kind === 'icon') {
                var icon = safeIcon(badge.icon);
                if (icon === '') {
                    return;
                }
                inline += '<i class="badge-icon fa ' + icon + '" style="color:'
                    + safeColor(badge.color, '#000000') + '"'
                    + titleAttr(badge) + ' aria-label="' + escapeHtml(badge.label)
                    + '"></i>';
                return;
            }

            // Unknown kind: drop it. Guessing would render a future badge type as
            // something wrong on an older LaneAssist.
        });

        return {
            edge: segments === '' ? '' : '<span class="competitor-edge">' + segments + '</span>',
            inline: inline === '' ? '' : '<span class="badge-inline">' + inline + '</span>'
        };
    }

    return {
        render: render
    };
}));
