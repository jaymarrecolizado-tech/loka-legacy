/**
 * LOKA - OB canvas signature pad (Plans #22 + #25)
 * Vanilla JS: mouse + touch draw, clear, exports a PNG data URL.
 *
 * Default pads (Guard, OB card) keep the fixed 520x160 bitmap.
 * Pass fit: true on the CoA kiosk so the bitmap follows the pad's CSS box
 * (x devicePixelRatio, cap 2), the stroke thickens for fingers, and the
 * export is downscaled to ~800px wide so a retina pad cannot blow
 * post_max_size.
 *
 * Usage:
 *   ObSignature.init({ canvas: '#obSigCanvas', pad: '#obSigPad', clear: '#obSigClear' });
 *   ObSignature.init({ canvas: '#c', pad: '#p', clear: '#cl', fit: true });
 *   ObSignature.isEmpty()   -> bool
 *   ObSignature.toDataUrl() -> 'data:image/png;base64,...' or ''
 *   ObSignature.clear()
 */
(function () {
    'use strict';

    var canvas = null;
    var ctx = null;
    var padEl = null;
    var fit = false;
    var dpr = 1;
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
        if (!drawing || !ctx) return;
        e.preventDefault(); // block page scroll while drawing (Plan #25)
        var p = pos(e);
        ctx.strokeStyle = '#1a1a2e';
        ctx.lineWidth = fit ? 2.6 * dpr : 2.4; // thicker round stroke for fingers
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

    /**
     * Size the bitmap then (re)acquire ctx. Setting canvas.width resets the
     * 2d context — always call getContext after a resize.
     * fit: pad CSS box × devicePixelRatio (cap 2).
     * otherwise: fixed 520×160 (Guard / approve pads).
     */
    function sizeBitmap(keepInk) {
        if (!canvas) return;
        var w;
        var h;
        if (fit && padEl) {
            var rect = padEl.getBoundingClientRect();
            var cssW = Math.max(1, Math.round(rect.width || 520));
            var cssH = Math.max(1, Math.round(rect.height || 220));
            dpr = Math.min(2, window.devicePixelRatio || 1);
            w = Math.round(cssW * dpr);
            h = Math.round(cssH * dpr);
        } else {
            dpr = 1;
            w = 520;
            h = 160;
        }

        var snapshot = null;
        if (keepInk && hasInk && canvas.width && canvas.height) {
            snapshot = document.createElement('canvas');
            snapshot.width = canvas.width;
            snapshot.height = canvas.height;
            snapshot.getContext('2d').drawImage(canvas, 0, 0);
        }

        if (canvas.width !== w || canvas.height !== h) {
            canvas.width = w;
            canvas.height = h;
        }
        ctx = canvas.getContext('2d');
        if (snapshot) {
            ctx.drawImage(snapshot, 0, 0, canvas.width, canvas.height);
        }
    }

    window.ObSignature = {
        init: function (opts) {
            canvas = document.querySelector(opts.canvas || '#obSigCanvas');
            if (!canvas) return;
            padEl = canvas.closest(opts.pad || '#obSigPad') || canvas.parentElement;
            fit = !!opts.fit;
            sizeBitmap(false);
            this.clear();

            if (fit && !canvas.dataset.obFitResize) {
                canvas.dataset.obFitResize = '1';
                window.addEventListener('resize', function () {
                    sizeBitmap(true);
                });
            }

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

        /** PNG data URL; fit pads export downscaled to ~800px wide. */
        toDataUrl: function () {
            if (!hasInk) return '';
            if (!fit || canvas.width <= 800) {
                return canvas.toDataURL('image/png');
            }
            var scale = 800 / canvas.width;
            var out = document.createElement('canvas');
            out.width = 800;
            out.height = Math.round(canvas.height * scale);
            out.getContext('2d').drawImage(canvas, 0, 0, out.width, out.height);
            return out.toDataURL('image/png');
        }
    };
})();
