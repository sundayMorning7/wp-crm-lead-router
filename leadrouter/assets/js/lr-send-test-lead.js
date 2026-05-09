jQuery(document).ready(function($){
    $(document).on('click', '.lr-send-test-lead-btn', function(){
        var $btn = $(this);
        var partnerId = $btn.data('partner-id');
        var $status = $btn.siblings('.lr-send-test-lead-status');
        $status.text('Отправка...');
        $.post(LRAjax.ajaxUrl, {
            action: 'lr_send_test_lead',
            nonce: LRAjax.nonce,
            partner_id: partnerId
        }, function(resp){
            if(resp.success) {
                $status.html('<span style="color:green;">✔ Успешно</span>');
            } else {
                var msg = resp.data && resp.data.message ? resp.data.message : 'Ошибка';
                $status.html('<span style="color:red;">✖ ' + msg + '</span>');
            }
        }).fail(function(){
            $status.html('<span style="color:red;">✖ Ошибка AJAX</span>');
        });
    });
});
