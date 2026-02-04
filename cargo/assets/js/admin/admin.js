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


    $('.js-create-aggregate-invoice, .js-create-aggregate-report').on('click', function (e) {


        let $this = $(this);
        let from_val = $("#md_range_from").val();
        let to_val = $("#md_range_to").val();

        if (!from_val) {
            alert('Enter start date');
            return false;
        }

        let type_report = 'report';
        let mon_sut_rate = 17.50;
        let sun_rate = 13.00;
        let broker_id = $('.js-choose-broker-id').val();
        let table_title = 'Aggregate report';


        if ($this.hasClass('js-create-aggregate-invoice')) {
            let mon_sut_rate = prompt('Rate in Mon-Sut', 17.50);
            let sun_rate = prompt('Rate in Sun', 13);
            type_report = 'invoice';
            table_title = 'Aggregate invoice for ' + $('.js-choose-broker-id option:selected').text() + ' ';

            if (!broker_id) {
                alert('Choose broker');
                return false;
            }
        }


        let from_date = new Date($.datepicker.parseDate($.datepicker._defaults.dateFormat, from_val));

        const month = String(from_date.getMonth() + 1).padStart(2, '0'); // getMonth() повертає 0-11
        const day = String(from_date.getDate()).padStart(2, '0');
        const year = from_date.getFullYear();

        let from_date_formated = `${month}-${day}-${year}`;


        let to_date_formated = '';
        if (to_val) {
            let to_date = new Date($.datepicker.parseDate($.datepicker._defaults.dateFormat, to_val));
            const month2 = String(to_date.getMonth() + 1).padStart(2, '0'); // getMonth() повертає 0-11
            const day2 = String(to_date.getDate()).padStart(2, '0');
            const year2 = to_date.getFullYear();
            to_date_formated = `${month2}-${day2}-${year2}`;
        }


        var data = {
            action: 'md_ajax_create_report',
            nonce: md_admin_js.nonce,
            from_date: from_date_formated,
            to_date: to_date_formated,
            mon_sut_rate: mon_sut_rate,
            sun_rate: sun_rate,
            broker_id: broker_id,
            type_report: type_report,
        };


        $.ajax({
            type: 'post',
            dataType: 'json',
            url: md_admin_js.url,
            data: data,
            beforeSend: function () {
                $this.addClass('loading');
                $this.prop('disabled', true);
            },
            success: function (response) {

                setTimeout(function () {
                    $this.removeClass('loading');
                    $this.prop('disabled', false);


                    if (!response.error) {
                        let rows = '';
                        let head = '';
                        response.excel_table.map(function (line, i) {
                            let row = '<tr>';
                            line.map(function (cell) {
                                row += '<td>' + cell + '</td>';
                            });
                            row += '<tr>';

                            if (i === 0) {
                                head = row;
                            } else {
                                rows += row;
                            }

                        })


                        let table = '<table><thead>' + head + '</thead><tbody>' + rows + '</tbody></table>';

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


    $('.js-sent-lead-to-pride ').on('click', function (e) {
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


    if ($('body.post-type-lead').length) {
        $('.wrap h1 + a').after('<button class="js-show-report-panel page-title-action">Show report panel</button>');

        $(".count_title_est_today_sent").clone().show().insertAfter(".wrap h1 + a + .page-title-action");
        $(".count_title_buy_status").clone().show().insertAfter(".wrap .count_title_est_today_sent");


    }

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

    $('input[type="checkbox"][name="post[]"]').click(function () {

        let count = $('input[type="checkbox"][name="post[]"]:checked').length;


        $('.md_checked_count').remove();
        if (count > 0) {
            $('<div class="md_checked_count">Checked: <b class="mod-green">' + count + '</b></div>').insertAfter(".wrap .count_title_buy_status");
        }
    });





});