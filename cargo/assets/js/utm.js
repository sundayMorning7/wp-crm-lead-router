(function () {
    'use strict';

    var UTM_KEYS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content'
    ];

    var EXTRA_KEYS = [
        'utm_id',
        'utm_source_platform',
        'utm_creative_format',
        'utm_marketing_tactic',
        'gclid',
        'fbclid',
        'msclkid'
    ];

    var STORAGE_KEY = 'md_utm';
    var MAX_AGE_DAYS = 30;

    function readQueryParams() {
        var params = new URLSearchParams(window.location.search);
        var data = {};
        UTM_KEYS.concat(EXTRA_KEYS).forEach(function (key) {
            var value = params.get(key);
            if (value) {
                data[key] = value;
            }
        });
        return data;
    }

    function readStorage() {
        try {
            var raw = window.localStorage.getItem(STORAGE_KEY);
            if (!raw) {
                return {};
            }
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    function writeStorage(data) {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
        } catch (e) {
            return;
        }
    }

    function setCookie(name, value, days) {
        var expires = '';
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
            expires = '; expires=' + date.toUTCString();
        }
        document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/';
    }

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([\.$?*|{}\(\)\[\]\\\/\+^])/g, '\\$1') + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }

    function readCookies() {
        var data = {};
        UTM_KEYS.concat(EXTRA_KEYS).forEach(function (key) {
            var value = getCookie(key);
            if (value) {
                data[key] = value;
            }
        });
        return data;
    }

    function mergeData() {
        var base = readStorage();
        var cookies = readCookies();
        var query = readQueryParams();

        var merged = {};
        [base, cookies, query].forEach(function (source) {
            Object.keys(source).forEach(function (key) {
                merged[key] = source[key];
            });
        });

        return merged;
    }

    function persist(data) {
        if (!data || Object.keys(data).length === 0) {
            return;
        }
        writeStorage(data);
        Object.keys(data).forEach(function (key) {
            if (data[key]) {
                setCookie(key, data[key], MAX_AGE_DAYS);
            }
        });
    }

    function fillInputs(data) {
        if (!data || Object.keys(data).length === 0) {
            return;
        }
        Object.keys(data).forEach(function (key) {
            var value = data[key];
            if (!value) {
                return;
            }
            var selector = 'input[name="' + key + '"], input[data-utm="' + key + '"]';
            var inputs = document.querySelectorAll(selector);
            inputs.forEach(function (input) {
                if (!input.value) {
                    input.value = value;
                }
            });
        });
    }

    var merged = mergeData();
    persist(merged);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            fillInputs(merged);
        });
    } else {
        fillInputs(merged);
    }
})();
