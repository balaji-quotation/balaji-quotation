<?php
declare(strict_types=1);

/** Helpers for the configurable, company-scoped Product Master. */
function productMasterTableExists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function productMasterColumnExists(mysqli $db, string $column): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $table = 'products';
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function productMasterSchemaReady(mysqli $db): bool
{
    return productMasterTableExists($db, 'product_master_fields')
        && productMasterColumnExists($db, 'description')
        && productMasterColumnExists($db, 'custom_fields_json');
}

function productMasterFieldDefinitions(mysqli $db, bool $includeInactive = false): array
{
    if (!productMasterTableExists($db, 'product_master_fields')) {
        return [];
    }

    $sql = 'SELECT id, field_key, label, field_type, options_json, is_required, is_active, sort_order FROM product_master_fields';
    if (!$includeInactive) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';
    $result = $db->query($sql);
    $fields = [];
    while ($row = $result->fetch_assoc()) {
        $decoded = json_decode((string)($row['options_json'] ?? ''), true);
        $row['options'] = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        $row['is_required'] = (int)$row['is_required'] === 1;
        $row['is_active'] = (int)$row['is_active'] === 1;
        $fields[] = $row;
    }
    return $fields;
}

/**
 * Validate active custom fields and retain values for fields that Super Admin
 * has deactivated, so turning a field off never destroys old product data.
 */
function productMasterNormalizeCustomFields(mysqli $db, array $submitted, array $existing = []): array
{
    $values = $existing;
    foreach (productMasterFieldDefinitions($db) as $field) {
        $key = (string)$field['field_key'];
        $raw = $submitted[$key] ?? '';
        if ($field['field_type'] === 'checkbox') {
            $value = !empty($raw) ? '1' : '0';
        } else {
            if (is_array($raw) || is_object($raw)) {
                throw new InvalidArgumentException('Invalid value for ' . $field['label'] . '.');
            }
            $value = trim((string)$raw);
        }

        if ($field['is_required'] && ($field['field_type'] === 'checkbox' ? $value !== '1' : $value === '')) {
            throw new InvalidArgumentException($field['label'] . ' is required.');
        }
        if (strlen($value) > 10000) {
            throw new InvalidArgumentException($field['label'] . ' is too long.');
        }
        if ($value !== '') {
            if ($field['field_type'] === 'number' && (!is_numeric($value) || !is_finite((float)$value))) {
                throw new InvalidArgumentException($field['label'] . ' must be a valid number.');
            }
            if ($field['field_type'] === 'date') {
                $date = DateTime::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) {
                    throw new InvalidArgumentException($field['label'] . ' must be a valid date.');
                }
            }
            if ($field['field_type'] === 'dropdown' && !in_array($value, $field['options'], true)) {
                throw new InvalidArgumentException('Choose a valid value for ' . $field['label'] . '.');
            }
        }
        $values[$key] = $value;
    }
    return $values;
}

function productMasterDecodeValues(?string $json): array
{
    $values = json_decode((string)$json, true);
    return is_array($values) ? $values : [];
}
