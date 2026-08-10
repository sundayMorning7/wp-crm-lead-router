/**
 * Поле «Власник» на сторінці партнера: підказка існуючих тегів + показ того,
 * у якому вигляді значення збережеться (нижній регістр, один пробіл).
 *
 * Поле малює Carbon Fields уже після завантаження сторінки, тому чіпляємось
 * делегуванням на focusin, а не шукаємо input одразу.
 */
(function () {
    'use strict';

    var cfg = window.LRPartnerOwner || {};
    var metaKey = cfg.metaKey || '_lr_partner_owner';
    var owners = Array.isArray(cfg.owners) ? cfg.owners : [];
    var listId = 'lr-owner-options';

    function ensureDatalist() {
        var list = document.getElementById(listId);
        if (list) {
            return list;
        }

        list = document.createElement('datalist');
        list.id = listId;

        owners.forEach(function (owner) {
            var option = document.createElement('option');
            option.value = owner;
            list.appendChild(option);
        });

        document.body.appendChild(list);

        return list;
    }

    function isOwnerInput(el) {
        return el
            && el.tagName === 'INPUT'
            && typeof el.name === 'string'
            && el.name.indexOf(metaKey) !== -1;
    }

    function attach(input) {
        if (input.dataset.lrOwnerBound) {
            return;
        }
        input.dataset.lrOwnerBound = '1';

        if (owners.length) {
            ensureDatalist();
            input.setAttribute('list', listId);
            input.setAttribute('autocomplete', 'off');
        }

        if (cfg.i18n && cfg.i18n.hint) {
            input.setAttribute('title', cfg.i18n.hint);
        }

        // показуємо той самий вигляд, у якому значення ляже в базу
        input.addEventListener('blur', function () {
            var value = input.value.trim().toLowerCase().replace(/\s+/g, ' ');
            if (value !== input.value) {
                input.value = value;
                input.dispatchEvent(new Event('input', {bubbles: true}));
                input.dispatchEvent(new Event('change', {bubbles: true}));
            }
        });
    }

    document.addEventListener('focusin', function (e) {
        if (isOwnerInput(e.target)) {
            attach(e.target);
        }
    });
})();
