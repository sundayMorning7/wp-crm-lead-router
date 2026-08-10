/**
 * Симулятор слотів — збір сценарію з форми і перемальовування результату.
 *
 * Скрипт НІЧОГО не рахує: усю арифметику робить PHP (LeadRouter_Slot_Planner +
 * LeadRouter_Slot_Sim), щоб схема колонок у пісочниці лишалась буквально тим
 * самим кодом, що на сторінці реальної групи. Тут — лише DOM і debounce.
 *
 * Сценарій живе тільки в DOM: перезавантаження сторінки скидає його.
 */
(function () {
    'use strict';

    var cfg = window.LRSlotSim || {};
    var i18n = cfg.i18n || {};

    var rows, tpl, busy, planBox, resultBox;
    var timer = null;
    var seq = 0; // щоб пізня відповідь не перекрила свіжішу

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        rows      = document.getElementById('lr-sim-rows');
        tpl       = document.getElementById('lr-sim-row-tpl');
        busy      = document.getElementById('lr-sim-busy');
        planBox   = document.getElementById('lr-sim-plan');
        resultBox = document.getElementById('lr-sim-result');

        if (!rows || !planBox) {
            return;
        }

        var wrap = document.querySelector('.lr-sim');

        // будь-яка зміна будь-якого поля → перерахунок
        wrap.addEventListener('input', onFieldChange);
        wrap.addEventListener('change', onFieldChange);
        wrap.addEventListener('click', onClick);

        var manual = document.getElementById('lr-sim-manual');
        if (manual) {
            manual.addEventListener('change', toggleManual);
        }
    }

    function onFieldChange(e) {
        if (!e.target.matches('input')) {
            return;
        }

        if (e.target.id === 'lr-sim-volume') {
            var out = document.getElementById('lr-sim-volume-out');
            if (out) {
                out.textContent = e.target.value + '%';
            }
        }

        schedule();
    }

    function onClick(e) {
        var btn = e.target.closest('button');
        if (!btn) {
            return;
        }

        if (btn.id === 'lr-sim-load') {
            loadGroup();
            return; // перерахунок піде після відповіді
        }

        if (btn.id === 'lr-sim-add') {
            addRow();
        } else if (btn.id === 'lr-sim-add5') {
            for (var i = 0; i < 5; i++) {
                addRow(30);
            }
        } else if (btn.id === 'lr-sim-bulk') {
            bulkLimit();
        } else if (btn.classList.contains('lr-sim-dup')) {
            duplicateRow(btn.closest('tr'));
        } else if (btn.classList.contains('lr-sim-del')) {
            btn.closest('tr').remove();
        } else {
            return;
        }

        schedule();
    }

    /* ===================== рядки таблиці ===================== */

    function rowCount() {
        return rows.querySelectorAll('.lr-sim-row').length;
    }

    function newRow() {
        return tpl.content.firstElementChild.cloneNode(true);
    }

    function addRow(limit) {
        var max = (cfg.limits && cfg.limits.partners) || 200;
        if (rowCount() >= max) {
            window.alert((i18n.tooMany || 'Ліміт: %d').replace('%d', max));
            return null;
        }

        var tr = newRow();
        tr.querySelector('.lr-f-label').value = (i18n.newPartner || 'Партнер') + ' ' + (rowCount() + 1);
        if (typeof limit === 'number') {
            tr.querySelector('.lr-f-limit').value = limit;
        }
        rows.appendChild(tr);

        return tr;
    }

    /**
     * Копія партнера з ТИМ САМИМ власником — саме на кластерах ламається
     * пакування, тож дублювання має його створювати. Якщо у джерела власник
     * порожній (тобто він сам собі власник), проставляємо спільний тег обом —
     * інакше копія стала б окремим власником і кластера не вийшло б.
     */
    function duplicateRow(src) {
        if (!src) {
            return;
        }

        var max = (cfg.limits && cfg.limits.partners) || 200;
        if (rowCount() >= max) {
            window.alert((i18n.tooMany || 'Ліміт: %d').replace('%d', max));
            return;
        }

        var srcOwner = src.querySelector('.lr-f-owner');
        var owner = srcOwner.value.trim();
        if (owner === '') {
            owner = slug(src.querySelector('.lr-f-label').value) || 'owner';
            srcOwner.value = owner;
        }

        var tr = newRow();
        tr.querySelector('.lr-f-label').value = src.querySelector('.lr-f-label').value + ' (2)';
        tr.querySelector('.lr-f-limit').value = src.querySelector('.lr-f-limit').value;
        tr.querySelector('.lr-f-owner').value = owner;
        tr.querySelector('.lr-f-start').value = src.querySelector('.lr-f-start').value;
        tr.querySelector('.lr-f-end').value   = src.querySelector('.lr-f-end').value;

        src.parentNode.insertBefore(tr, src.nextSibling);
    }

    function bulkLimit() {
        var input = document.getElementById('lr-sim-bulk-limit');
        var val = input ? parseInt(input.value, 10) : NaN;
        if (isNaN(val) || val < 0) {
            return;
        }

        rows.querySelectorAll('.lr-f-limit').forEach(function (el) {
            el.value = val;
        });
    }

    function slug(text) {
        return String(text).toLowerCase().replace(/\s+/g, '-').replace(/[^\wа-яіїєґ\-]/gi, '').slice(0, 40);
    }

    function toggleManual() {
        var box = document.getElementById('lr-sim-manual-box');
        var on  = document.getElementById('lr-sim-manual').checked;
        if (box) {
            box.hidden = !on;
        }
        schedule();
    }

    /* ===================== стартова точка з реальної групи ===================== */

    /**
     * Підвантажити склад реальної групи як ЧЕРНЕТКУ сценарію. Сервер лише
     * читає; далі все, що менеджер тут крутить, на групу не впливає.
     */
    function loadGroup() {
        var sel = document.getElementById('lr-sim-group');
        var dow = document.getElementById('lr-sim-dow');
        var box = document.getElementById('lr-sim-load-notes');

        if (!sel || !sel.value) {
            notes(box, [i18n.pickGroup || 'Pick a group'], 'warning');
            return;
        }

        notes(box, [i18n.loading || '…'], '');

        var body = new URLSearchParams();
        body.set('action', 'lr_slot_sim_load');
        body.set('nonce', cfg.nonce || '');
        body.set('group', sel.value);
        body.set('dow', dow ? dow.value : '');

        window.fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (res) {
            return res.json().catch(function () {
                return { success: false };
            });
        }).then(function (json) {
            if (!json || !json.success || !json.data) {
                var msg = (json && json.data && json.data.message) || i18n.error || 'Error';
                notes(box, [msg], 'error');
                return;
            }

            fillFrom(json.data);

            var head = (i18n.loaded || '%s / %s')
                .replace('%s', json.data.group || '')
                .replace('%s', json.data.day || '');
            var list = [head].concat(json.data.notes || []);
            if (!json.data.partners || !json.data.partners.length) {
                list.push(i18n.noPartners || '');
            }
            notes(box, list, json.data.partners && json.data.partners.length ? 'ok' : 'warning');

            schedule();
        }).catch(function () {
            notes(box, [i18n.network || 'Network error'], 'error');
        });
    }

    /** Заповнити форму даними групи */
    function fillFrom(data) {
        document.getElementById('lr-sim-n').value = data.n;
        document.getElementById('lr-sim-l').value = data.l;

        rows.innerHTML = '';
        (data.partners || []).forEach(function (p) {
            var tr = newRow();
            tr.querySelector('.lr-f-label').value = p.label;
            tr.querySelector('.lr-f-limit').value = p.limit;
            tr.querySelector('.lr-f-owner').value = p.owner;
            tr.querySelector('.lr-f-start').value = p.start_h;
            tr.querySelector('.lr-f-end').value   = p.end_h;
            rows.appendChild(tr);
        });
    }

    function notes(box, lines, level) {
        if (!box) {
            return;
        }

        box.className = 'lr-sim-load-notes' + (level ? ' lr-lv-' + level : '');
        box.innerHTML = '';
        lines.filter(Boolean).forEach(function (text) {
            var p = document.createElement('p');
            p.textContent = text;
            box.appendChild(p);
        });
    }

    /* ===================== сценарій → PHP ===================== */

    function num(el, def) {
        if (!el) {
            return def;
        }
        var v = parseInt(el.value, 10);

        return isNaN(v) ? def : v;
    }

    function scenario() {
        var partners = [];
        rows.querySelectorAll('.lr-sim-row').forEach(function (tr, i) {
            partners.push({
                id:      i + 1,
                label:   tr.querySelector('.lr-f-label').value,
                limit:   num(tr.querySelector('.lr-f-limit'), 0),
                owner:   tr.querySelector('.lr-f-owner').value,
                start_h: num(tr.querySelector('.lr-f-start'), 0),
                end_h:   num(tr.querySelector('.lr-f-end'), 24)
            });
        });

        var perHour = [];
        for (var h = 0; h < 24; h++) {
            var el = document.querySelector('.lr-f-hour[data-hour="' + h + '"]');
            perHour.push(num(el, 0));
        }

        var manual = document.getElementById('lr-sim-manual');

        return {
            n: num(document.getElementById('lr-sim-n'), 6),
            l: num(document.getElementById('lr-sim-l'), 0),
            partners: partners,
            flow: {
                mode: manual && manual.checked ? 'manual' : 'uniform',
                window: [
                    num(document.getElementById('lr-sim-win-start'), 8),
                    num(document.getElementById('lr-sim-win-end'), 22)
                ],
                volume_pct: num(document.getElementById('lr-sim-volume'), 100),
                per_hour: perHour
            }
        };
    }

    function schedule() {
        window.clearTimeout(timer);
        timer = window.setTimeout(recalc, cfg.debounce || 250);
    }

    function recalc() {
        var my = ++seq;
        setBusy(true, '');

        var body = new URLSearchParams();
        body.set('action', 'lr_slot_sim');
        body.set('nonce', cfg.nonce || '');
        body.set('scenario', JSON.stringify(scenario()));

        window.fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (res) {
            return res.json().catch(function () {
                return { success: false };
            });
        }).then(function (json) {
            if (my !== seq) {
                return; // прийшла відповідь на застарілий запит
            }

            if (!json || !json.success || !json.data) {
                var msg = (json && json.data && json.data.message) || i18n.error || 'Error';
                setBusy(false, msg);
                return;
            }

            planBox.innerHTML   = json.data.planHtml || '';
            resultBox.innerHTML = json.data.simHtml || '';
            setBusy(false, '');
        }).catch(function () {
            if (my === seq) {
                setBusy(false, i18n.network || 'Network error');
            }
        });
    }

    function setBusy(on, error) {
        if (!busy) {
            return;
        }

        if (error) {
            busy.hidden = false;
            busy.textContent = error;
            busy.classList.add('lr-sim-busy-error');
            return;
        }

        busy.classList.remove('lr-sim-busy-error');
        busy.textContent = i18n.calc || '…';
        busy.hidden = !on;
    }
}());
