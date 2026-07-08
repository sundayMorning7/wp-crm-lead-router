/*
 * LeadRouter — кабінет партнера: лоадери на кнопках.
 *
 * Tabler-нативний клас .btn-loading показує спінер і ховає текст кнопки.
 * - на submit будь-якої форми всередині .lr-cabinet — кнопка submit стає loading;
 * - для майбутніх (не-submit/AJAX) кнопок — атрибут data-lr-loading або window.lrBtnLoading().
 */
(function () {
    'use strict';

    function setLoading(btn, on) {
        if (!btn) { return; }
        if (on) {
            btn.classList.add('btn-loading');
            btn.setAttribute('aria-busy', 'true');
            btn.disabled = true;
        } else {
            btn.classList.remove('btn-loading');
            btn.removeAttribute('aria-busy');
            btn.disabled = false;
        }
    }

    // Публічний хелпер для майбутніх AJAX-кнопок
    window.lrBtnLoading = setLoading;

    // Сабміт форм у кабінеті → лоадер на кнопці submit
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.closest || !form.closest('.lr-cabinet')) { return; }
        // Скасований сабміт (напр., відхилений confirm()) — без лоадера
        if (e.defaultPrevented) { return; }
        var btn = form.querySelector('button[type="submit"], input[type="submit"], button:not([type])');
        // Невелика затримка, щоб не блокувати відправку значення кнопки (тут не критично)
        setLoading(btn, true);
    }, false);

    // Не-submit кнопки майбутнього: data-lr-loading
    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('[data-lr-loading]') : null;
        if (btn) { setLoading(btn, true); }
    }, false);
})();
