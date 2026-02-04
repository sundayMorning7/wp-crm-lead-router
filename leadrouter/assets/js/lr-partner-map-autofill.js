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

    async function clearComplex(complex){
        if (!complex) return;

        const msg = (window.LRPartnerMap && LRPartnerMap.i18n && LRPartnerMap.i18n.confirm_reset) || 'Reset mapping?';
        if (!confirm(msg)) return;

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

    $(document).on('click', '.js-lr-autofill-map', async function (e){
        e.preventDefault();

        const complex = findComplexContainerFromButton(e.currentTarget);
        if (!complex) return;

        const defaults = (window.LRPartnerMap && Array.isArray(LRPartnerMap.defaults)) ? LRPartnerMap.defaults : [];
        if (!defaults.length) return;

        // ВАЖЛИВО: чекаємо очищення
        await clearComplex(complex);

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
