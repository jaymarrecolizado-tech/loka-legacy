/**
 * LOKA - OB Pass Slip plate dropdown (Plan #22)
 * Green = vehicle free on the Official Business date.
 * Red = vehicle already booked that day. Shows a small modal on pick.
 *
 * Usage:
 *   ObPlateSelect.init({
 *     select: '#obPlateSelect',
 *     date: '#obDate',
 *     modal: '#obPlateBusyModal',
 *     trips: [{plate_number, start_datetime, end_datetime, destination, requester_name}]
 *   });
 */
(function () {
    'use strict';

    var COLOR_FREE = '#198754';
    var COLOR_BUSY = '#dc3545';
    var selectEl = null;
    var dateEl = null;
    var modalEl = null;
    var trips = [];
    var lastWarned = '';

    function ymd() {
        return dateEl && dateEl.value ? dateEl.value : '';
    }

    function overlapsDay(trip, day) {
        if (!day) return false;
        return trip.start_datetime < (day + ' 23:59:59')
            && trip.end_datetime > (day + ' 00:00:00');
    }

    function tripForPlate(plate, day) {
        if (!plate || !day) return null;
        for (var i = 0; i < trips.length; i++) {
            if (trips[i].plate_number === plate && overlapsDay(trips[i], day)) {
                return trips[i];
            }
        }
        return null;
    }

    function paint() {
        if (!selectEl) return;
        var day = ymd();
        var selectedBusy = false;

        for (var i = 0; i < selectEl.options.length; i++) {
            var opt = selectEl.options[i];
            if (!opt.value) {
                opt.style.color = '';
                continue;
            }
            var busy = !!tripForPlate(opt.value, day);
            opt.dataset.onTrip = busy ? '1' : '0';
            opt.style.color = busy ? COLOR_BUSY : COLOR_FREE;
            var base = opt.dataset.baseLabel || opt.textContent;
            opt.dataset.baseLabel = base;
            opt.textContent = busy ? (base + ' — on trip') : base;
            if (opt.selected && busy) selectedBusy = true;
        }

        if (!selectEl.value) {
            selectEl.style.color = '';
        } else {
            selectEl.style.color = selectedBusy ? COLOR_BUSY : COLOR_FREE;
        }
    }

    function prettyDay(day) {
        if (!day || day.length < 10) return day;
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        var y = day.slice(0, 4);
        var m = parseInt(day.slice(5, 7), 10);
        var d = parseInt(day.slice(8, 10), 10);
        if (!m || !d) return day;
        return months[m - 1] + ' ' + d + ', ' + y;
    }

    function fillModal(trip, plate, day) {
        var plateNode = document.getElementById('obPlateBusyPlate');
        var dateNode = document.getElementById('obPlateBusyDate');
        var detailNode = document.getElementById('obPlateBusyDetail');
        if (plateNode) plateNode.textContent = plate;
        if (dateNode) dateNode.textContent = prettyDay(day);
        if (!detailNode) return;
        if (!trip) {
            detailNode.textContent = 'This vehicle is already scheduled on that day.';
            return;
        }
        var dest = trip.destination ? trip.destination : 'an official trip';
        var who = trip.requester_name ? trip.requester_name : 'another employee';
        var start = trip.start_label || trip.start_datetime;
        var end = trip.end_label || trip.end_datetime;
        detailNode.textContent = 'Booked by ' + who + ' for ' + dest + ' (' + start + ' – ' + end + ').';
    }

    function showBusyModal(trip, plate, day) {
        fillModal(trip, plate, day);
        if (typeof bootstrap === 'undefined' || !modalEl) {
            window.alert(plate + ' is already on a trip on ' + day + '.');
            return;
        }
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    function onPlateChange() {
        paint();
        var plate = selectEl.value;
        var day = ymd();
        if (!plate || !day) {
            lastWarned = '';
            return;
        }
        var trip = tripForPlate(plate, day);
        if (!trip) {
            lastWarned = '';
            return;
        }
        var key = plate + '|' + day;
        if (lastWarned === key) return;
        lastWarned = key;
        showBusyModal(trip, plate, day);
    }

    function onDateChange() {
        lastWarned = '';
        paint();
        var plate = selectEl.value;
        var day = ymd();
        if (!plate || !day) return;
        var trip = tripForPlate(plate, day);
        if (trip) {
            lastWarned = plate + '|' + day;
            showBusyModal(trip, plate, day);
        }
    }

    function clearPlate() {
        if (!selectEl) return;
        selectEl.value = '';
        lastWarned = '';
        paint();
        if (typeof bootstrap !== 'undefined' && modalEl) {
            var inst = bootstrap.Modal.getInstance(modalEl);
            if (inst) inst.hide();
        }
    }

    window.ObPlateSelect = {
        init: function (opts) {
            selectEl = document.querySelector(opts.select);
            dateEl = document.querySelector(opts.date);
            modalEl = document.querySelector(opts.modal);
            trips = Array.isArray(opts.trips) ? opts.trips : [];
            if (!selectEl) return;

            for (var i = 0; i < selectEl.options.length; i++) {
                var opt = selectEl.options[i];
                if (opt.value && !opt.dataset.baseLabel) {
                    opt.dataset.baseLabel = opt.textContent.trim();
                }
            }

            paint();
            selectEl.addEventListener('change', onPlateChange);
            if (dateEl) dateEl.addEventListener('change', onDateChange);

            var pickOther = document.getElementById('obPlateBusyPickOther');
            if (pickOther) pickOther.addEventListener('click', clearPlate);
        }
    };
})();
