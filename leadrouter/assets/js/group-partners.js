/**
 * Склад групи на сторінці leadrouter_group: додати/вилучити партнера.
 *
 * Обробники навішані делегуванням на document, бо блок домальовує Carbon
 * Fields (вміст html-поля вставляється через innerHTML — inline-скрипти там
 * не виконуються), а після кожної дії ми ще й перемальовуємо його самі.
 *
 * Дані беремо з контейнера .lr-gp: data-group і data-nonce.
 */
(function () {
    'use strict';

    var cfg = window.LRGroupPartners || {};
    var ajaxUrl = cfg.ajaxUrl || (window.ajaxurl || '/wp-admin/admin-ajax.php');

    function message(root, text, ok) {
        var msg = root.querySelector('.lr-gp-msg');
        if (!msg) {
            return;
        }

        msg.textContent = text;
        msg.className = 'lr-gp-msg ' + (ok ? 'is-ok' : 'is-err');

        setTimeout(function () {
            msg.textContent = '';
            msg.className = 'lr-gp-msg';
        }, 4000);
    }

    function send(root, action, partnerId) {
        root.classList.add('is-busy');

        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: action,
                nonce: root.dataset.nonce,
                group: root.dataset.group,
                partner: partnerId
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            root.classList.remove('is-busy');

            if (!res || !res.success) {
                message(root, (res && res.data && res.data.message) || (cfg.i18n && cfg.i18n.error) || 'Помилка', false);
                return;
            }

            var body = root.querySelector('.lr-gp-body');
            if (body) {
                body.innerHTML = res.data.body;
            }

            message(root, res.data.message || '', true);

            // план слотів живе в сусідньому полі — оновлюємо на місці
            var plan = document.querySelector('.lr-slot-plan');
            if (plan && res.data.plan) {
                plan.outerHTML = res.data.plan;
            }
        })
        .catch(function () {
            root.classList.remove('is-busy');
            message(root, (cfg.i18n && cfg.i18n.network) || 'Помилка мережі', false);
        });
    }

    document.addEventListener('click', function (e) {
        var root = e.target.closest('.lr-gp');
        if (!root) {
            return;
        }

        var remove = e.target.closest('.lr-gp-remove');
        if (remove) {
            e.preventDefault();

            var ask = (cfg.i18n && cfg.i18n.confirmRemove) || 'Вилучити «%s» з групи?';
            if (!window.confirm(ask.replace('%s', remove.dataset.label || ''))) {
                return;
            }

            send(root, 'lr_group_partner_remove', remove.dataset.partner);
            return;
        }

        if (e.target.closest('.lr-gp-add-btn')) {
            e.preventDefault();

            var select = root.querySelector('.lr-gp-select');
            var pid = select ? parseInt(select.value, 10) : 0;

            if (!pid) {
                message(root, (cfg.i18n && cfg.i18n.pickPartner) || 'Оберіть партнера', false);
                return;
            }

            var option = select.options[select.selectedIndex];
            var from = option ? option.dataset.from : '';

            if (from) {
                var askMove = (cfg.i18n && cfg.i18n.confirmMove) || 'Партнер зараз у групі «%s». Перенести сюди?';
                if (!window.confirm(askMove.replace('%s', from))) {
                    return;
                }
            }

            send(root, 'lr_group_partner_assign', pid);
        }
    });
})();
