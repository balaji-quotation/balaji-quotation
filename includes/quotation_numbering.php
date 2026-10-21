<?php
declare(strict_types=1);

/**
 * Per-company quotation numbering patterns.
 * Supported tokens: {FY}, {YYYY}, {YY}, {SEQ}, and {SEQ:n} (n = 1..12).
 * A plain legacy prefix remains supported and receives FY/0001 suffixes.
 */
function quotationNumberPatternIsValid(string $pattern): bool
{
    $pattern = trim($pattern);
    if ($pattern === '' || strlen($pattern) > 100) {
        return false;
    }

    if (!str_contains($pattern, '{') && !str_contains($pattern, '}')) {
        return (bool)preg_match('/^[A-Za-z0-9._\/-]+$/D', $pattern);
    }

    if (!preg_match('/^[A-Za-z0-9._\/:{}-]+$/D', $pattern)) {
        return false;
    }

    if (!preg_match('/\{SEQ(?::(?:[1-9]|1[0-2]))?\}/', $pattern)) {
        return false;
    }

    $withoutTokens = preg_replace('/\{(?:FY|YYYY|YY|SEQ(?::(?:[1-9]|1[0-2]))?)\}/', '', $pattern);
    if ($withoutTokens === null || str_contains($withoutTokens, '{') || str_contains($withoutTokens, '}')) {
        return false;
    }

    return true;
}

function quotationFormatNumber(string $pattern, string $financialYear, int $year, int $sequence): string
{
    $pattern = trim($pattern);
    $sequence = max(1, $sequence);
    if (!quotationNumberPatternIsValid($pattern)) {
        $pattern = 'UT/QTN/';
    }

    if (!str_contains($pattern, '{')) {
        return $pattern . $financialYear . '/' . str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
    }

    $twoDigitYear = substr((string)$year, -2);
    return (string)preg_replace_callback(
        '/\{(FY|YYYY|YY|SEQ(?::([1-9]|1[0-2]))?)\}/',
        static function (array $match) use ($financialYear, $year, $twoDigitYear, $sequence): string {
            if ($match[1] === 'FY') {
                return $financialYear;
            }
            if ($match[1] === 'YYYY') {
                return (string)$year;
            }
            if ($match[1] === 'YY') {
                return $twoDigitYear;
            }
            $width = isset($match[2]) && $match[2] !== '' ? (int)$match[2] : 4;
            return str_pad((string)$sequence, $width, '0', STR_PAD_LEFT);
        },
        $pattern
    );
}
