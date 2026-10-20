<?php

// =====================================================
// ERP ROLE & PERMISSION SYSTEM
// File: includes/permissions.php
// =====================================================


// -----------------------------------------------------
// NORMALIZE ROLE
// -----------------------------------------------------

function erpNormalizeRole($role)
{
    $role = trim((string)$role);

    if (strcasecmp($role, 'Super Admin') === 0) {
        return 'Super Admin';
    }

    if (
        strcasecmp($role, 'Admin') === 0 ||
        strcasecmp($role, 'Administrator') === 0
    ) {
        return 'Administrator';
    }

    if (strcasecmp($role, 'Manager') === 0) {
        return 'Manager';
    }

    if (strcasecmp($role, 'Production') === 0) {
        return 'Production';
    }

    if (strcasecmp($role, 'Quality') === 0) {
        return 'Quality';
    }

    if (strcasecmp($role, 'Dispatch') === 0) {
        return 'Dispatch';
    }

    if (strcasecmp($role, 'Accounts') === 0) {
        return 'Accounts';
    }

    return 'User';
}


// -----------------------------------------------------
// CURRENT USER ROLE
// -----------------------------------------------------

function erpCurrentRole()
{
    return erpNormalizeRole(
        $_SESSION['user_role'] ?? 'User'
    );
}

function erpIsSuperAdmin()
{
    return erpCurrentRole() === 'Super Admin';
}

function erpCurrentCompanyId()
{
    $companyId = $_SESSION['company_id'] ?? null;
    return $companyId === null ? null : (int)$companyId;
}

function erpRequireCompanyUser($database = null)
{
    if (!isset($_SESSION['user_id']) || erpIsSuperAdmin() || erpCurrentCompanyId() === null) {
        http_response_code(403);
        exit('Company account required.');
    }

    if ($database === null) {
        $database = $GLOBALS['conn'] ?? null;
    }
    if (!$database instanceof mysqli) {
        http_response_code(503);
        exit('Company access could not be verified.');
    }

    $companyId = erpCurrentCompanyId();
    $stmt = $database->prepare('SELECT status FROM companies WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || $row['status'] !== 'Active') {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        header('Location: /quotation/login.php?error=company');
        exit;
    }
}


// -----------------------------------------------------
// ROLE CHECK
// -----------------------------------------------------

function erpHasRole($roles)
{
    if (!is_array($roles)) {
        $roles = [$roles];
    }

    $currentRole = erpCurrentRole();

    foreach ($roles as $role) {

        if (
            $currentRole ===
            erpNormalizeRole($role)
        ) {
            return true;
        }
    }

    return false;
}


// -----------------------------------------------------
// PERMISSION MAP
// -----------------------------------------------------

function erpPermissionMap()
{
    return [

        // =================================================
        // DASHBOARD
        // =================================================

        'dashboard.view' => [
            'Administrator',
            'Manager',
            'Production',
            'Quality',
            'Dispatch',
            'Accounts',
            'User'
        ],


        // =================================================
        // CLIENTS
        // =================================================

        'clients.view' => [
            'Administrator',
            'Manager',
            'Accounts'
        ],

        'clients.add' => [
            'Administrator',
            'Manager'
        ],

        'clients.edit' => [
            'Administrator',
            'Manager'
        ],

        'clients.delete' => [
            'Administrator'
        ],


        // =================================================
        // VENDORS
        // =================================================

        'vendors.view' => [
            'Administrator',
            'Manager',
            'Production',
            'Quality',
            'Accounts'
        ],

        'vendors.add' => [
            'Administrator',
            'Manager'
        ],

        'vendors.edit' => [
            'Administrator',
            'Manager'
        ],

        'vendors.delete' => [
            'Administrator'
        ],


        // =================================================
        // PRODUCTS
        // =================================================

        'products.view' => [
            'Administrator',
            'Manager',
            'Production',
            'Quality',
            'Dispatch',
            'Accounts'
        ],

        'products.add' => [
            'Administrator',
            'Manager'
        ],

        'products.edit' => [
            'Administrator',
            'Manager'
        ],

        'products.delete' => [
            'Administrator'
        ],


        // =================================================
        // OPERATIONS
        // =================================================

        'operations.view' => [
            'Administrator',
            'Manager',
            'Production',
            'Quality'
        ],

        'operations.add' => [
            'Administrator',
            'Manager'
        ],

        'operations.edit' => [
            'Administrator',
            'Manager'
        ],

        'operations.delete' => [
            'Administrator'
        ],


        // =================================================
        // QUOTATIONS
        // =================================================

        'quotations.view' => [
            'Administrator',
            'Manager',
            'Accounts'
        ],

        'quotations.add' => [
            'Administrator',
            'Manager',
            'Accounts'
        ],

        'quotations.edit' => [
            'Administrator',
            'Manager',
            'Accounts'
        ],

        'quotations.delete' => [
            'Administrator'
        ],


        // =================================================
        // ORDERS / PRODUCTION
        // =================================================

        'orders.view' => [
            'Administrator',
            'Manager',
            'Production',
            'Quality',
            'Dispatch',
            'Accounts'
        ],

        'orders.add' => [
            'Administrator',
            'Manager'
        ],

        'orders.edit' => [
            'Administrator',
            'Manager',
            'Production'
        ],

        'orders.production' => [
            'Administrator',
            'Manager',
            'Production'
        ],


        // =================================================
        // REJECTION / REPLACEMENT
        // =================================================

        'rejections.view' => [
            'Administrator',
            'Manager',
            'Production',
            'Quality'
        ],

        'rejections.manage' => [
            'Administrator',
            'Manager',
            'Production',
            'Quality'
        ],


        // =================================================
        // DISPATCH
        // =================================================

        'dispatch.view' => [
            'Administrator',
            'Manager',
            'Production',
            'Dispatch'
        ],

        'dispatch.manage' => [
            'Administrator',
            'Manager',
            'Dispatch'
        ],


        // =================================================
        // CARRY FORWARD STOCK
        // =================================================

        'carry_forward.view' => [
            'Administrator',
            'Manager',
            'Production',
            'Dispatch'
        ],

        'carry_forward.manage' => [
            'Administrator',
            'Manager'
        ],


        // =================================================
        // REPORTS
        // =================================================

        'reports.view' => [
            'Administrator',
            'Manager',
            'Production',
            'Quality',
            'Dispatch',
            'Accounts'
        ],


        // =================================================
        // USERS
        // =================================================

        'users.view' => [
            'Administrator'
        ],

        'users.add' => [
            'Administrator'
        ],

        'users.edit' => [
            'Administrator'
        ],

        'users.status' => [
            'Administrator'
        ],


        // =================================================
        // SYSTEM
        // =================================================

        'system.settings' => [
            'Administrator'
        ],

        // =================================================
        // SUPER ADMIN
        // =================================================

        'superadmin.dashboard' => [
            'Super Admin'
        ],

        'companies.view' => [
            'Super Admin'
        ],

        'companies.add' => [
            'Super Admin'
        ],

        'companies.edit' => [
            'Super Admin'
        ],

        'companies.status' => [
            'Super Admin'
        ],

        'superadmin.users' => [
            'Super Admin'
        ],

        'superadmin.templates' => [
            'Super Admin'
        ],

        'superadmin.change_requests' => [
            'Super Admin'
        ],

        'superadmin.subscriptions' => [
            'Super Admin'
        ]
    ];
}


// -----------------------------------------------------
// CHECK PERMISSION
// -----------------------------------------------------

function erpCan($permission)
{
    $permissions = erpPermissionMap();

    if (!isset($permissions[$permission])) {
        return false;
    }

    return erpHasRole(
        $permissions[$permission]
    );
}


// -----------------------------------------------------
// REQUIRE PERMISSION
// -----------------------------------------------------

function erpRequire($permission)
{
    if (erpCan($permission)) {
        return;
    }

    http_response_code(403);

    echo '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Access Denied</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body{
                margin:0;
                min-height:100vh;
                display:flex;
                align-items:center;
                justify-content:center;
                background:#f4f7fb;
                font-family:Arial,sans-serif;
                color:#26374a;
            }
            .access-box{
                width:90%;
                max-width:480px;
                background:#fff;
                border:1px solid #e4e9ef;
                border-radius:14px;
                box-shadow:0 8px 28px rgba(31,45,61,.08);
                padding:32px;
                text-align:center;
            }
            .access-code{
                font-size:56px;
                font-weight:700;
                color:#dc3545;
                line-height:1;
            }
            .access-title{
                margin-top:12px;
                font-size:22px;
                font-weight:700;
            }
            .access-text{
                margin-top:8px;
                font-size:13px;
                color:#7b8794;
            }
            .access-role{
                margin-top:10px;
                font-size:12px;
                font-weight:700;
            }
            .access-btn{
                display:inline-block;
                margin-top:20px;
                padding:9px 16px;
                border-radius:6px;
                background:#1f73f1;
                color:#fff;
                text-decoration:none;
            }
        </style>
    </head>
    <body>

        <div class="access-box">

            <div class="access-code">
                403
            </div>

            <div class="access-title">
                Access Denied
            </div>

            <div class="access-text">
                You do not have permission to access this ERP module.
            </div>

            <div class="access-role">
                Current Role:
                ' . htmlspecialchars(erpCurrentRole(), ENT_QUOTES, 'UTF-8') . '
            </div>

            <a
                href="/quotation/dashboard.php"
                class="access-btn"
            >
                Back to Dashboard
            </a>

        </div>

    </body>
    </html>
    ';

    exit;
}
?>
