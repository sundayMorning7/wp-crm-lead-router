jQuery(document).ready(function($){
    $(document).on('click', '.lr-send-test-lead-btn', function(){
        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }
        var partnerId = $btn.data('partner-id');
        var $status = $btn.siblings('.lr-send-test-lead-status');
        var originalText = $btn.text();
        $btn.prop('disabled', true).text('Sending...');
        $status.text('Отправка...');
        $.post(LRAjax.ajaxUrl, {
            action: 'lr_send_test_lead',
            nonce: LRAjax.nonce,
            partner_id: partnerId
        }, function(resp){
            if(resp.success) {
                var leadId = (resp.data && resp.data.lead_id) ? resp.data.lead_id : '';
                var statusCode = (resp.data && resp.data.status_code) ? resp.data.status_code : '';
                var isOk = !!(resp.data && resp.data.ok);
                var details = [];
                if (leadId) {
                    details.push('Lead #' + leadId);
                }
                if (statusCode) {
                    details.push('HTTP ' + statusCode);
                }
                var suffix = details.length ? ' (' + details.join(', ') + ')' : '';
                if (isOk) {
                    $status.html('<span style="color:green;">✔ Успешно' + suffix + '</span>');
                } else {
                    var err = (resp.data && resp.data.error_message) ? resp.data.error_message : 'Send failed';
                    $status.html('<span style="color:#d63638;">✖ ' + err + suffix + '</span>');
                }
            } else {
                var msg = resp.data && resp.data.message ? resp.data.message : 'Ошибка';
                $status.html('<span style="color:red;">✖ ' + msg + '</span>');
            }
        }).fail(function(){
            $status.html('<span style="color:red;">✖ Ошибка AJAX</span>');
        }).always(function(){
            $btn.prop('disabled', false).text(originalText);
        });
    });
});
