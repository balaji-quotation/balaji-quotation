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