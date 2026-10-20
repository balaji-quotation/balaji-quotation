<?php
require_once __DIR__ . '/permissions.php';
erpRequire('superadmin.dashboard');
?>
<div class="app-sidebar-menu">
    <div class="h-100" data-simplebar>
        <div id="sidebar-menu">
            <div class="logo-box">
                <a href="/quotation/superadmin/dashboard.php" class="logo logo-light">
                    <span class="logo-lg"><img src="/quotation/dist/assets/images/unicornlogodark.png" alt="Balaji Webtech" height="36"></span>
                </a>
            </div>
            <ul id="side-menu">
                <li class="menu-title">SUPER ADMIN</li>
                <li><a href="/quotation/superadmin/dashboard.php" class="side-nav-link"><i class="ri-dashboard-2-line"></i><span>Dashboard</span></a></li>
                <li><a href="/quotation/superadmin/companies/index.php" class="side-nav-link"><i class="ri-building-line"></i><span>Manage Companies</span></a></li>
                <li><a href="/quotation/superadmin/users/index.php" class="side-nav-link"><i class="ri-user-settings-line"></i><span>Manage Users</span></a></li>
                <li><a href="/quotation/superadmin/templates/index.php" class="side-nav-link"><i class="ri-file-list-3-line"></i><span>Quotation Templates</span></a></li>
                <li><a href="/quotation/superadmin/change_requests/index.php" class="side-nav-link"><i class="ri-message-2-line"></i><span>Change Requests</span></a></li>
                <li><a href="/quotation/superadmin/subscriptions/index.php" class="side-nav-link"><i class="ri-bank-card-line"></i><span>Subscriptions</span></a></li>
                <li><a href="/quotation/logout.php" class="side-nav-link"><i class="ri-logout-box-line"></i><span>Logout</span></a></li>
            </ul>
        </div>
    </div>
</div>
<div class="erp-mobile-sidebar-backdrop" aria-hidden="true"></div>
