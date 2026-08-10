/**
 * Компактний вигляд таблиці лідів: розкриття контролу відправки.
 *
 * Скрипт нічого не відправляє й не знає про API — він лише показує/ховає
 * поповер із наявним контролом (.lr-broadcast-inline). Самі обробники живуть
 * в admin.js і делеговані на document, тож від того, що селект схований до
 * кліку, поведінка не змінюється.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var table = document.querySelector('.lr-leads-compact');
        if (!table) {
            return;
        }

        function closeAll(except) {
            table.querySelectorAll('.lr-c-send-pop').forEach(function (pop) {
                if (pop === except) {
                    return;
                }
                pop.hidden = true;
                var btn = pop.parentNode.querySelector('.lr-c-send-toggle');
                if (btn) {
                    btn.classList.remove('is-open');
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
        }

        table.addEventListener('click', function (e) {
            var btn = e.target.closest('.lr-c-send-toggle');
            if (!btn) {
                return;
            }

            e.preventDefault();

            var pop = btn.parentNode.querySelector('.lr-c-send-pop');
            if (!pop) {
                return;
            }

            var open = pop.hidden;
            closeAll(pop);

            pop.hidden = !open;
            btn.classList.toggle('is-open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');

            if (open) {
                var sel = pop.querySelector('.lr-group-select');
                if (sel) {
                    sel.focus();
                }
            }
        });

        // клік поза поповером і Esc — закривають
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.lr-c-send')) {
                closeAll(null);
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeAll(null);
            }
        });
    });
}());
