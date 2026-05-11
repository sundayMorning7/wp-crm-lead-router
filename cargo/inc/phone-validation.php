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
            'reason' => 'parse_error',
        ];
    }


    if (!$util->isPossibleNumber($phone)) {
        // Проверяем кастомные fake-pattern
        $nationalDigits = (string) $phone->getNationalNumber();
        $fake_reason = cargo_phone_is_fake($nationalDigits);
        $reason = 'not_possible';
        if ($fake_reason !== null) {
            $reason .= '+fake_pattern:' . $fake_reason;
        }
        return [
            'valid' => false,
            'message' => cargo_phone_error_message(),
            'reason' => $reason,
        ];
    }
    if (!$util->isValidNumber($phone)) {
        // Проверяем кастомные fake-pattern
        $nationalDigits = (string) $phone->getNationalNumber();
        $fake_reason = cargo_phone_is_fake($nationalDigits);
        $reason = 'not_valid';
        if ($fake_reason !== null) {
            $reason .= '+fake_pattern:' . $fake_reason;
        }
        return [
            'valid' => false,
            'message' => cargo_phone_error_message(),
            'reason' => $reason,
        ];
    }

    $region = $util->getRegionCodeForNumber($phone);
    if (!$region || !in_array($region, $allowedRegions, true)) {
        return [
            'valid' => false,
            'message' => cargo_phone_error_message(),
            'reason' => 'not_allowed_region',
        ];
    }

    $nationalDigits = (string) $phone->getNationalNumber();

    $fake_reason = cargo_phone_is_fake($nationalDigits);
    if ($fake_reason !== null) {
        return [
            'valid' => false,
            'message' => cargo_phone_error_message(),
            'reason' => 'fake_pattern:' . $fake_reason,
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

    // Проверка: после кода (первые 3 цифры) 5+ одинаковых подряд
    if (strlen($digits) >= 8) {
        $post_code = substr($digits, 3);
        if (preg_match('/(\d)\1{4,}/', $post_code)) {
            return 'post_code_repeats';
        }
    }

    if (cargo_phone_is_sequential($digits)) {
        return 'sequential';
    }

    if (cargo_phone_has_repeating_pattern($digits)) {
        return 'repeating_pattern';
    }

    if (cargo_phone_has_short_sequence($digits)) {
        return 'short_sequence';
    }
    
    if (cargo_phone_has_partial_repeating_pattern($digits)) {
        return 'partial_repeating';
    }

    if (count(array_unique(str_split($digits))) <= 2) {
        return 'low_unique';
    }

    return null;
}

function cargo_phone_is_sequential(string $digits): bool
{
    $asc = true;
    $desc = true;

    for ($i = 1; $i < strlen($digits); $i++) {
        $prev = (int) $digits[$i - 1];
        $curr = (int) $digits[$i];

        if ($curr !== (($prev + 1) % 10)) {
            $asc = false;
        }

        if ($curr !== (($prev + 9) % 10)) {
            $desc = false;
        }
    }

    return $asc || $desc;
}

function cargo_phone_has_repeating_pattern(string $digits): bool
{
    $length = strlen($digits);

    for ($chunk = 1; $chunk <= intdiv($length, 2); $chunk++) {
        if ($length % $chunk !== 0) {
            continue;
        }

        $part = substr($digits, 0, $chunk);
        if (str_repeat($part, intdiv($length, $chunk)) === $digits) {
            return true;
        }
    }

    return false;
}

function cargo_phone_has_short_sequence(string $digits, int $minLen = 3): bool
{
    $len = strlen($digits);
    for ($i = 0; $i <= $len - $minLen; $i++) {
        // Проверка на возрастающую
        $asc = true;
        for ($j = 1; $j < $minLen; $j++) {
            if ((int)$digits[$i + $j] !== ((int)$digits[$i + $j - 1] + 1) % 10) {
                $asc = false;
                break;
            }
        }
        if ($asc) return true;

        // Проверка на убывающую
        $desc = true;
        for ($j = 1; $j < $minLen; $j++) {
            if ((int)$digits[$i + $j] !== ((int)$digits[$i + $j - 1] + 9) % 10) {
                $desc = false;
                break;
            }
        }
        if ($desc) return true;
    }
    return false;
}

function cargo_phone_has_partial_repeating_pattern(string $digits, int $minChunk = 2): bool
{
    $length = strlen($digits);
    for ($chunk = $minChunk; $chunk <= intdiv($length, 2); $chunk++) {
        $part = substr($digits, 0, $chunk);
        $repeats = intdiv($length, $chunk);
        if ($repeats < 2) continue;
        if (strpos($digits, str_repeat($part, 2)) === 0) {
            return true;
        }
    }
    return false;
}