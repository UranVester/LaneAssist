(function(root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LaneAssist = root.LaneAssist || {};
        root.LaneAssist.updateStatus = factory();
    }
}(typeof self !== 'undefined' ? self : this, function() {
    'use strict';

    function isSignatureVerified(signature) {
        return !!signature &&
            (signature.ok === true || parseInt(signature.ok, 10) > 0);
    }

    return {
        isSignatureVerified: isSignatureVerified
    };
}));