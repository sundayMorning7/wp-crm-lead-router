/*
 * LeadRouter — кабінет партнера: деталі ліда.
 *
 * Клік по #id у таблиці транзакцій відкриває Tabler-модалку
 * #lr-lead-details-modal (data-bs-toggle) і підвантажує деталі AJAX-ом.
 * Сервер віддає лише ліди, за які цьому партнеру було списання
 * (partner_id — виключно із серверної сесії).
 */
(function () {
    'use strict';

    if (typeof window.LRLeadDetails === 'undefined') { return; }

    var cfg = window.LRLeadDetails;
    var modalEl = document.getElementById('lr-lead-details-modal');
    if (!modalEl) { return; }

    var titleEl = modalEl.querySelector('.lr-ld-title');
    var bodyEl  = modalEl.querySelector('.lr-ld-body');

    modalEl.addEventListener('show.bs.modal', function (e) {
        var btn = e.relatedTarget;
        if (!btn) { return; }

        var leadId = btn.getAttribute('data-lead-id') || '';
        if (titleEl) { titleEl.textContent = '#' + leadId; }
        if (bodyEl) { bodyEl.innerHTML = '<div class="text-secondary">' + cfg.i18n.loading + '</div>'; }

        var body = new URLSearchParams({
            action: cfg.action,
            nonce: cfg.nonce,
            lead_id: leadId
        });

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (!res || !res.success || !res.data) {
                var msg = (res && res.data && res.data.message) ? res.data.message : cfg.i18n.error;
                bodyEl.innerHTML = '<div class="alert alert-danger" role="alert"></div>';
                bodyEl.querySelector('.alert').textContent = msg;
                return;
            }

            if (titleEl) { titleEl.textContent = res.data.title || ('#' + leadId); }

            var fields = res.data.fields || [];
            var html = '<div class="datagrid">';
            fields.forEach(function () {
                html += '<div class="datagrid-item"><div class="datagrid-title"></div><div class="datagrid-content"></div></div>';
            });
            html += '</div>';
            bodyEl.innerHTML = html;

            // Значення — лише через textContent (без інʼєкцій)
            var items = bodyEl.querySelectorAll('.datagrid-item');
            fields.forEach(function (f, i) {
                items[i].querySelector('.datagrid-title').textContent = f.label || '';
                items[i].querySelector('.datagrid-content').textContent = (f.value && String(f.value).trim() !== '') ? f.value : '—';
            });
        }).catch(function () {
            bodyEl.innerHTML = '<div class="alert alert-danger" role="alert"></div>';
            bodyEl.querySelector('.alert').textContent = cfg.i18n.error;
        });
    });
})();
