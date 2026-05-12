<?php

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

function cargo_phone_error_message(): string
{
    return 'This phone number doesn’t look right. Please double-check it to get an accurate quote.';
}

function cargo_validate_phone(string $rawPhone, array $allowedRegions = ['US']): array
{
    $util = PhoneNumberUtil::getInstance();

    try {
        $phone = $util->parse($rawPhone, 'US');
    } catch (NumberParseException $e) {
        return [
            'valid' => false,
            'message' => cargo_phone_error_message(),
        ];
    }

    if (!$util->isPossibleNumber($phone) || !$util->isValidNumber($phone)) {
        return [
            'valid' => false,
            'message' => cargo_phone_error_message(),
        ];
    }

    $region = $util->getRegionCodeForNumber($phone);
    if (!$region || !in_array($region, $allowedRegions, true)) {
        return [
            'valid' => false,
            'message' => cargo_phone_error_message(),
        ];
    }

    $nationalDigits = (string) $phone->getNationalNumber();

    $fake_reason = cargo_phone_is_fake($nationalDigits);
    if ($fake_reason !== null) {
        return [
            'valid' => false,
            'message' => cargo_phone_error_message(),
            'reason' => $fake_reason
        ];
    }

    return [
        'valid' => true,
        'region' => $region,
        'national_digits' => $nationalDigits,
        'e164' => $util->format($phone, PhoneNumberFormat::E164),
        'national' => $util->format($phone, PhoneNumberFormat::NATIONAL),
    ];
}

function cargo_phone_is_fake(string $digits): ?string
{
    if (preg_match('/^(\d)\1{9}$/', $digits)) {
        return 'all_same';
    }

    $knownFake = [
        '0000000000',
        '1111111111',
        '1234567890',
        '0123456789',
        '9876543210',
    ];

    if (in_array($digits, $knownFake, true)) {
        return 'known_fake';
    }

    // Проверки после кода (первые 3 цифры)
    if (strlen($digits) > 3) {
        $post_code = substr($digits, 3);

        // 5+ одинаковых подряд
        if (preg_match('/(\d)\1{4,}/', $post_code)) {
            return 'post_code_repeats';
        }

        // Последовательности возрастающие
        if (preg_match('/012345/', $post_code) || preg_match('/123456/', $post_code) || preg_match('/234567/', $post_code)) {
            return 'post_code_ascending_seq';
        }

        // Последовательности убывающие
        if (preg_match('/987654/', $post_code) || preg_match('/876543/', $post_code) || preg_match('/765432/', $post_code)) {
            return 'post_code_descending_seq';
        }

        // Только 2 уникальные цифры после кода
        if (count(array_unique(str_split($post_code))) <= 2) {
            return 'post_code_low_unique';
        }
    }

    return null;
}
