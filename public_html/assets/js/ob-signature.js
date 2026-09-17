/**
 * LOKA - OB Pass Slip canvas signature pad (Plan #22)
 * Vanilla JS: mouse + touch draw, clear, exports a PNG data URL.
 *
 * Usage:
 *   ObSignature.init({ canvas: '#obSigCanvas', pad: '#obSigPad' });
 *   ObSignature.isEmpty()          -> bool
 *   ObSignature.toDataUrl()        -> 'data:image/png;base64,...' or ''
 *   ObSignature.clear()
 */
(function () {
    'use strict';

    var canvas = null;
    var ctx = null;
    var drawing = false;
    var hasInk = false;
    var last = null;

    function pos(e) {
        var r = canvas.getBoundingClientRect();
        var t = e.touches && e.touches[0] ? e.touches[0] : e;
        return {
            x: (t.clientX - r.left) * (canvas.width / r.width),
            y: (t.clientY - r.top) * (canvas.height / r.height)
        };
    }

    function start(e) {
        e.preventDefault();
        drawing = true;
        last = pos(e);
    }

    function move(e) {
        if (!drawing) return;
        e.preventDefault();
        var p = pos(e);
        ctx.strokeStyle = '#1a1a2e';
        ctx.lineWidth = 2.4;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.beginPath();
        ctx.moveTo(last.x, last.y);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        last = p;
        hasInk = true;
    }

    function end() {
        drawing = false;
    }

    window.ObSignature = {
        init: function (opts) {
            canvas = document.querySelector(opts.canvas || '#obSigCanvas');
            if (!canvas) return;
            // Fixed internal resolution; CSS keeps it responsive
            canvas.width = 520;
            canvas.height = 160;
            ctx = canvas.getContext('2d');
            this.clear();

            canvas.addEventListener('mousedown', start);
            canvas.addEventListener('mousemove', move);
            canvas.addEventListener('mouseup', end);
            canvas.addEventListener('mouseleave', end);
            canvas.addEventListener('touchstart', start, { passive: false });
            canvas.addEventListener('touchmove', move, { passive: false });
            canvas.addEventListener('touchend', end);

            var self = this;
            var clearBtn = document.querySelector(opts.clear || '#obSigClear');
            if (clearBtn) {
                clearBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    self.clear();
                });
            }
        },

        clear: function () {
            if (!ctx) return;
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            hasInk = false;
        },

        isEmpty: function () {
            return !hasInk;
        },

        toDataUrl: function () {
            if (!hasInk) return '';
            return canvas.toDataURL('image/png');
        }
    };
})();
