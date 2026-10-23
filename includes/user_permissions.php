<?php
declare(strict_types=1);

/**
 * Per-user module access. Quotation item fields are controlled by the user's
 * assigned, versioned quotation template.
 */
function erpUserPermissionOptions(): array
{
    return [
        'Customers' => [
            'clients.view' => 'View',
            'clients.add' => 'Add',
            'clients.edit' => 'Edit',
            'clients.delete' => 'Delete',
        ],
        'Product Master' => [
            'products.view' => 'View',
            'products.add' => 'Add',
            'products.edit' => 'Edit',
            'products.delete' => 'Delete',
        ],
        'Quotations' => [
            'quotations.view' => 'View',
            'quotations.add' => 'Create',
            'quotations.save_products_to_master' => 'Save quotation items to Product Master',
            'quotations.edit' => 'Edit / revise own quotations',
            'quotations.delete' => 'Delete',
        ],
    ];
}

function erpUserPermissionKeys(): array
{
    $keys = [];
    foreach (erpUserPermissionOptions() as $permissions) {
        foreach ($permissions as $key => $_label) {
            $keys[] = $key;
        }
    }
    return $keys;
}

/**
 * Return explicit permissions for one user, or null when the user still uses
 * the legacy role map or when the migration has not been applied.
 */
function erpLoadUserPermissionOverrides(mysqli $db, int $userId): ?array
{
    static $cache = [];
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    try {
        $stmt = $db->prepare(
            'SELECT permission_key, allowed FROM user_permission_overrides WHERE user_id = ?'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $overrides = [];
        while ($row = $result->fetch_assoc()) {
            $overrides[(string)$row['permission_key']] = (bool)$row['allowed'];
        }
        $stmt->close();
        $cache[$userId] = $overrides ?: null;
    } catch (Throwable $e) {
        $cache[$userId] = null;
    }

    return $cache[$userId];
}

/**
 * Pre-fill legacy users with their current effective role permissions.
 */
function erpUserPermissionSelection(mysqli $db, int $userId, string $role): array
{
    $role = function_exists('erpNormalizeRole') ? erpNormalizeRole($role) : $role;
    $map = function_exists('erpPermissionMap') ? erpPermissionMap() : [];
    $overrides = erpLoadUserPermissionOverrides($db, $userId) ?? [];
    $selected = [];
    foreach (erpUserPermissionKeys() as $key) {
        $selected[$key] = array_key_exists($key, $overrides)
            ? $overrides[$key]
            : in_array($role, $map[$key] ?? [], true);
    }
    return $selected;
}

/**
 * Create or update the private quotation template assigned to one user.
 * Selected fields are indexes from a trusted company template version; the
 * submitted config itself is never trusted.
 */
function erpCreateUserQuotationTemplate(
    mysqli $db,
    int $companyId,
    int $userId,
    string $userName,
    int $adminId,
    int $sourceVersionId,
    int $currentVersionId,
    string $submittedColumnsJson,
    string $layout
): int {
    require_once __DIR__ . '/quotation_template_runtime.php';

    $allowedLayouts = ['Classic', 'Compact', 'Minimal', 'Modern'];
    if (!in_array($layout, $allowedLayouts, true)) {
        throw new RuntimeException('Choose a valid quotation print design.');
    }
    $source = $db->prepare(
        'SELECT v.id, v.template_id, v.config_json, t.template_name, t.description
         FROM quotation_template_versions v
         JOIN quotation_templates t ON t.id = v.template_id
         WHERE v.id = ? AND t.company_id = ? AND t.is_active = 1
         LIMIT 1'
    );
    $source->bind_param('ii', $sourceVersionId, $companyId);
    $source->execute();
    $base = $source->get_result()->fetch_assoc();
    $source->close();
    if (!$base) {
        throw new RuntimeException('Choose an active quotation template for this company.');
    }

    $config = json_decode((string)$base['config_json'], true);
    if (!is_array($config) || !is_array($config['columns'] ?? null)) {
        throw new RuntimeException('The selected quotation template is invalid.');
    }
    $submittedColumns = json_decode($submittedColumnsJson, true);
    if (!is_array($submittedColumns) || !array_is_list($submittedColumns) || count($submittedColumns) < 1 || count($submittedColumns) > 20) {
        throw new RuntimeException('Add between 1 and 20 quotation table fields.');
    }

    $columns = [];
    $seenLabels = [];
    foreach ($submittedColumns as $column) {
        if (!is_array($column)) {
            throw new RuntimeException('Each quotation table field must be valid.');
        }
        $label = mb_substr(trim((string)($column['label'] ?? '')), 0, 100, 'UTF-8');
        $unit = mb_substr(trim((string)($column['unit'] ?? '')), 0, 30, 'UTF-8');
        $type = (string)($column['type'] ?? 'text');
        $fieldRole = (string)($column['field_role'] ?? 'none');
        $align = (string)($column['align'] ?? 'left');
        $normalizedLabel = mb_strtolower($label, 'UTF-8');
        if ($label === '' || isset($seenLabels[$normalizedLabel])
            || !in_array($type, ['text', 'number', 'currency', 'textarea', 'auto_amount'], true)
            || !in_array($fieldRole, ['none', 'qty', 'rate', 'amount', 'auto_amount'], true)
            || !in_array($align, ['left', 'center', 'right'], true)) {
            throw new RuntimeException('Give every quotation field a unique name and valid type, calculation role, and alignment.');
        }
        if (($type === 'auto_amount') !== ($fieldRole === 'auto_amount')) {
            throw new RuntimeException('Set both Input Type and Calculation Role to Auto Amount for a calculated line total.');
        }
        if (in_array($fieldRole, ['qty', 'rate', 'amount'], true) && !in_array($type, ['number', 'currency'], true)) {
            throw new RuntimeException('Quantity, Rate, and Manual Amount roles require Number or Price / Currency input type.');
        }
        $seenLabels[$normalizedLabel] = true;
        $columns[] = ['label' => $label, 'unit' => $unit, 'type' => $type, 'field_role' => $fieldRole, 'align' => $align];
    }
    $config['columns'] = $columns;
    $config['print_layout'] = $layout;

    $hasQty = false;
    $hasRate = false;
    $hasAmount = false;
    $autoAmountCount = 0;
    foreach ($columns as $column) {
        if (!is_array($column)) continue;
        $role = quotationTemplateColumnRole($column);
        if ($role === 'qty') $hasQty = true;
        if ($role === 'rate') $hasRate = true;
        if ($role === 'amount') $hasAmount = true;
        if (($column['field_role'] ?? '') === 'auto_amount') $autoAmountCount++;
    }
    if ($autoAmountCount > 1) {
        throw new RuntimeException('Use only one Auto Amount field in a quotation table.');
    }
    $totals = is_array($config['totals'] ?? null) ? $config['totals'] : [];
    $tax = is_array($config['tax'] ?? null) ? $config['tax'] : [];
    $amountNeeded = !empty($tax['gst_enabled'])
        || !empty($totals['show_line_amount'])
        || !empty($totals['show_subtotal'])
        || !empty($totals['show_tax'])
        || !empty($totals['show_grand_total']);
    $calculation = is_array($config['calculation'] ?? null) ? $config['calculation'] : [];
    $mode = (string)($calculation['mode'] ?? 'standard');
    $formula = (string)($calculation['formula'] ?? '');
    if ($mode === 'standard' && $autoAmountCount > 0 && (!$hasQty || !$hasRate)) {
        throw new RuntimeException('An Auto Amount field requires one Quantity field and one Rate field in Standard calculation mode.');
    }
    if ($mode === 'standard' && $amountNeeded && !$hasAmount && !($hasQty && $hasRate)) {
        throw new RuntimeException('Keep the Qty and Rate fields, or an Amount field, because this template calculates totals.');
    }
    if ($mode === 'custom') {
        if (preg_match('/\\b(qty|quantity)\\b/i', $formula) && !$hasQty) {
            throw new RuntimeException('Keep the Qty field because the template formula uses it.');
        }
        if (preg_match('/\\b(rate|price)\\b/i', $formula) && !$hasRate) {
            throw new RuntimeException('Keep the Rate field because the template formula uses it.');
        }
        if (preg_match('/\\bamount\\b/i', $formula) && !$hasAmount && !($hasQty && $hasRate)) {
            throw new RuntimeException('Keep the Amount field or both Qty and Rate fields because the template formula uses them.');
        }
    }

    $configJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($configJson === false) {
        throw new RuntimeException('Quotation settings could not be saved.');
    }

    $privateDescription = 'User-specific quotation template for user ID ' . $userId;
    $sameAssignedVersion = $sourceVersionId === $currentVersionId;
    $isPrivateTemplate = trim((string)($base['description'] ?? '')) === $privateDescription;
    $originalConfig = json_decode((string)$base['config_json'], true);
    if ($sameAssignedVersion && $isPrivateTemplate && $originalConfig === $config) {
        return $sourceVersionId;
    }

    if ($sameAssignedVersion && $isPrivateTemplate) {
        $templateId = (int)$base['template_id'];
        $versionQuery = $db->prepare(
            'SELECT COALESCE(MAX(version_number), 0) + 1 AS next_version
             FROM quotation_template_versions WHERE template_id = ?'
        );
        $versionQuery->bind_param('i', $templateId);
        $versionQuery->execute();
        $nextVersion = (int)($versionQuery->get_result()->fetch_assoc()['next_version'] ?? 1);
        $versionQuery->close();
    } else {
        $templateName = mb_substr(trim($userName) . ' - ' . trim((string)$base['template_name']), 0, 150, 'UTF-8');
        if ($templateName === '') $templateName = 'User quotation template';
        $templateKey = 'usr-' . bin2hex(random_bytes(12));
        $stmt = $db->prepare(
            'INSERT INTO quotation_templates (company_id, template_key, template_name, description, is_active, created_by)
             VALUES (?, ?, ?, ?, 1, ?)'
        );
        $stmt->bind_param('isssi', $companyId, $templateKey, $templateName, $privateDescription, $adminId);
        $stmt->execute();
        $templateId = (int)$db->insert_id;
        $stmt->close();
        $nextVersion = 1;
    }

    $insert = $db->prepare(
        'INSERT INTO quotation_template_versions (template_id, version_number, config_json, created_by)
         VALUES (?, ?, ?, ?)'
    );
    $insert->bind_param('iisi', $templateId, $nextVersion, $configJson, $adminId);
    $insert->execute();
    $versionId = (int)$db->insert_id;
    $insert->close();
    return $versionId;
}

/**
 * Persist both granted and denied choices so unchecked items do not inherit a
 * broad role permission.
 */
function erpSaveUserPermissionOverrides(mysqli $db, int $userId, $submitted): void
{
    if (!is_array($submitted)) {
        $submitted = [];
    }

    $delete = $db->prepare('DELETE FROM user_permission_overrides WHERE user_id = ?');
    $delete->bind_param('i', $userId);
    $delete->execute();
    $delete->close();

    $insert = $db->prepare(
        'INSERT INTO user_permission_overrides (user_id, permission_key, allowed) VALUES (?, ?, ?)'
    );
    foreach (erpUserPermissionKeys() as $key) {
        $allowed = isset($submitted[$key]) && (string)$submitted[$key] === '1' ? 1 : 0;
        $insert->bind_param('isi', $userId, $key, $allowed);
        $insert->execute();
    }
    $insert->close();
}
?>