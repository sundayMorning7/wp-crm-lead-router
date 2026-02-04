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

function initMap() {
    const input_from = document.getElementById("place_from");
    const input_to = document.getElementById("place_to");

    const options = {
        componentRestrictions: {country: "us"},
        fields: ["address_components", "geometry", "icon", "name"],
        strictBounds: false,
    };

    const autocomplete_from = new google.maps.places.Autocomplete(input_from, options);
    const autocomplete_to = new google.maps.places.Autocomplete(input_to, options);
}


const element = document.querySelector('.js-choice-year');
if (element) {
    const choices = new Choices(element, {
        placeholder: 'Type to search years...',
        sorter: function(a, b) {
            return b.label.length - a.label.length;
        },
    });
}

const element3 = document.querySelector('.js-choice-model');
if (element3) {
    const choices3 = new Choices(element3, {
        placeholder: 'Type to search model...',
    }).disable();
}

const element2 = document.querySelector('.js-choice-brand');
if (element2) {
    const choices2 = new Choices(element2, {
        placeholder: 'Type to search marks...',
    });


    element2.addEventListener(
        'change',
        function(event) {

            var data = {
                action: 'md_get_model',
                nonce: md_main_js.nonce,
                search: $this.val()
            };


            $.ajax({
                type: 'post',
                dataType: 'json',
                url: md_main_js.url,
                data: data,
                beforeSend: function () {
                    $('.modal-search-result').slideUp();
                },
                success: function (response) {


                    setTimeout(function () {
                        $this.parent().removeClass('loading');
                        $('.modal-search-result').html(response.html).slideDown();
                    }, 700)

                }
            });



        },
        false,
    );


}


jQuery(document).ready(function($) {

    $('input[ms-code-phone-number]').each(function() {
        var input = this;
        var preferredCountries = $(input).attr('ms-code-phone-number').split(',');
        var iti = window.intlTelInput(input, {
            //preferredCountries: preferredCountries,
            onlyCountries: ["us"],
            allowDropdown: false,
            showFlags: false,
            utilsScript: "https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/utils.js"
        });
        /*$.get("https://ipinfo.io", function(response) {
            var countryCode = response.country;
            iti.setCountry(countryCode);
        }, "jsonp");*/
        input.addEventListener('change', formatPhoneNumber);
        input.addEventListener('keyup', formatPhoneNumber);
        function formatPhoneNumber() {
            var formattedNumber = iti.getNumber(intlTelInputUtils.numberFormat.NATIONAL);
            input.value = formattedNumber;
        }
        var form = $(input).closest('form');
        form.submit(function() {
            var formattedNumber = iti.getNumber(intlTelInputUtils.numberFormat.INTERNATIONAL);
            input.value = formattedNumber;
        });
    });

    $('[data-toggle="datepicker"]').datepicker({
        format: 'mm-dd-yyyy'
    });
    // Available date placeholders:
    // Year: yyyy
    // Month: mm
    // Day: dd
    if (window.innerWidth < 768) {
        $('[data-toggle="datepicker"]').attr('readonly', 'readonly')
    }
});
