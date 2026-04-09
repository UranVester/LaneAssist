(function($) {
    'use strict';

    if (!$.ui || !$.ui.mouse) {
        return;
    }

    const mouseProto = $.ui.mouse.prototype;
    const originalMouseInit = mouseProto._mouseInit;
    const originalMouseDestroy = mouseProto._mouseDestroy;

    function simulateMouseEvent(event, type) {
        if (!event || !event.originalEvent || !event.originalEvent.changedTouches || !event.originalEvent.changedTouches.length) {
            return;
        }

        const touch = event.originalEvent.changedTouches[0];
        const simulatedEvent = document.createEvent('MouseEvents');
        simulatedEvent.initMouseEvent(
            type,
            true,
            true,
            window,
            1,
            touch.screenX,
            touch.screenY,
            touch.clientX,
            touch.clientY,
            false,
            false,
            false,
            false,
            0,
            null
        );

        touch.target.dispatchEvent(simulatedEvent);
    }

    mouseProto._touchStart = function(event) {
        if (!event || !event.originalEvent || !event.originalEvent.touches) {
            return;
        }

        if (event.originalEvent.touches.length > 1) {
            return;
        }

        if (this._touchActive) {
            return;
        }

        this._touchActive = true;
        this._touchMoved = false;

        simulateMouseEvent(event, 'mouseover');
        simulateMouseEvent(event, 'mousemove');
        simulateMouseEvent(event, 'mousedown');
    };

    mouseProto._touchMove = function(event) {
        if (!this._touchActive) {
            return;
        }

        this._touchMoved = true;
        simulateMouseEvent(event, 'mousemove');
    };

    mouseProto._touchEnd = function(event) {
        if (!this._touchActive) {
            return;
        }

        simulateMouseEvent(event, 'mouseup');

        if (!this._touchMoved) {
            simulateMouseEvent(event, 'click');
        }

        this._touchActive = false;
        this._touchMoved = false;
    };

    mouseProto._mouseInit = function() {
        this.element.on('touchstart.uiMouseTouch', $.proxy(this, '_touchStart'));
        this.element.on('touchmove.uiMouseTouch', $.proxy(this, '_touchMove'));
        this.element.on('touchend.uiMouseTouch touchcancel.uiMouseTouch', $.proxy(this, '_touchEnd'));

        originalMouseInit.call(this);
    };

    mouseProto._mouseDestroy = function() {
        this.element.off('.uiMouseTouch');
        originalMouseDestroy.call(this);
    };
})(jQuery);
