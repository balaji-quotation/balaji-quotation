<?php

// =====================================================
// SIDEBAR PERMISSIONS
// =====================================================

require_once __DIR__ . "/permissions.php";

$current_role = erpCurrentRole();

?>

<!-- Left Sidebar Start -->
<div class="app-sidebar-menu">

    <div class="h-100" data-simplebar>

        <div id="sidebar-menu">

            <!-- LOGO -->
            <div class="logo-box">

                <a
                    href="/quotation/dashboard.php"
                    class="logo logo-light"
                >

                    <span class="logo-sm">
                        <img
                            src="/quotation/dist/assets/images/unicornlogo.png"
                            alt="Logo"
                            height="22"
                        >
                    </span>

                    <span class="logo-lg">
                        <img
                            src="/quotation/dist/assets/images/unicornlogodark.png"
                            alt="Logo"
                            height="40"
                        >
                    </span>

                </a>


                <a
                    href="/quotation/dashboard.php"
                    class="logo logo-dark"
                >

                    <span class="logo-sm">
                        <img
                            src="/quotation/dist/assets/images/logo-sm.png"
                            alt="Logo"
                            height="22"
                        >
                    </span>

                    <span class="logo-lg">
                        <img
                            src="/quotation/dist/assets/images/logo-dark.png"
                            alt="Logo"
                            height="24"
                        >
                    </span>

                </a>

            </div>


            <ul id="side-menu">


                <?php if (erpCurrentCompanyId() !== null) { ?>

                <li class="menu-title">WORKSPACE</li>
                <?php if (erpCan('dashboard.view')) { ?><li><a href="/quotation/client/dashboard.php" class="side-nav-link"><i class="ri-dashboard-2-line"></i><span>Dashboard</span></a></li><?php } ?>
                <?php if (erpCan('clients.view')) { ?><li><a href="/quotation/clients/index.php" class="side-nav-link"><i class="ri-contacts-line"></i><span>Customers</span></a></li><?php } ?>
                <?php if (erpCan('quotations.view')) { ?><li><a href="/quotation/quotations/index.php" class="side-nav-link"><i class="ri-file-text-line"></i><span>Quotations</span></a><?php if (erpCan('products.view')) { ?><div class="ps-4"><a href="/quotation/products/index.php" class="side-nav-link"><i class="ri-box-3-line"></i><span>Product Master</span></a></div><?php } ?></li><?php } ?>
                <li><a href="/quotation/client/template_request.php" class="side-nav-link"><i class="ri-message-2-line"></i><span>Template Change Request</span></a></li>
                <li><a href="/quotation/client/company_profile.php" class="side-nav-link"><i class="ri-building-line"></i><span>Company Profile</span></a></li>
                <li><a href="/quotation/users/profile.php" class="side-nav-link"><i class="ri-user-line"></i><span>My Profile</span></a></li>

                <?php } else { ?>

                <!-- =================================================
                     ERP
                ================================================== -->

                <li class="menu-title">
                    ERP
                </li>


                <?php if (erpCan('dashboard.view')) { ?>

                <li>
                    <a
                        href="/quotation/dashboard.php"
                        class="side-nav-link"
                    >
                        <i class="ri-dashboard-2-line"></i>
                        <span>Dashboard</span>
                    </a>
                </li>

                <?php } ?>


                <!-- =================================================
                     MASTER
                ================================================== -->

                <?php if (
                    erpCan('clients.view') ||
                    erpCan('vendors.view') ||
                    erpCan('products.view') ||
                    erpCan('operations.view')
                ) { ?>

                <li class="menu-title">
                    MASTER
                </li>

                <?php } ?>


                <!-- CLIENTS MASTER -->

                <?php if (erpCan('clients.view')) { ?>

                <li>
                    <a
                        href="/quotation/clients/index.php"
                        class="side-nav-link"
                    >
                        <i class="ri-user-3-line"></i>
                        <span>Clients Master</span>
                    </a>
                </li>

                <?php } ?>


                <!-- VENDORS MASTER -->

                <?php if (erpCan('vendors.view')) { ?>

                <li>
                    <a
                        href="/quotation/vendors/index.php"
                        class="side-nav-link"
                    >
                        <i class="ri-store-2-line"></i>
                        <span>Vendors Master</span>
                    </a>
                </li>

                <?php } ?>


                <!-- PRODUCTS -->

                <?php if (erpCan('products.view')) { ?>

                <li>
                    <a
                        href="/quotation/products/index.php"
                        class="side-nav-link"
                    >
                        <i class="ri-box-3-line"></i>
                        <span>Products</span>
                    </a>
                </li>

                <?php } ?>


                <!-- OPERATIONS -->

                <?php if (erpCan('operations.view')) { ?>

                <li>
                    <a
                        href="/quotation/operations/index.php"
                        class="side-nav-link"
                    >
                        <i class="ri-settings-3-line"></i>
                        <span>Operations</span>
                    </a>
                </li>

                <?php } ?>

<!-- =================================================
                     ORDER & PRODUCTION
                ================================================== -->

                <?php if (
                    erpCan('orders.view') ||
                    erpCan('quotations.view') ||
                     erpCan('orders.production') ||
                    erpCan('dispatch.view') ||
                    erpCan('carry_forward.view') ||
                    erpCan('rejections.view')
                ) { ?>

                <li class="menu-title">
                    ORDER & PRODUCTION
                </li>

                <?php } ?>


                <!-- ORDERS -->

                <?php if (erpCan('orders.view')) { ?>

                <li>
                    <a
                        href="/quotation/client_po/index.php"
                        class="side-nav-link"
                    >
                        <i class="ri-file-list-2-line"></i>
                        <span>Orders</span>
                    </a>
                </li>

                <?php } ?>


                


                                
                <!-- QUOTATIONS -->

                <?php if (erpCan('quotations.view')) { ?>

                <li>
                    <a
                        href="/quotation/quotations/index.php"
                        class="side-nav-link"
                    >
                        <i class="ri-file-list-3-line"></i>
                        <span>Quotations</span>
                    </a>
                </li>

                <?php } ?>

                <!-- CHALLANS -->

                <li>
                    <a
                        href="/quotation/challans/index.php"
                        class="side-nav-link"
                    >
                        <i class="ri-file-list-3-line"></i>
                        <span>Challans</span>
                    </a>
                </li>

                <!-- PACKING STOCK -->

                <?php if (erpCan('orders.production')) { ?>

                <li>
                    <a
                        href="/quotation/client_po/packing_stock.php"
                        class="side-nav-link"
                    >
                        <i class="ri-archive-drawer-line"></i>
                        <span>Packing Stock</span>
                    </a>
                </li>

                <?php } ?>


<!-- DISPATCH -->

                <?php if (erpCan('dispatch.view')) { ?>

                <li>
                    <a
                        href="/quotation/dispatch/index.php"
                        class="side-nav-link"
                    >
                        <i class="ri-truck-line"></i>
                        <span>Dispatch</span>
                    </a>
                </li>

                <?php } ?>


                <!-- CARRY FORWARD STOCK -->

                <?php if (erpCan('carry_forward.view')) { ?>

                <li>
                    <a
                        href="/quotation/client_po/carry_forward_stock.php"
                        class="side-nav-link"
                    >
                        <i class="ri-archive-line"></i>
                        <span>Carry Forward Stock</span>
                    </a>
                </li>

                <?php } ?>


                <!-- =================================================
                     REPORTS
                ================================================== -->

                <?php if (erpCan('reports.view')) { ?>

                <li class="menu-title">
                    REPORTS
                </li>


                <li>
                    <a
                        href="/quotation/reports/production.php"
                        class="side-nav-link"
                    >
                        <i class="ri-bar-chart-box-line"></i>
                        <span>Production Report</span>
                    </a>
                </li>

                <li>
                    <a
                        href="/quotation/reports/cost_profit.php"
                        class="side-nav-link"
                    >
                        <i class="ri-money-dollar-circle-line"></i>
                        <span>Cost &amp; Profit Report</span>
                    </a>
                </li>

                <?php } ?>


                <!-- =================================================
                     SYSTEM
                ================================================== -->

                <?php if (erpCan('users.view')) { ?>

                <li class="menu-title">
                    SYSTEM
                </li>


                <li>
                    <a
                        href="/quotation/users/index.php"
                        class="side-nav-link"
                    >
                        <i class="ri-user-settings-line"></i>
                        <span>Users</span>
                    </a>
                </li>

                <?php } ?>


                <?php } ?>

                <!-- LOGOUT -->

                <li>

                    <a
                        href="/quotation/logout.php"
                        class="side-nav-link"
                    >
                        <i class="ri-logout-box-line"></i>
                        <span>Logout</span>
                    </a>

                </li>


            </ul>

        </div>


        <div class="clearfix"></div>

    </div>

</div>

<div class="erp-mobile-sidebar-backdrop" aria-hidden="true"></div>
<!-- Left Sidebar End -->


<style>

    .app-sidebar-menu .h-100 {
        height: 100vh !important;
    }

    #sidebar-menu {
        min-height: 100%;
    }

    #side-menu {
        padding-bottom: 60px;
    }

    :root {
        --erp-sidebar-width: 210px;
    }

    .app-sidebar-menu {
        width: var(--erp-sidebar-width) !important;
    }

    .logo-box {
        width: var(--erp-sidebar-width) !important;
    }

    .content-page {
        margin-left: var(--erp-sidebar-width) !important;
    }

    .navbar-custom {
        left: var(--erp-sidebar-width) !important;
    }

    #side-menu > li > a {
        padding-left: 18px !important;
        padding-right: 12px !important;
    }

    #side-menu .menu-title {
        padding-left: 18px !important;
        padding-right: 12px !important;
        font-size: 10px;
        letter-spacing: .4px;
    }

    #side-menu .side-nav-link span {
        font-size: 13px;
    }

    #side-menu .side-nav-link i {
        font-size: 17px;
        margin-right: 8px;
    }


    /* CURRENT ROLE SMALL LABEL */

    .sidebar-role-label {
        font-size: 10px;
        color: rgba(255,255,255,.55);
        padding: 4px 18px 12px;
    }



    .erp-mobile-sidebar-backdrop {
        display: none;
    }
    @media (max-width: 991.98px) {

        .app-sidebar-menu {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            bottom: 0 !important;
            width: var(--erp-sidebar-width) !important;
            transform: translateX(-100%) !important;
            transition: transform .22s ease !important;
            z-index: 1055 !important;
        }

        body.erp-mobile-sidebar-open .app-sidebar-menu {
            transform: translateX(0) !important;
        }

        .content-page {
            margin-left: 0 !important;
            width: 100% !important;
            min-width: 0 !important;
        }

        .navbar-custom {
            left: 0 !important;
            width: 100% !important;
        }

        .erp-mobile-sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .35);
            z-index: 1050;
        }

        body.erp-mobile-sidebar-open .erp-mobile-sidebar-backdrop {
            display: block;
        }

        body.erp-mobile-sidebar-open {
            overflow: hidden;
        }

    }

</style>
