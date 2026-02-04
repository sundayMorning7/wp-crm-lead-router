// Reserved for future admin scripts

jQuery(function($) {
    $(document).on('click', '.lr-broadcast-btn', function (e) {
        e.preventDefault();

        const lead_id = $(this).data('id');
        const $cell   = $(this).closest('td');
        const group_id = $cell.find('.lr-group-select').val() || 0;

        if (!confirm('Відправити broadcast для lead #' + lead_id + ' (group_id=' + group_id + ')?')) {
            return;
        }

        $.post(ajaxurl, {
            action:  'leadrouter_manual_broadcast',
            lead_id: lead_id,
            group_id: group_id
        }, function (resp) {
            if (resp.success) {
                alert('Broadcast OK: ' + resp.data);
            } else {
                alert('Помилка: ' + resp.data);
            }
        });
    });
});
