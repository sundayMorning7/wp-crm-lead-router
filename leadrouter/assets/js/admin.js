// Reserved for future admin scripts

jQuery(function ($) {
    $(document).on('click', '.lr-broadcast-btn', function (e) {
        e.preventDefault();

        const lead_id = $(this).data('id');
        const $cell = $(this).closest('td');
        const group_id = $cell.find('.lr-group-select').val() || 0;

        if (!confirm('Відправити broadcast для lead #' + lead_id + ' (group_id=' + group_id + ')?')) {
            return;
        }

        $.post(ajaxurl, {
            action: 'leadrouter_manual_broadcast',
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


(function () {
    function qs(sel, root) {
        return (root || document).querySelector(sel);
    }

    function qsa(sel, root) {
        return Array.from((root || document).querySelectorAll(sel));
    }

    function openModal() {
        const m = qs('#lr-logs-modal');
        if (!m) return;
        m.style.display = 'block';
        document.body.style.overflow = 'hidden';

        const mj = qs('.lr-modal-json');
        if (!mj) return;
        mj.style.display = 'none';
        document.body.style.overflow = '';
        mj.innerHTML = '';
    }

    function closeModal() {
        const m = qs('#lr-logs-modal');
        if (!m) return;
        m.style.display = 'none';
        document.body.style.overflow = '';
    }

    function setLoading(isLoading) {
        const m = qs('#lr-logs-modal');
        if (!m) return;
        qs('.lr-modal-loading', m).style.display = isLoading ? 'block' : 'none';
        qs('.lr-modal-content', m).style.display = isLoading ? 'none' : 'block';
    }

    function setContent(html) {
        const m = qs('#lr-logs-modal');
        if (!m) return;
        qs('.lr-modal-content', m).innerHTML = html;
    }

    document.addEventListener('click', async function (e) {
        const btn = e.target.closest('.lr-view-logs');
        if (btn) {
            e.preventDefault();


            const leadId = parseInt(btn.getAttribute('data-lead-id') || '0', 10);
            if (!leadId) return;

            openModal();
            setContent('');
            setLoading(true);


            const fd = new FormData();
            fd.append('action', (window.LeadRouterLogViewer && LeadRouterLogViewer.getLeadLogsAction) ? LeadRouterLogViewer.getLeadLogsAction : 'leadrouter_get_lead_send_logs');
            fd.append('nonce', (window.LeadRouterLogViewer && LeadRouterLogViewer.nonce) ? LeadRouterLogViewer.nonce : '');
            fd.append('lead_id', String(leadId));

            try {
                const res = await fetch((window.LeadRouterLogViewer && LeadRouterLogViewer.ajaxUrl) ? LeadRouterLogViewer.ajaxUrl : ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: fd
                });
                const json = await res.json();
                if (json && json.success && json.data && json.data.html) {
                    setContent(json.data.html);
                } else {
                    setContent('<div class="notice notice-error"><p>Failed to load logs.</p></div>');
                }
            } catch (err) {
                setContent('<div class="notice notice-error"><p>AJAX error.</p></div>');
            } finally {
                setLoading(false);
            }
        }

        // close handlers
        if (e.target.closest('.lr-modal-close') || e.target.closest('.lr-modal-backdrop')) {
            e.preventDefault();
            closeModal();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeModal();
    });


})();


jQuery(function ($) {


    const fromField = $('input[name="md-date-from"]')
    const toField = $('input[name="md-date-to"]')

    // by default, the dates look like "April 3, 2017"
    // let's make them look like "2017-04-03" for convenience
    const customDateFormat = 'yy-mm-dd'

    // create datepickers
    fromField.datepicker({dateFormat: customDateFormat})
    toField.datepicker({dateFormat: customDateFormat})

    // prevent a user from choosing an incorrect date interval
    fromField.on('change', function () {
        toField.datepicker('option', 'minDate', fromField.val())
    });
    toField.on('change', function () {
        fromField.datepicker('option', 'maxDate', toField.val())
    });


    $(".md_range_datepicker").datepicker({
        numberOfMonths: 3,
        //showCurrentAtPos: 2,
        firstDay: 1,
        //showButtonPanel: true,
        beforeShowDay: function (date) {
            var date1 = $.datepicker.parseDate($.datepicker._defaults.dateFormat, $("#md_range_from").val());
            var date2 = $.datepicker.parseDate($.datepicker._defaults.dateFormat, $("#md_range_to").val());
            return [true, date1 && ((date.getTime() == date1.getTime()) || (date2 && date >= date1 && date <= date2)) ? "dp-highlight" : ""];
        },
        onSelect: function (dateText, inst) {
            var date1 = $.datepicker.parseDate($.datepicker._defaults.dateFormat, $("#md_range_from").val());
            var date2 = $.datepicker.parseDate($.datepicker._defaults.dateFormat, $("#md_range_to").val());
            var selectedDate = $.datepicker.parseDate($.datepicker._defaults.dateFormat, dateText);

            if (!date1 || date2) {
                $("#md_range_from").val(dateText);
                $("#md_range_to").val("");
                $(this).datepicker();
            } else if (selectedDate < date1) {
                $("#md_range_to").val($("#md_range_from").val());
                $("#md_range_from").val(dateText);
                $(this).datepicker();
            } else {
                $("#md_range_to").val(dateText);
                $(this).datepicker();
            }
        }
    });


    $('.js-create-aggregate-report').on('click', function (e) {
        let $this = $(this);
        let from_val = $("#md_range_from").val();
        let to_val = $("#md_range_to").val();

        if (!from_val) {
            alert('Enter start date');
            return false;
        }

        let from_date = new Date($.datepicker.parseDate($.datepicker._defaults.dateFormat, from_val));

        const month = String(from_date.getMonth() + 1).padStart(2, '0'); // getMonth() повертає 0-11
        const day = String(from_date.getDate()).padStart(2, '0');
        const year = from_date.getFullYear();

        let from_date_formated = `${year}-${month}-${day}`;

        let to_date_formated = '';
        if (to_val) {
            let to_date = new Date($.datepicker.parseDate($.datepicker._defaults.dateFormat, to_val));
            const month2 = String(to_date.getMonth() + 1).padStart(2, '0'); // getMonth() повертає 0-11
            const day2 = String(to_date.getDate()).padStart(2, '0');
            const year2 = to_date.getFullYear();
            to_date_formated = `${year2}-${month2}-${day2}`;
        }


        let table_title = 'Aggregate report';


        var data = {
            action: 'leadrouter_get_report',
            nonce: LeadRouterLogViewer.nonce,
            date_from: from_date_formated,
            date_to: to_date_formated,
        };


        $.ajax({
            type: 'post',
            dataType: 'json',
            url: LeadRouterLogViewer.ajaxUrl,
            data: data,
            beforeSend: function () {
                $this.addClass('loading');
                $this.prop('disabled', true);
            },
            success: function (response) {

                setTimeout(function () {
                    $this.removeClass('loading');
                    $this.prop('disabled', false);


                    if (response.success) {
                        let rows = '';
                        let head = '<tr>';
                        response.data.columns.map(function (cell, i) {
                            head += '<td>' + cell + '</td>';
                        })
                        head += '<tr>';

                        var rowsHtml = '';
                        var cols = response.data.columns;   // порядок колонок

                        response.data.rows.forEach(function (line) {

                            rowsHtml += '<tr>';

                            cols.forEach(function (col) {
                                var cell = (line[col] !== undefined && line[col] !== null)
                                    ? line[col]
                                    : '';

                                rowsHtml += '<td>' + cell + '</td>';
                            });

                            rowsHtml += '</tr>';

                        });


                        let table = '<table><thead>' + head + '</thead><tbody>' + rowsHtml + '</tbody></table>';

                        $('.md-report-panel_result').slideUp(400, function () {
                            $(this)
                                .html('')
                                .append('<div class="md-report-panel_result_head"><h2>' + table_title + ' from ' + from_date_formated + ' to ' + to_date_formated + '</h2><a class="button" href="' + response.file_url_xlsx + '">Download file (.xlsx)</a><a class="button" href="' + response.file_url_csv + '">Download file (.csv)</a><a href="#" class="button js-md-empty-report-result">Clear & hide</a> </div>')
                                .append(table)
                                .slideDown();
                        });

                    } else {
                        $('.md-report-panel_result').slideUp(400, function () {
                            $(this)
                                .html('')
                                .append('<div class="md-report-panel_result_head"><h2>' + table_title + ' from ' + from_date_formated + ' to ' + to_date_formated + '</h2><h2>' + response.error + '</h2><a href="#" class="button js-md-empty-report-result">Clear & hide</a> </div>')
                                .slideDown();
                        });
                    }


                }, 1500);


            }
        });


    });


    $('.js-create-aggregate-invoice').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();

        let $this = $(this);
        let from_val = $("#md_range_from").val();
        let to_val = $("#md_range_to").val();
        let broker_id = $('.js-choose-broker-id').val();

        if (!from_val) {
            alert('Enter start date');
            return false;
        }

        if (!broker_id) {
            alert('Choose broker');
            return false;
        }


        let table_title = 'Aggregate report';


        let mon_sat_rate = prompt('Rate in Mon-Sut', 17.50);
        let sun_rate = prompt('Rate in Sun', 13);
        table_title = 'Aggregate invoice for ' + $('.js-choose-broker-id option:selected').text() + ' ';


        let from_date = new Date($.datepicker.parseDate($.datepicker._defaults.dateFormat, from_val));

        const month = String(from_date.getMonth() + 1).padStart(2, '0'); // getMonth() повертає 0-11
        const day = String(from_date.getDate()).padStart(2, '0');
        const year = from_date.getFullYear();

        let from_date_formated = `${year}-${month}-${day}`;

        let to_date_formated = '';
        if (to_val) {
            let to_date = new Date($.datepicker.parseDate($.datepicker._defaults.dateFormat, to_val));
            const month2 = String(to_date.getMonth() + 1).padStart(2, '0'); // getMonth() повертає 0-11
            const day2 = String(to_date.getDate()).padStart(2, '0');
            const year2 = to_date.getFullYear();
            to_date_formated = `${year2}-${month2}-${day2}`;
        }


        var data = {
            action: 'leadrouter_get_report',
            nonce: LeadRouterLogViewer.nonce,
            date_from: from_date_formated,
            date_to: to_date_formated,
            mon_sat_rate: mon_sat_rate,
            sun_rate: sun_rate,
            partner_id: broker_id,
        };


        $.ajax({
            type: 'post',
            dataType: 'json',
            url: LeadRouterLogViewer.ajaxUrl,
            data: data,
            beforeSend: function () {
                $this.addClass('loading');
                $this.prop('disabled', true);
            },
            success: function (response) {


                setTimeout(function () {
                    $this.removeClass('loading');
                    $this.prop('disabled', false);


                    if (response.success) {
                        let rows = '';
                        let head = '<tr>';
                        response.data.columns.map(function (cell, i) {
                            head += '<td>' + cell + '</td>';
                        })
                        head += '<tr>';

                        var rowsHtml = '';
                        var cols = response.data.columns;   // порядок колонок

                        response.data.rows.forEach(function (line) {

                            rowsHtml += '<tr>';

                            cols.forEach(function (col, index) {
                                var cell = (line[col] !== undefined && line[col] !== null)
                                    ? line[col]
                                    : '';

                                if (index === 4 || index === 5) {
                                    cell = parseFloat(cell).toFixed(2) + ' $';
                                }

                                rowsHtml += '<td>' + cell + '</td>';
                            });

                            rowsHtml += '</tr>';

                        });

                        var footHtml = '';
                        if (rows.length > 1) {
                            footHtml = '<tfoot><tr>';

                            Object.entries(response.data.totals).forEach(function ([key, value]) {

                                if (key === 'Gross' || key === 'Total Due') {
                                    value = parseFloat(value).toFixed(2) + ' $';
                                }

                                footHtml += '<td>' + key + ': <strong>' + value + '</strong></td>';
                            });

                            footHtml += '</tr></tfoot>';
                        }


                        let table = '<table><thead>' + head + '</thead><tbody>' + rowsHtml + '</tbody>' + footHtml + '</table>';

                        $('.md-report-panel_result').slideUp(400, function () {
                            $(this)
                                .html('')
                                .append('<div class="md-report-panel_result_head"><h2>' + table_title + ' from ' + from_date_formated + ' to ' + to_date_formated + '</h2><a class="button" href="' + response.file_url_xlsx + '">Download file (.xlsx)</a><a class="button" href="' + response.file_url_csv + '">Download file (.csv)</a><a href="#" class="button js-md-empty-report-result">Clear & hide</a> </div>')
                                .append(table)
                                .slideDown();
                        });

                    } else {
                        $('.md-report-panel_result').slideUp(400, function () {
                            $(this)
                                .html('')
                                .append('<div class="md-report-panel_result_head"><h2>' + table_title + ' from ' + from_date_formated + ' to ' + to_date_formated + '</h2><h2>' + response.error + '</h2><a href="#" class="button js-md-empty-report-result">Clear & hide</a> </div>')
                                .slideDown();
                        });
                    }


                }, 1500);


            }
        });


    });


    $('body').on('click', '.js-md-empty-report-result', function (e) {
        e.preventDefault();
        e.stopPropagation();

        $('.md-report-panel_result').slideUp(400, function () {
            $(this).html('');
        });

    });

    $(document).on('click', '.lr-lead-delete', function (e) {

        e.preventDefault();

        var $btn = $(this);
        var leadId = parseInt($btn.data('lead-id'), 10) || 0;
        if (!leadId) return;

        if (!confirm('Delete lead #' + leadId + ' and all related logs?')) return;

        $btn.prop('disabled', true);

        lrShowRowLoader(leadId);

        $.post(LeadRouterLogViewer.ajaxUrl, {
            action: LeadRouterLogViewer.deleteLeadsCascadeAction,
            nonce: LeadRouterLogViewer.nonce,
            lead_id: leadId
        }, function (resp) {

            if (!resp || !resp.success) {
                lrHideRowLoader(leadId);
                $btn.prop('disabled', false);
                alert('Delete error');
                return;
            }

            $('#leadrow-' + leadId).fadeOut(150, function () {
                $(this).remove();
            });

        }).fail(function () {
            lrHideRowLoader(leadId);
            $btn.prop('disabled', false);
            alert('Delete error');
        });

    });

    $(document).on('click', '.lr-bulk-delete-btn', function () {

        var leadIds = [];

        $('input.lr-lead-cb:checked').each(function () {
            var v = parseInt($(this).val(), 10);
            if (v) leadIds.push(v);
        });

        if (!leadIds.length) {
            alert('No leads selected');
            return;
        }

        if (!confirm('Delete ' + leadIds.length + ' leads and all related logs?')) {
            return;
        }

        // показати лоадер на кожному рядку одразу
        leadIds.forEach(function (id) {
            lrShowRowLoader(id);
        });

        $.post(LeadRouterLogViewer.ajaxUrl, {
            action: LeadRouterLogViewer.deleteLeadsCascadeAction,
            nonce: LeadRouterLogViewer.nonce,
            lead_ids: leadIds
        }, function (resp) {

            if (!resp || !resp.success) {
                // прибрати лоадер з усіх
                leadIds.forEach(function (id) {
                    lrHideRowLoader(id);
                });
                alert('Delete error');
                return;
            }

            var deleted = (resp.data && resp.data.deleted) ? resp.data.deleted : leadIds;

            // видалені — прибираємо рядки
            deleted.forEach(function (id) {
                $('#leadrow-' + id).fadeOut(150, function () {
                    $(this).remove();
                });
            });

            // якщо щось лишилось (не видалилось) — прибираємо лоадер
            leadIds.forEach(function (id) {
                if (deleted.indexOf(id) === -1) lrHideRowLoader(id);
            });

        }).fail(function () {
            leadIds.forEach(function (id) {
                lrHideRowLoader(id);
            });
            alert('Delete error');
        });

    });

    function lrShowRowLoader(leadId) {
        var $tr = $('#leadrow-' + leadId);
        if (!$tr.length) return null;

        // якщо вже є
        if ($tr.find('.lr-row-loader').length) {
            $tr.addClass('lr-row-loading');
            return $tr;
        }

        $tr.css('position', 'relative');
        $tr.addClass('lr-row-loading');

        var overlay = '' +
            '<div class="lr-row-loader" aria-hidden="true">' +
            '  <div class="lr-row-spinner"></div>' +
            '</div>';

        $tr.find('td').eq(0).append(overlay);
        return $tr;
    }

    function lrHideRowLoader(leadId) {
        var $tr = $('#leadrow-' + leadId);
        if (!$tr.length) return;
        $tr.removeClass('lr-row-loading');
        $tr.find('.lr-row-loader').remove();
    }

    $(document).on('click', '.lr-json-view', function () {

        var json = $(this).attr('data-json-raw') || '';

        try {
            json = JSON.parse(json);
        } catch (e) {
        }

        $('.lr-modal-json').slideDown().jsonViewer(json);


    });

    $(document).on('click', '.lr-json-close', function () {

        $('.lr-modal-json').slideUp(120).html('');

    });


    /*
        $('.js-sent-lead-to-pride').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            let $this = $(this);
            let post_id = $this.data('post-id');

            let isBoss = confirm("Resend the lead to PRIDE manually ?");


            if (isBoss) {
                $this.addClass('loading');
                $this.prop('disabled', true);


                var data = {
                    action: 'md_admin_ajax_send_lead_pride',
                    nonce: md_admin_js.nonce,
                    post_id: post_id
                };


                $.ajax({
                    type: 'post',
                    dataType: 'json',
                    url: md_admin_js.url,
                    data: data,
                    beforeSend: function () {
                        $('.modal-search-result').slideUp();
                    },
                    success: function (response) {

                        if (response.status == 'success') {

                            setTimeout(function () {
                                $this.parent().html('<i class="md_check_success"><span>Success</span></i>');
                            }, 700)


                        } else {


                            alert(response.text ?? 'Something went wrong. Check the logs inside the lead and reload page' + response.error_server);
                            $this.parent().find('i').removeClass('md_check_success md_check_await').addClass('md_check_error').find('span').text('Error');

                            if (response.text) {
                                location.reload();
                            }


                            console.log(response.error);
                            console.log(response.error_server);

                        }


                    }
                });

            }
        });
    */

    /*
    $('.js-sent-lead').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();

        let $this = $(this);
        let post_id = $this.data('post-id');

        let isBoss = confirm("Resend the lead manually ?");


        if (isBoss) {

            $this.addClass('loading');
            $this.prop('disabled', true);


            var data = {
                action: 'md_admin_ajax_send_lead',
                nonce: md_admin_js.nonce,
                post_id: post_id
            };


            $.ajax({
                type: 'post',
                dataType: 'json',
                url: md_admin_js.url,
                data: data,
                beforeSend: function () {
                    $('.modal-search-result').slideUp();
                },
                success: function (response) {

                    if (response.status == 'success') {

                        setTimeout(function () {
                            $this.parent().html('<i class="md_check_success"><span>Success</span></i>');
                        }, 700)


                    } else {


                        alert(response.text ?? 'Something went wrong. Check the logs inside the lead and reload page');
                        $this.parent().find('i').removeClass('md_check_success md_check_await').addClass('md_check_error').find('span').text('Error');

                        if (response.text) {
                            location.reload();
                        }


                        console.log(response.error);
                        console.log(response.error_server);

                    }


                }
            });


        }

        return false;
    })

*/

    $('body').on('click', '.js-show-report-panel', function (e) {
        e.preventDefault();
        e.stopPropagation();

        let $this = $(this);

        $('.md-report-panel').slideToggle(400, function () {
                if ($this.text() === 'Show report panel') {
                    $this.text('Hide report panel')
                } else {
                    $this.text('Show report panel')
                }
            }
        );
    });

    $('input[type="checkbox"][name="lead_id[]"]').click(function () {

        let count = $('input[type="checkbox"][name="lead_id[]"]:checked').length;


        $('.md_checked_count').remove();
        if (count > 0) {
            $('<div class="md_checked_count">Checked: <b class="mod-green">' + count + '</b></div>').insertAfter(".wrap .count_title_buy_status");
        }
    });



    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function (m) {
            return ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[m]);
        });
    }

    function injectInlineConfirm($wrap, groupLabel, onYes) {
        // remove old confirm if any
        $wrap.find('.lr-inline-confirm').remove();

        var html = '' +
            '<span class="lr-inline-confirm" style="margin-left:8px;">' +
            '<span style="margin-right:6px;">Send to group “' + escapeHtml(groupLabel) + '”?</span>' +
            '<button type="button" class="button button-small lr-confirm-yes">Yes</button> ' +
            '<button type="button" class="button button-small lr-confirm-cancel">Cancel</button>' +
            '</span>';

        $wrap.append(html);

        $wrap.find('.lr-confirm-cancel').on('click', function () {
            $wrap.find('.lr-inline-confirm').remove();
        });

        $wrap.find('.lr-confirm-yes').on('click', function () {
            $wrap.find('.lr-inline-confirm').remove();
            onYes();
        });
    }

    $(document).on('click', '.lr-broadcast-btn', function () {
        var $btn = $(this);
        var leadId = parseInt($btn.data('id'), 10) || 0;
        if (!leadId) return;

        var $wrap = $btn.closest('.lr-broadcast-inline');
        var $sel = $wrap.find('.lr-group-select');
        var groupId = parseInt($sel.val(), 10) || 0;

        var groupLabel = $sel.find('option:selected').text() || '';

        var doSend = function () {
            $btn.prop('disabled', true);
            $sel.prop('disabled', true);
            lrShowRowLoader(leadId);

            // optional inline spinner
            $wrap.find('.lr-inline-status').remove();
            $wrap.append('<span class="lr-inline-status" style="margin-left:8px;">Sending…</span>');

            $.ajax({
                url: (window.LeadRouterLogViewer && LeadRouterLogViewer.ajaxUrl) ? LeadRouterLogViewer.ajaxUrl : ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: (window.LeadRouterLogViewer && LeadRouterLogViewer.manualBroadcastAction) ? LeadRouterLogViewer.manualBroadcastAction : 'leadrouter_manual_broadcast',
                    nonce: (window.LeadRouterLogViewer && LeadRouterLogViewer.nonce) ? LeadRouterLogViewer.nonce : '',
                    lead_id: leadId,
                    group_id: groupId
                }
            }).done(function (resp) {
                if (!resp || !resp.success) {
                    var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Failed';
                    $wrap.find('.lr-inline-status').text('Error: ' + msg);
                    return;
                }

                var rowHtml = resp.data && resp.data.row_html ? resp.data.row_html : '';
                if (rowHtml) {
                    // Replace whole <tr> of this lead
                    var $tr = $btn.closest('tr');
                    $tr.replaceWith(rowHtml);
                } else {
                    $wrap.find('.lr-inline-status').text('Sent');
                }

            }).fail(function (xhr) {
                $wrap.find('.lr-inline-status').text('Error');
            }).always(function () {
                // If row was replaced, old elements are gone; otherwise re-enable
                $btn.prop('disabled', false);
                $sel.prop('disabled', false);
                lrHideRowLoader(leadId);
            });
        };

        // Confirm only for конкретної групи (groupId > 0)
        /*if (groupId > 0) {
            injectInlineConfirm($wrap, groupLabel, doSend);
            return;
        }*/

        // Auto → no confirm
        doSend();
    });

    $(document).on('click', '.lr-bulk-send-btn', function () {

        var $btn = $(this);
        var $wrap = $btn.closest('.lr-bulk-send');
        var $status = $wrap.find('.lr-bulk-status');
        var groupId = parseInt($wrap.find('.lr-bulk-group-select').val(), 10) || 0;
        var groupLabel = $wrap.find('.lr-bulk-group-select option:selected').text() || '';

        var leadIds = [];
        $('input.lr-lead-cb:checked').each(function () {
            var v = parseInt($(this).val(), 10);
            if (v) leadIds.push(v);
        });

        if (!leadIds.length) {
            $status.text('No leads selected');
            return;
        }

        // confirm only for specific group
        if (groupId > 0) {
            if (!window.confirm('Send ' + leadIds.length + ' lead(s) to group "' + groupLabel + '"?')) {
                return;
            }
        }

        leadIds.forEach(function(id){ lrShowRowLoader(id); });

        $btn.prop('disabled', true);
        $wrap.find('.lr-bulk-group-select').prop('disabled', true);
        $status.text('Sending ' + leadIds.length + '…');

        $.ajax({
            url: (window.LeadRouterLogViewer && LeadRouterLogViewer.ajaxUrl) ? LeadRouterLogViewer.ajaxUrl : ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: (window.LeadRouterLogViewer && LeadRouterLogViewer.manualBroadcastBulkAction) ? LeadRouterLogViewer.manualBroadcastBulkAction : 'leadrouter_manual_broadcast_bulk',
                nonce: (window.LeadRouterLogViewer && LeadRouterLogViewer.nonce) ? LeadRouterLogViewer.nonce : '',
                group_id: groupId,
                lead_ids: leadIds
            }
        }).done(function (resp) {

            if (!resp || !resp.success) {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Failed';
                $status.text('Error: ' + msg);
                return;
            }

            var rowsHtml = (resp.data && resp.data.rows_html) ? resp.data.rows_html : {};
            var results = (resp.data && resp.data.results) ? resp.data.results : {};

            var ok = 0, fail = 0;

            leadIds.forEach(function (id) {
                var r = results[id];
                if (r && r.ok) ok++; else fail++;

                if (rowsHtml[id]) {
                    $('#leadrow-' + id).replaceWith(rowsHtml[id]);
                }
            });

            $status.text('Done. Sent: ' + ok + ', Failed: ' + fail);

            // optional: uncheck "select all" and individual checks after success
            $('input.lr-lead-cb').prop('checked', false);
            $('.wp-list-table thead input[type="checkbox"], .wp-list-table tfoot input[type="checkbox"]').prop('checked', false);

        }).fail(function () {
            $status.text('Error');
        }).always(function () {
            $btn.prop('disabled', false);
            $wrap.find('.lr-bulk-group-select').prop('disabled', false);
            leadIds.forEach(function(id){ lrHideRowLoader(id); });
        });

    });

});



