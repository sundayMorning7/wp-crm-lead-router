// Скрипт для аккордеону з відкритим лише одним пунктом
// Отримуємо всі елементи з класом 'accardion'
const accordions = document.querySelectorAll('.accardion');
// Ініціалізація: закриваємо всі акордеони, крім першого
accordions.forEach((accordion, index) => {
    const target = accordion.querySelector('.accordion-target');
    const icon = accordion.querySelector('.icon-btn');
    target.style.transition = 'height 0.3s ease';
    accordion.style.transition = 'background-color 0.3s ease';
    if (icon) {
        icon.style.transition = 'transform 0.3s ease';
    }
    if (index === 0) {
        // Відкриваємо перший аккордеон
        accordion.classList.add('active');
        target.style.height = `${target.scrollHeight}px`;
        accordion.style.backgroundColor = '#fff';
        if (icon) {
            icon.style.transform = 'rotate(45deg)';
        }
    } else {
        // Закриваємо інші акордеони
        target.style.height = '0px';
        accordion.classList.remove('active');
        if (icon) {
            icon.style.transform = 'rotate(0deg)';
        }
    }
});
// Додаємо обробник подій на кожен аккордеон
accordions.forEach((accordion) => {
    const target = accordion.querySelector('.accordion-target');
    const icon = accordion.querySelector('.icon-btn');
    accordion.addEventListener('click', () => {
        // Закриваємо всі аккордеони, крім того, який відкривається
        accordions.forEach((item) => {
            const itemTarget = item.querySelector('.accordion-target');
            const itemIcon = item.querySelector('.icon-btn');
            if (item !== accordion) {
                item.classList.remove('active');
                itemTarget.style.height = '0px';
                item.style.backgroundColor = '';
                if (itemIcon) {
                    itemIcon.style.transform = 'rotate(0deg)';
                }
            }
        });
        // Перевірка, чи аккордеон вже відкритий
        if (accordion.classList.contains('active')) {
            accordion.classList.remove('active');
            target.style.height = '0px';
            accordion.style.backgroundColor = '';
            if (icon) {
                icon.style.transform = 'rotate(0deg)';
            }
        } else {
            accordion.classList.add('active');
            target.style.height = `${target.scrollHeight}px`;
            accordion.style.backgroundColor = '#fff';
            if (icon) {
                icon.style.transform = 'rotate(45deg)';
            }
        }
    });
});


let choices1 = '';
let choices2 = '';
let choices3 = '';

const element = document.querySelector('.js-choice-year');
if (element) {
    choices1 = new Choices(element, {
        placeholder: 'Type to search years...',
        position: 'bottom',
        sorter: function (a, b) {
            return b.label.length - a.label.length;
        },
    });

    element.addEventListener(
        'change',
        function (event) {
            checkInputfield(choices1);
        });
}


const element3 = document.querySelector('.js-choice-model');
if (element3) {
    choices3 = new Choices(element3, {
        position: 'bottom',
        placeholder: 'Type to search model...',
    }).disable();

    element3.addEventListener(
        'change',
        function (event) {
            checkInputfield(choices3);
        });
}

const element2 = document.querySelector('.js-choice-brand');
if (element2) {
    choices2 = new Choices(element2, {
        position: 'bottom',
        placeholder: 'Type to search marks...',
    });


    element2.addEventListener(
        'change',
        function (event) {


            checkInputfield(choices2);


            var data = {
                action: 'md_get_model',
                nonce: md_main_js.nonce,
                brand: event.detail.value
            };


            $.ajax({
                type: 'post',
                dataType: 'json',
                url: md_main_js.url,
                data: data,
                beforeSend: function () {

                },
                success: function (response) {

                    let options = '<option>Type to search models...</option>';
                    options += response.models.map(function (item) {
                        return '<option value="' + item.value + '">' + item.label + '</option>';
                    });

                    choices3.init();
                    choices3.clearChoices();
                    choices3.setChoices(
                        response.models,
                        'value',
                        'label',
                        false,
                    );
                    choices3.enable();

                }
            });


        },
        false,
    );


}


const from_city = document.querySelector('.js-choice-from-city2');
if (from_city) {
    from_city_choices = new Choices(from_city, {
        position: 'bottom',
        placeholder: 'Type to search city...',
    });

    from_city.addEventListener(
        'search',
        function (event) {

            //checkInputfield(from_city_choices);


            var data = {
                action: 'md_get_city',
                nonce: md_main_js.nonce,
                search: event.detail.value
            };


            $.ajax({
                type: 'post',
                dataType: 'json',
                url: md_main_js.url,
                data: data,
                beforeSend: function () {

                },
                success: function (response) {

                    let options = '<option>Type to search models...</option>';
                    options += response.models.map(function (item) {
                        return '<option value="' + item.value + '">' + item.label + '</option>';
                    });
                    /*
                                        from_city.init();
                                        from_city.clearChoices();
                                        from_city.setChoices(
                                            response.models,
                                            'value',
                                            'label',
                                            false,
                                        );*/

                }
            });
        });
}




const md_test_phone = document.getElementById('md_test_phone')

if (md_test_phone) {
    md_test_phone.addEventListener('input', function (e) {

        var x = e.target.value.replace(/\D/g, '').match(/(\d{0,3})(\d{0,3})(\d{0,4})/);
        e.target.value = '(' + x[1] + ') ' + x[2] + '-' + x[3];

        if (e.target.value.length < 14) {
            e.target.classList.add("md_input_error");
        } else {
            e.target.classList.remove("md_input_error");
        }
    });
}


const md_test_phone2 = document.getElementById('md_test_phone2')




if (md_test_phone2) {

    IMask(md_test_phone2, {
        mask: '+{1} (000) 000-0000',
        lazy: false,  // make placeholder always visible
        placeholderChar: '0'     // defaults to '_'
    })


}



function checkInputfield(my_choices) {
    let inner_element = my_choices.containerInner.element;
    if (my_choices.getValue(true)) {
        inner_element.classList.remove('is-invalid');
    } else {
        inner_element.classList.add('is-invalid');
    }
}

function delay(callback, ms) {
    var timer = 0;
    return function () {
        var context = this, args = arguments;
        clearTimeout(timer);
        timer = setTimeout(function () {
            callback.apply(context, args);
        }, ms || 0);
    };
}

function isFakePhoneNumber(phone) {
    // Удаляем все нецифры
    let digits = phone.replace(/\D/g, '');

    // 10 одинаковых цифр
    if (/^(\d)\1{9}$/.test(digits)) return true;

    // Известные фейки
    const knownFake = [
        '0000000000',
        '1111111111',
        '1234567890',
        '0123456789',
        '9876543210'
    ];
    if (knownFake.includes(digits)) return true;

    // Последовательности (короткие и длинные, минимум 3 подряд)
    // for (let len = 3; len <= digits.length; len++) {
    //     for (let i = 0; i <= digits.length - len; i++) {
    //         let asc = true, desc = true;
    //         for (let j = 1; j < len; j++) {
    //             let prev = parseInt(digits[i + j - 1]);
    //             let curr = parseInt(digits[i + j]);
    //             if (curr !== (prev + 1) % 10) asc = false;
    //             if (curr !== (prev + 9) % 10) desc = false;
    //         }
    //         if (asc || desc) return true;
    //     }
    // }

    // Повторяющийся паттерн (полный и частичный)
    // for (let chunk = 2; chunk <= Math.floor(digits.length / 2); chunk++) {
    //     let part = digits.slice(0, chunk);
    //     let repeats = Math.floor(digits.length / chunk);
    //     if (repeats < 2) continue;
    //     if (digits.indexOf(part.repeat(2)) === 0) return true;
    // }

    // Мало уникальных цифр
    if ([...new Set(digits)].length <= 2) return true;

    return false;
}


jQuery(document).ready(function ($) {
    
    $('#md_step3_form').on('submit', function (e) {
        $('.md_phone_error_msg').remove();
        
        let phone = $('#md_test_phone2').val();

        if (isFakePhoneNumber(phone.slice(2))) {
            console.log('false');
            $('#md_test_phone2').addClass('md_input_error');
            $('#md_test_phone2').closest('.input-group').append(
                '<div class="md_error_msg md_phone_error_msg">This phone number doesn’t look right. Please double-check it to get an accurate quote.</div>'
            );
            return false;
        } else {
            $('#md_test_phone2').removeClass('md_input_error');
        }


        if ($(this).find('.md_input_error').length === 0) {
            $('#md_step3_form_btn').addClass('loading').attr('disabled', 'disabled');
            $('#md_step3_form_btn').find('.txt-24').text('Calculating…');
            return true;
        } else {
            return false;
        }

    });





    $(document).mouseup(function (e) {
        var $dropDowns = $(".md_dropdown_result");

        $dropDowns.each(function (i, obj) {
            if (!$(obj).is(e.target)
                && $(obj).has(e.target).length === 0) {
                $(obj).hide();
            }
        });


    });


    $('.js-md-search-city input').on('keyup', delay(function (e) {

        let $this = $(this);
        if ($this.val().length >= 2) {

            let url = $this.parent().data('url')


            var data = {
                action: url,
                nonce: md_main_js.nonce,
                search: $this.val(),
            };


            $.ajax({
                type: 'post',
                dataType: 'json',
                url: md_main_js.url,
                data: data,
                beforeSend: function () {
                    $this.addClass('loading');
                },
                success: function (response) {

                    $this.removeClass('loading');

                    let $group = $this.closest('.js-md-group');

                    $group.find('[name*=city]').addClass('md_invalid');
                    $group.find('[name*=zip]').addClass('md_invalid');
                    $group.find('[name*=state]').addClass('md_invalid');


                    let list = '';
                    response.result.map(function (item) {
                        list += '<div data-city="' + item.city + '" data-state="' + item.code + '" data-state="' + item.code + '" data-zip="' + item.zip + '">' + item.text + '</div>';
                        return item;
                    });


                    if (list) {
                        $this.parent().find('.md_dropdown_result').html(list).slideDown();
                    } else {
                        $this.parent().find('.md_dropdown_result').slideUp();
                    }


                    /*
                                        from_city.init();
                                        from_city.clearChoices();
                                        from_city.setChoices(
                                            response.models,
                                            'value',
                                            'label',
                                            false,
                                        );*/

                }
            });


        } else {
            $this.parent().find('.md_dropdown_result').slideUp();
        }


    }, 100));


    $('.md_dropdown_result').on('click', 'div', function (e) {
        let $this = $(this);
        let $group = $this.closest('.js-md-group');

        let state = $this.data('state');
        let city = $this.data('city');
        let zip = $this.data('zip');


        $group.find('[name*=city]').val(city).removeClass('md_invalid');
        $group.find('[name*=zip]').val(zip).removeClass('md_invalid');
        $group.find('[name*=state]').val(state).removeClass('md_invalid').change();

        $('.md_dropdown_result').hide();

    });

    $('#md_form_step_one').on('submit', function (e) {


        let $this = $(this);
        $this.removeClass('md_form_invalid');

        let text_error = '';
        if ($this.find('.md_invalid').length === 0) {

            console.log('success');
            return true;

        } else {


            $this.addClass('md_form_invalid');
            $('.md_form_step1_error').remove();

            text_error = 'Start typing in the field and select an item from the list.';
            $this.append('<div class="md_form_step1_error md_error_msg">' + text_error + '</div>');

            setTimeout(function () {
                $('.md_form_step1_error').slideUp(300, function () {
                    $('.md_form_step1_error').remove();
                })
            }, 10000);

        }

        return false;

    });

    $(window).on('scroll', function () {


        if ($(window).width() < 768) {

            if ($(this).scrollTop() > 700) {
                $('.js-mob-btn').addClass('opened');
            } else {
                $('.js-mob-btn').removeClass('opened');
            }
        }
    });


    $('.menu-open-btn, .menu-btn-open').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $('.mob-menu').toggleClass('opened');
    });

    /*
        $(document).on('click', '.js-submit', function(e) {
            checkInputfield(choices1);
            checkInputfield(choices2);
            checkInputfield(choices3);


            if ($('.is-invalid').length) {
                return false;
            } else {
                return true
            }


        });*/

/*
    $('input[ms-code-phone-number]').each(function () {
        var input = this;
        var preferredCountries = $(input).attr('ms-code-phone-number').split(',');
        var iti = window.intlTelInput(input, {
            preferredCountries: preferredCountries,
            utilsScript: "https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/utils.js"
        });
        $.get("https://ipinfo.io", function (response) {
            var countryCode = response.country;
            iti.setCountry(countryCode);
        }, "jsonp");
        input.addEventListener('change', formatPhoneNumber);
        input.addEventListener('keyup', formatPhoneNumber);

        function formatPhoneNumber() {
            var formattedNumber = iti.getNumber(intlTelInputUtils.numberFormat.NATIONAL);
            input.value = formattedNumber;
        }

        var form = $(input).closest('form');
        form.submit(function () {
            var formattedNumber = iti.getNumber(intlTelInputUtils.numberFormat.INTERNATIONAL);
            input.value = formattedNumber;
        });
    });*/

    $('[data-toggle="datepicker"]').datepicker({
        format: 'mm-dd-yyyy',
        autoHide: true,
    }).on('pick.datepicker', function (e) {

        let text = ("0" + (e.date.getMonth() + 1)).slice(-2) + '/' + ("0" + e.date.getDate()).slice(-2) + '/' + e.date.getFullYear();

        $('#js-md-est-date').text(text);

    });


    // Available date placeholders:
    // Year: yyyy
    // Month: mm
    // Day: dd
    if (window.innerWidth < 768) {
        $('[data-toggle="datepicker"]').attr('readonly', 'readonly')
    }
});
