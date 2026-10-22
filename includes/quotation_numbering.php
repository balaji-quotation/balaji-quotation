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

/**
 * Return the next sequence for this company's current number format.
 * Sequence is derived from existing quotation numbers, never from global row IDs.
 */
function quotationNextSequence(mysqli $db, int $companyId, string $pattern, string $financialYear, int $year): int
{
    if ($companyId < 1 || !quotationNumberPatternIsValid($pattern)) {
        return 1;
    }

    $pattern = trim($pattern);
    if (!str_contains($pattern, '{')) {
        $numberRegex = '/^' . preg_quote($pattern, '/') . preg_quote($financialYear, '/') . '(\\d{4,})$/D';
    } else {
        $tokenPattern = '/\\{(FY|YYYY|YY|SEQ(?::([1-9]|1[0-2]))?)\\}/';
        if (!preg_match_all($tokenPattern, $pattern, $tokens, PREG_OFFSET_CAPTURE)) {
            return 1;
        }

        $regexBody = '';
        $offset = 0;
        foreach ($tokens[0] as $index => $tokenMatch) {
            $tokenText = $tokenMatch[0];
            $tokenOffset = $tokenMatch[1];
            $regexBody .= preg_quote(substr($pattern, $offset, $tokenOffset - $offset), '/');
            $tokenName = $tokens[1][$index][0];

            if ($tokenName === 'FY') {
                $regexBody .= preg_quote($financialYear, '/');
            } elseif ($tokenName === 'YYYY') {
                $regexBody .= preg_quote((string)$year, '/');
            } elseif ($tokenName === 'YY') {
                $regexBody .= preg_quote(substr((string)$year, -2), '/');
            } else {
                $width = isset($tokens[2][$index][0]) && $tokens[2][$index][0] !== ''
                    ? (int)$tokens[2][$index][0]
                    : 4;
                $regexBody .= '([0-9]{' . $width . ',})';
            }

            $offset = $tokenOffset + strlen($tokenText);
        }
        $regexBody .= preg_quote(substr($pattern, $offset), '/');
        $numberRegex = '/^' . $regexBody . '$/D';
    }

    $stmt = $db->prepare('SELECT quotation_no FROM quotations WHERE company_id = ?');
    if (!$stmt) {
        return 1;
    }
    $stmt->bind_param('i', $companyId);
    if (!$stmt->execute()) {
        $stmt->close();
        return 1;
    }

    $result = $stmt->get_result();
    $maximum = 0;
    while ($row = $result->fetch_assoc()) {
        $number = (string)($row['quotation_no'] ?? '');
        if (!preg_match($numberRegex, $number, $matches) || count($matches) < 2) {
            continue;
        }

        $sequences = array_slice($matches, 1);
        $sequence = (int)($sequences[0] ?? 0);
        if ($sequence < 1) {
            continue;
        }

        $allMatch = true;
        foreach ($sequences as $matchedSequence) {
            if ((int)$matchedSequence !== $sequence) {
                $allMatch = false;
                break;
            }
        }
        if ($allMatch) {
            $maximum = max($maximum, $sequence);
        }
    }
    $stmt->close();

    return $maximum + 1;
}
