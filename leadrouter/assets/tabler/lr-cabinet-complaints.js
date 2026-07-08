/*
 * LeadRouter — кабінет партнера: скарги на лід.
 *
 * Спільна Tabler-модалка #lr-complaint-modal відкривається кнопкою «Complain»
 * у рядку таблиці лідів. Кнопка передає lead_id/label через data-атрибути.
 * Сабміт — AJAX (admin-ajax) з nonce; partner_id сервер бере лише із сесії.
 * Після успіху кнопку рядка замінюємо на бейдж «Complaint sent».
 */
(function () {
    'use strict';

    if (typeof window.LRComplaints === 'undefined') { return; }

    var cfg = window.LRComplaints;
    var modalEl = document.getElementById('lr-complaint-modal');
    if (!modalEl) { return; }

    var form     = modalEl.querySelector('.lr-complaint-form');
    var alertBox = modalEl.querySelector('.lr-complaint-alert');
    var leadIdEl = modalEl.querySelector('.lr-complaint-lead-id');
    var leadLbl  = modalEl.querySelector('.lr-complaint-lead-label');
    var topicEl  = modalEl.querySelector('.lr-complaint-topic');
    var msgEl    = modalEl.querySelector('.lr-complaint-message');
    var submitEl = modalEl.querySelector('.lr-complaint-submit');

    function showAlert(type, text) {
        if (!alertBox) { return; }
        alertBox.innerHTML = '<div class="alert alert-' + type + '" role="alert"></div>';
        alertBox.querySelector('.alert').textContent = text;
    }

    function clearAlert() {
        if (alertBox) { alertBox.innerHTML = ''; }
    }

    // Заповнення модалки з даних кнопки, що її відкрила (Bootstrap event)
    modalEl.addEventListener('show.bs.modal', function (e) {
        var btn = e.relatedTarget;
        clearAlert();
        if (form) { form.reset(); }
        if (!btn) { return; }
        var lid = btn.getAttribute('data-lead-id') || '';
        var lbl = btn.getAttribute('data-lead-label') || ('#' + lid);
        if (leadIdEl) { leadIdEl.value = lid; }
        if (leadLbl) { leadLbl.textContent = lbl; }
    });

    // Замінити кнопку рядка на бейдж «Complaint sent»
    function markRowSent(leadId) {
        var btn = document.querySelector('.lr-complaint-btn[data-lead-id="' + leadId + '"]');
        if (!btn) { return; }
        var badge = document.createElement('span');
        badge.className = 'badge bg-secondary-lt';
        badge.textContent = cfg.i18n && cfg.i18n.sent_badge ? cfg.i18n.sent_badge : 'Complaint sent';
        btn.parentNode.replaceChild(badge, btn);
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            clearAlert();

            var leadId = leadIdEl ? leadIdEl.value : '';
            var topic  = topicEl ? topicEl.value : '';
            var message = msgEl ? msgEl.value : '';

            if (submitEl) {
                submitEl.disabled = true;
                submitEl.classList.add('btn-loading');
            }

            var body = new URLSearchParams();
            body.append('action', cfg.action);
            body.append('nonce', cfg.nonce);
            body.append('lead_id', leadId);
            body.append('topic', topic);
            body.append('message', message);

            fetch(cfg.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).then(function (resp) {
                return resp.json().then(function (json) {
                    return { ok: resp.ok, json: json };
                });
            }).then(function (res) {
                var data = res.json && res.json.data ? res.json.data : {};
                if (res.json && res.json.success) {
                    showAlert('success', data.message || 'OK');
                    markRowSent(leadId);
                    // Закриваємо модалку трохи згодом, щоб користувач побачив підтвердження.
                    // Через нативний data-bs-dismiss — не залежимо від глобального window.bootstrap.
                    setTimeout(function () {
                        var dismiss = modalEl.querySelector('[data-bs-dismiss="modal"]');
                        if (dismiss) { dismiss.click(); }
                    }, 1200);
                } else {
                    showAlert('danger', data.message || (cfg.i18n && cfg.i18n.network_error) || 'Error');
                }
            }).catch(function () {
                showAlert('danger', (cfg.i18n && cfg.i18n.network_error) || 'Network error');
            }).finally(function () {
                if (submitEl) {
                    submitEl.disabled = false;
                    submitEl.classList.remove('btn-loading');
                }
            });
        });
    }
})();
