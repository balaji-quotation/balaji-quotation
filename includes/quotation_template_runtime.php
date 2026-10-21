<?php
declare(strict_types=1);

/**
 * Safe, small arithmetic runtime for versioned quotation templates.
 * Supported formula variables: qty, quantity, rate, price, amount.
 * Supported operators: +, -, *, /, and parentheses. No PHP evaluation.
 */
function quotationTemplateNumericValue($raw): ?float
{
    $value = trim((string)$raw);
    if ($value === '') {
        return null;
    }
    $value = str_replace([',', '₹', '$', '€', '£', '%', ' '], '', $value);
    if (!is_numeric($value)) {
        return null;
    }
    $number = (float)$value;
    return is_finite($number) ? $number : null;
}

function quotationTemplateColumnRole(array $column): ?string
{
    $label = mb_strtolower(trim((string)($column['column_label'] ?? $column['label'] ?? '')), 'UTF-8');
    $label = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $label) ?? $label;
    $label = trim($label);
    if (preg_match('/^(qty|quantity|ea|eau|qty eau|quantity eau)$/u', $label)) {
        return 'qty';
    }
    if (preg_match('/^(rate|unit rate|price|unit price|unit cost)$/u', $label)) {
        return 'rate';
    }
    if (preg_match('/^(amount|line amount|total amount|line total)$/u', $label)) {
        return 'amount';
    }
    return null;
}

function quotationTemplateEvaluateFormula(string $formula, array $variables): ?float
{
    $formula = trim($formula);
    if ($formula === '' || strlen($formula) > 500) {
        return null;
    }
    $tokens = [];
    $offset = 0;
    $length = strlen($formula);
    while ($offset < $length) {
        if (preg_match('/\G\s*(qty|quantity|rate|price|amount|(?:\d+(?:\.\d*)?|\.\d+)|[()+\-*\/])\s*/i', $formula, $match, 0, $offset) !== 1) {
            return null;
        }
        $token = strtolower($match[1]);
        $offset += strlen($match[0]);
        if (is_numeric($token)) {
            $tokens[] = (float)$token;
        } elseif (in_array($token, ['qty', 'quantity', 'rate', 'price', 'amount'], true)) {
            $key = in_array($token, ['qty', 'quantity'], true) ? 'qty' : (in_array($token, ['rate', 'price'], true) ? 'rate' : 'amount');
            if (!array_key_exists($key, $variables) || !is_finite((float)$variables[$key])) {
                return null;
            }
            $tokens[] = (float)$variables[$key];
        } else {
            $tokens[] = $token;
        }
    }
    if (!$tokens) {
        return null;
    }

    $output = [];
    $operators = [];
    $precedence = ['+' => 1, '-' => 1, '*' => 2, '/' => 2, 'u-' => 3];
    $expectOperand = true;
    foreach ($tokens as $token) {
        if (is_float($token) || is_int($token)) {
            if (!$expectOperand) return null;
            $output[] = (float)$token;
            $expectOperand = false;
            continue;
        }
        if ($token === '(') {
            if (!$expectOperand) return null;
            $operators[] = $token;
            $expectOperand = true;
            continue;
        }
        if ($token === ')') {
            if ($expectOperand) return null;
            while ($operators && end($operators) !== '(') $output[] = array_pop($operators);
            if (!$operators) return null;
            array_pop($operators);
            $expectOperand = false;
            continue;
        }
        if (in_array($token, ['+', '-', '*', '/'], true)) {
            if ($expectOperand) {
                if ($token !== '-') return null;
                $token = 'u-';
            }
            while ($operators && end($operators) !== '(' && $precedence[end($operators)] >= $precedence[$token]) {
                $output[] = array_pop($operators);
            }
            $operators[] = $token;
            $expectOperand = true;
            continue;
        }
        return null;
    }
    if ($expectOperand) return null;
    while ($operators) {
        $operator = array_pop($operators);
        if ($operator === '(') return null;
        $output[] = $operator;
    }

    $stack = [];
    foreach ($output as $token) {
        if (is_float($token) || is_int($token)) {
            $stack[] = (float)$token;
            continue;
        }
        if ($token === 'u-') {
            if (count($stack) < 1) return null;
            $stack[] = -array_pop($stack);
            continue;
        }
        if (count($stack) < 2) return null;
        $right = array_pop($stack);
        $left = array_pop($stack);
        if ($token === '+') $value = $left + $right;
        elseif ($token === '-') $value = $left - $right;
        elseif ($token === '*') $value = $left * $right;
        elseif ($token === '/') {
            if ($right == 0.0) return null;
            $value = $left / $right;
        } else return null;
        if (!is_finite($value)) return null;
        $stack[] = $value;
    }
    return count($stack) === 1 && is_finite($stack[0]) ? (float)$stack[0] : null;
}

function quotationTemplateRowAmount(array $row, array $columns, array $calculation): ?float
{
    $values = ['qty' => null, 'rate' => null, 'amount' => null];
    foreach ($columns as $column) {
        if (!is_array($column)) continue;
        $role = quotationTemplateColumnRole($column);
        if ($role === null) continue;
        $key = (string)($column['column_key'] ?? '');
        if ($key === '') continue;
        $value = quotationTemplateNumericValue($row[$key] ?? '');
        if ($value !== null) $values[$role] = $value;
    }

    if (($calculation['mode'] ?? 'standard') === 'custom') {
        if ($values['amount'] === null && $values['qty'] !== null && $values['rate'] !== null) {
            $values['amount'] = $values['qty'] * $values['rate'];
        }
        return quotationTemplateEvaluateFormula((string)($calculation['formula'] ?? ''), $values);
    }
    if ($values['qty'] !== null && $values['rate'] !== null) {
        return $values['qty'] * $values['rate'];
    }
    return $values['amount'];
}

function quotationTemplateRound(float $value, int $decimalPlaces = 2, string $mode = 'half_up'): float
{
    $decimalPlaces = max(0, min(4, $decimalPlaces));
    if ($mode === 'ceil' || $mode === 'floor') {
        $factor = 10 ** $decimalPlaces;
        $scaled = $value * $factor;
        return ($mode === 'ceil' ? ceil($scaled) : floor($scaled)) / $factor;
    }
    $roundMode = $mode === 'half_even' ? PHP_ROUND_HALF_EVEN : PHP_ROUND_HALF_UP;
    return round($value, $decimalPlaces, $roundMode);
}
