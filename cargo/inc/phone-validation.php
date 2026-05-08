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

    if (cargo_phone_is_fake($nationalDigits)) {
        return [
            'valid' => false,
            'message' => cargo_phone_error_message(),
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

function cargo_phone_is_fake(string $digits): bool
{
    if (preg_match('/^(\d)\1{9}$/', $digits)) {
        return true;
    }

    $knownFake = [
        '0000000000',
        '1111111111',
        '1234567890',
        '0123456789',
        '9876543210',
    ];

    if (in_array($digits, $knownFake, true)) {
        return true;
    }

    if (cargo_phone_is_sequential($digits)) {
        return true;
    }

    if (cargo_phone_has_repeating_pattern($digits)) {
        return true;
    }

    if (cargo_phone_has_short_sequence($digits)) {
        return true;
    }
    
    if (cargo_phone_has_partial_repeating_pattern($digits)) {
        return true;
    }

    if (count(array_unique(str_split($digits))) <= 2) {
        return true;
    }

    return false;
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