jQuery(function($){
    function decodeHtmlEntities(s){
        // розкодуємо &quot; &amp; тощо у звичайний текст
        var ta = document.createElement('textarea');
        ta.innerHTML = s;
        return ta.value;
    }

    $(document).on('click', '.js-lr-show-json', function(){
        // 1) намагаємось взяти СИРИЙ атрибут (без jQuery .data() кeшування)
        var rawAttr = $(this).attr('data-json');
        var raw = rawAttr != null ? rawAttr : $(this).data('json');

        // 2) якщо це не рядок — конвертуємо одразу
        if (typeof raw !== 'string') {
            try { raw = JSON.stringify(raw, null, 2); } catch(e) { raw = String(raw); }
        } else {
            // 3) роздекодуємо html-ентіті
            raw = decodeHtmlEntities(raw);
            // 4) спробуємо красиво відформатувати, якщо це валідний JSON
            try {
                var parsed = JSON.parse(raw);
                raw = JSON.stringify(parsed, null, 2);
            } catch(e) {
                // не JSON — показуємо як є
            }
        }

        var $modal = $('#lr-json-modal');
        if (!$modal.length) {
            $modal = $(
                '<div id="lr-json-modal" class="lr-modal">' +
                '<div class="lr-modal__inner">' +
                '<pre class="lr-pre"></pre>' +
                '<button class="button lr-close">Закрити</button>' +
                '</div>' +
                '<div class="lr-modal__backdrop"></div>' +
                '</div>'
            );
            $('body').append($modal);
        }

        $modal.find('.lr-pre').text(raw);
        $modal.addClass('is-open');
    });

    $(document).on('click', '.lr-close, .lr-modal__backdrop', function(){
        $('#lr-json-modal').removeClass('is-open');
    });
});
