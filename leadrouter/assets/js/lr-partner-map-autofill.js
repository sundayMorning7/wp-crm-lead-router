(function($){
    'use strict';

    const sleep = (ms) => new Promise(r => setTimeout(r, ms));

    function triggerInput(el){
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function findComplexContainerFromButton(btn){
        return btn.closest('.cf-field.cf-complex') || null;
    }

    function getPresetFromButton(btn, complex){
        if (complex) {
            const select = complex.querySelector('.js-lr-map-preset-select');
            if (select && select.value) return select.value;
        }

        if (!btn) return 'bats';
        return btn.getAttribute('data-lr-map-profile') || btn.getAttribute('data-map-preset') || 'bats';
    }

    function getDefaultsByPreset(preset){
        const cfg = window.LRPartnerMap || {};
        const key = preset || 'bats';

        if (cfg.presets && Array.isArray(cfg.presets[key])) {
            return cfg.presets[key];
        }

        return Array.isArray(cfg.defaults) ? cfg.defaults : [];
    }

    function getSettingsByPreset(preset){
        const cfg = window.LRPartnerMap || {};
        const key = preset || 'bats';

        if (cfg.presetSettings && cfg.presetSettings[key] && typeof cfg.presetSettings[key] === 'object') {
            return cfg.presetSettings[key];
        }

        return null;
    }

    function setFieldValue(selector, value){
        const el = document.querySelector(selector);
        if (!el) return;
        el.value = value ?? '';
        triggerInput(el);
    }

    function setCheckboxValue(selector, checked){
        const el = document.querySelector(selector);
        if (!el) return;

        el.checked = !!checked;
        triggerInput(el);
    }

    function applyPresetSettings(preset){
        const settings = getSettingsByPreset(preset);
        if (!settings) return;

        setFieldValue('input[name$="[_leadrouter_partner_endpoint]"]', settings.endpoint || '');
        setFieldValue('select[name$="[_leadrouter_partner_auth_variant]"]', settings.auth_variant || 'header');
        // setFieldValue('input[name$="[_leadrouter_partner_api_key]"]', settings.api_key || '');
        setFieldValue('input[name$="[_leadrouter_partner_api_key_header]"]', settings.api_key_header || 'X-API-Key');
        setCheckboxValue('input[name$="[_leadrouter_partner_require_ok_json]"]', !!settings.require_ok_json);
    }

    async function clearComplex(complex){
        if (!complex) return false;

        const msg = (window.LRPartnerMap && LRPartnerMap.i18n && LRPartnerMap.i18n.confirm_reset) || 'Reset mapping?';
        if (!confirm(msg)) return false;

        const groupSel = ':scope .cf-complex__group';

        let groups = Array.from(complex.querySelectorAll(groupSel));
        for (let i = groups.length - 1; i >= 0; i--) {
            const gr = groups[i];
            let removeBtn =
                gr.querySelector(':scope .cf-complex__group-actions .cf-complex__group-action[title="Remove"]') ||
                gr.querySelector(':scope .cf-complex__group-actions .dashicons-trash')?.closest('button') ||
                Array.from(gr.querySelectorAll(':scope .cf-complex__group-actions .cf-complex__group-action'))
                    .find(b => /remove|delete|видалити|удалить/i.test(b.textContent || ''));

            if (removeBtn) {
                removeBtn.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
                await sleep(120);
            }
        }

        // safety-pass без .item(-1)
        while (true) {
            const list = complex.querySelectorAll(groupSel);
            if (!list.length) break;
            const last = list[list.length - 1];
            let btn =
                last.querySelector(':scope .cf-complex__group-actions .cf-complex__group-action[title="Remove"]') ||
                last.querySelector(':scope .cf-complex__group-actions .dashicons-trash')?.closest('button');
            if (!btn) break;
            btn.click();
            await sleep(120);
        }

        return true;
    }

    // чекаємо, поки CF домалює нову групу й поля
    async function waitForNewGroup(complex, prevCount, timeout = 3000){
        const start = performance.now();
        while (performance.now() - start < timeout) {
            const groups = complex.querySelectorAll('.cf-complex__group');
            if (groups.length > prevCount) return groups[groups.length - 1];
            await sleep(50);
        }
        return null;
    }

    async function addRow(complex, row) {
        const addBtn = complex.querySelector(':scope button.cf-complex__inserter-button');
        if (!addBtn) return;

        const beforeCount = complex.querySelectorAll(':scope .cf-complex__group').length;
        addBtn.click();

        const last = await waitForNewGroup(complex, beforeCount, 4000);
        if (!last) return;

        const inputOur = last.querySelector('input[name$="[_our_key]"]');
        const inputTheir = last.querySelector('input[name$="[_their_key]"]');
        const selectTr = last.querySelector('select[name$="[_transform]"]');
        const inputDef = last.querySelector('input[name$="[_default_value]"]');

        if (inputOur)  { inputOur.value  = row.our_key ?? '';      triggerInput(inputOur); }
        if (inputTheir){ inputTheir.value= row.their_key ?? '';    triggerInput(inputTheir); }
        if (selectTr)  { selectTr.value  = row.transform ?? 'none';triggerInput(selectTr); }
        if (inputDef)  { inputDef.value  = row.default_value ?? '';triggerInput(inputDef); }
    }

    $(document).on('click', '.js-lr-autofill-map, .js-lr-apply-map-preset', async function (e){
        e.preventDefault();

        const complex = findComplexContainerFromButton(e.currentTarget);
        if (!complex) return;

        const preset = getPresetFromButton(e.currentTarget, complex);
        const defaults = getDefaultsByPreset(preset);
        if (!defaults.length) return;

        applyPresetSettings(preset);

        // ВАЖЛИВО: чекаємо очищення
        const confirmed = await clearComplex(complex);
        if (!confirmed) return;

        // Послідовно додаємо рядки (щоб DOM точно встигав)
        for (const row of defaults) {
            await addRow(complex, row);
            await sleep(60);
        }

        if (window.LRPartnerMap?.i18n?.done) {
            alert(LRPartnerMap.i18n.done);
        }
    });

})(jQuery);
