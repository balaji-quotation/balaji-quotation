<?php

session_start();


// =====================================================
// LOGIN CHECK
// =====================================================

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}


// =====================================================
// DATABASE
// =====================================================

include("includes/db.php");


// =====================================================
// SEARCH VALUE
// =====================================================

$q = trim($_GET['q'] ?? '');

$results = [];


// =====================================================
// SEARCH DATABASE
// =====================================================

if ($q !== '') {

    $search = "%" . $q . "%";


    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT DISTINCT

            cp.id AS client_po_id,
            cp.po_number,
            cp.po_date,
            cp.delivery_date,
            cp.status AS po_status,

            c.company_name AS client_name,

            cpi.id AS client_po_item_id,
            cpi.order_qty,
            cpi.rate,
            cpi.rate_unit,

            p.id AS product_id,
            p.product_code,
            p.product_name,
            p.part_number,


            /* ==========================================
               TOTAL ROUTE COUNT
            ========================================== */

            (
                SELECT COUNT(*)

                FROM client_po_routes rc

                WHERE rc.client_po_item_id = cpi.id

            ) AS route_count,


            /* ==========================================
               CURRENT ROUTE
            ========================================== */

            cur.route_id,

            cur.sequence_no AS current_step,

            cur.route_status AS current_status,

            cur.operation_name AS current_operation,

            cur.operation_code AS current_operation_code,

            cur.vendor_name AS current_vendor,


            /* ==========================================
               CURRENT ROUTE PROGRESS
            ========================================== */

            COALESCE(
                pr.send_qty,
                0
            ) AS send_qty,

            COALESCE(
                pr.receive_qty,
                0
            ) AS good_qty,

            COALESCE(
                pr.reject_qty,
                0
            ) AS reject_qty,

            pr.send_date,

            pr.receive_date,


            /* ==========================================
               NEXT ROUTE
            ========================================== */

            nxt.sequence_no AS next_step,

            nxt.operation_name AS next_operation,

            nxt.operation_code AS next_operation_code,

            nxt.vendor_name AS next_vendor,


            /* ==========================================
               FINAL ROUTE
            ========================================== */

            final_route.route_id AS final_route_id,

            final_route.sequence_no AS final_step,

            final_route.operation_name AS final_operation,

            final_route.operation_code AS final_operation_code,

            final_route.vendor_name AS final_vendor


        FROM client_po cp


        INNER JOIN clients c
            ON c.id = cp.client_id


        LEFT JOIN client_po_items cpi
            ON cpi.client_po_id = cp.id


        LEFT JOIN products p
            ON p.id = cpi.product_id



        /* =================================================
           CURRENT ACTIVE ROUTE

           First route which is NOT Completed
        ================================================= */

        LEFT JOIN (

            SELECT

                r1.client_po_item_id,

                r1.id AS route_id,

                r1.sequence_no,

                r1.status AS route_status,

                o.operation_name,

                o.operation_code,

                v.company_name AS vendor_name


            FROM client_po_routes r1


            LEFT JOIN operations o
                ON o.id = r1.operation_id


            LEFT JOIN vendors v
                ON v.id = r1.vendor_id


            WHERE r1.sequence_no = (

                SELECT MIN(r2.sequence_no)

                FROM client_po_routes r2

                WHERE
                    r2.client_po_item_id =
                    r1.client_po_item_id

                AND
                    r2.status <> 'Completed'

            )

        ) cur

            ON cur.client_po_item_id =
               cpi.id



        /* =================================================
           CURRENT ROUTE PROGRESS
        ================================================= */

        LEFT JOIN client_po_route_progress pr

            ON pr.route_id =
               cur.route_id



        /* =================================================
           NEXT ROUTE
        ================================================= */

        LEFT JOIN (

            SELECT

                nr.client_po_item_id,

                nr.sequence_no,

                no.operation_name,

                no.operation_code,

                nv.company_name AS vendor_name


            FROM client_po_routes nr


            LEFT JOIN operations no
                ON no.id = nr.operation_id


            LEFT JOIN vendors nv
                ON nv.id = nr.vendor_id

        ) nxt

            ON nxt.client_po_item_id =
               cpi.id

            AND nxt.sequence_no = (

                SELECT MIN(r3.sequence_no)

                FROM client_po_routes r3

                WHERE
                    r3.client_po_item_id =
                    cpi.id

                AND
                    r3.sequence_no >
                    cur.sequence_no

            )



        /* =================================================
           FINAL ROUTE
        ================================================= */

        LEFT JOIN (

            SELECT

                fr.id AS route_id,

                fr.client_po_item_id,

                fr.sequence_no,

                fo.operation_name,

                fo.operation_code,

                fv.company_name AS vendor_name


            FROM client_po_routes fr


            LEFT JOIN operations fo
                ON fo.id = fr.operation_id


            LEFT JOIN vendors fv
                ON fv.id = fr.vendor_id


            WHERE fr.sequence_no = (

                SELECT MAX(fr2.sequence_no)

                FROM client_po_routes fr2

                WHERE
                    fr2.client_po_item_id =
                    fr.client_po_item_id

            )

        ) final_route

            ON final_route.client_po_item_id =
               cpi.id



        /* =================================================
           SEARCH
        ================================================= */

        WHERE

            cp.po_number LIKE ?

            OR c.company_name LIKE ?

            OR p.product_code LIKE ?

            OR p.product_name LIKE ?

            OR p.part_number LIKE ?


        ORDER BY cp.id DESC


        LIMIT 100
        "
    );


    if (!$stmt) {

        die(
            "Database Error: " .
            mysqli_error($conn)
        );
    }


    mysqli_stmt_bind_param(
        $stmt,
        "sssss",
        $search,
        $search,
        $search,
        $search,
        $search
    );


    mysqli_stmt_execute($stmt);


    $result =
        mysqli_stmt_get_result($stmt);


    while (
        $row =
        mysqli_fetch_assoc($result)
    ) {


        // =================================================
        // ROUTE COUNT
        // =================================================

        $routeCount =
            intval(
                $row['route_count'] ?? 0
            );


        // =================================================
        // CURRENT ROUTE AVAILABLE?
        // =================================================

        $hasCurrentRoute =
            !empty(
                $row['current_operation']
            );


        // =================================================
        // ALL ROUTES COMPLETED
        // =================================================

        $allRoutesCompleted =
            (
                $routeCount > 0 &&
                !$hasCurrentRoute
            );


        $row['all_routes_completed'] =
            $allRoutesCompleted;


        // =================================================
        // IF ALL ROUTES COMPLETED
        // GET FINAL ROUTE PROGRESS
        // =================================================

        if ($allRoutesCompleted) {

            $row['current_operation'] =
                $row['final_operation'] ?? '';

            $row['current_operation_code'] =
                $row['final_operation_code'] ?? '';

            $row['current_vendor'] =
                $row['final_vendor'] ?? '';

            $row['current_step'] =
                $row['final_step'] ?? '';

            $row['current_status'] =
                'Completed';


            // =============================================
            // GET FINAL ROUTE PROGRESS
            // =============================================

            $finalRouteId =
                intval(
                    $row['final_route_id'] ?? 0
                );


            if ($finalRouteId > 0) {

                $progressStmt =
                    mysqli_prepare(
                        $conn,
                        "
                        SELECT

                            send_qty,
                            receive_qty,
                            reject_qty,
                            send_date,
                            receive_date

                        FROM client_po_route_progress

                        WHERE route_id = ?

                        ORDER BY id DESC

                        LIMIT 1
                        "
                    );


                if ($progressStmt) {

                    mysqli_stmt_bind_param(
                        $progressStmt,
                        "i",
                        $finalRouteId
                    );


                    mysqli_stmt_execute(
                        $progressStmt
                    );


                    $progressResult =
                        mysqli_stmt_get_result(
                            $progressStmt
                        );


                    if (
                        $finalProgress =
                        mysqli_fetch_assoc(
                            $progressResult
                        )
                    ) {

                        $row['send_qty'] =
                            $finalProgress['send_qty'] ?? 0;

                        $row['good_qty'] =
                            $finalProgress['receive_qty'] ?? 0;

                        $row['reject_qty'] =
                            $finalProgress['reject_qty'] ?? 0;

                        $row['send_date'] =
                            $finalProgress['send_date'] ?? null;

                        $row['receive_date'] =
                            $finalProgress['receive_date'] ?? null;
                    }


                    mysqli_stmt_close(
                        $progressStmt
                    );
                }
            }


            // =============================================
            // NEXT = DISPATCH
            // =============================================

            $row['next_operation'] =
                'Ready for Dispatch';

            $row['next_operation_code'] =
                '';

            $row['next_vendor'] =
                '';

            $row['next_step'] =
                '';
        }


        // =================================================
        // QUANTITIES
        // =================================================

        $sendQty =
            floatval(
                $row['send_qty'] ?? 0
            );


        $goodQty =
            floatval(
                $row['good_qty'] ?? 0
            );


        $rejectQty =
            floatval(
                $row['reject_qty'] ?? 0
            );


        // =================================================
        // PENDING QTY
        // =================================================

        $pendingQty =
            $sendQty
            -
            $goodQty
            -
            $rejectQty;


        if ($pendingQty < 0) {
            $pendingQty = 0;
        }


        $row['pending_qty'] =
            $pendingQty;


        // =================================================
        // DAYS AT VENDOR
        // =================================================

        $row['days_at_vendor'] = null;


        if (!empty($row['send_date'])) {

            try {

                $sendDate =
                    new DateTime(
                        $row['send_date']
                    );


                /*
                 * Receive ho gaya:
                 * Send Date -> Receive Date
                 *
                 * Receive nahi hua:
                 * Send Date -> Today
                 */

                if (!empty($row['receive_date'])) {

                    $endDate =
                        new DateTime(
                            $row['receive_date']
                        );

                } else {

                    $endDate =
                        new DateTime();
                }


                if ($endDate >= $sendDate) {

                    $dateDiff =
                        $sendDate->diff(
                            $endDate
                        );


                    $row['days_at_vendor'] =
                        intval(
                            $dateDiff->days
                        );
                }

            } catch (Exception $e) {

                $row['days_at_vendor'] =
                    null;
            }
        }


        // =================================================
        // SAVE RESULT
        // =================================================

        $results[] = $row;
    }


    mysqli_stmt_close($stmt);
}



// =====================================================
// HEADER
// =====================================================

include("includes/header.php");
include("includes/sidebar.php");

?>


<!-- =====================================================
     CONTENT
===================================================== -->

<div class="content-page">

<div class="content">

<div class="container-fluid">


<!-- =====================================================
     PAGE TITLE
===================================================== -->

<div class="row">

<div class="col-12">

<div class="page-title-box">


    <div class="page-title-right">

        <a
            href="/erp/dashboard.php"
            class="btn btn-sm btn-light"
        >

            <i class="mdi mdi-arrow-left me-1"></i>

            Dashboard

        </a>

    </div>


    <h4 class="page-title">

        Product Tracking Search

    </h4>


</div>

</div>

</div>



<!-- =====================================================
     SEARCH BOX
===================================================== -->

<div class="row">

<div class="col-12">

<div class="card">

<div class="card-body">


<form
    action="/erp/search.php"
    method="GET"
>


<div class="row align-items-end">


<div class="col-lg-9 col-md-8 mb-2">


    <label class="form-label">

        Search PO / Client / Product / Part

    </label>


    <div class="input-group">


        <span class="input-group-text">

            <i class="mdi mdi-magnify"></i>

        </span>


        <input
            type="text"
            name="q"
            class="form-control"
            value="<?= htmlspecialchars($q); ?>"
            placeholder="Enter PO No., Client, Product or Part No."
            autofocus
        >


    </div>


</div>


<div class="col-lg-3 col-md-4 mb-2">


    <button
        type="submit"
        class="btn btn-primary w-100"
    >

        <i class="mdi mdi-magnify me-1"></i>

        Search Tracking

    </button>


</div>


</div>


</form>


</div>

</div>

</div>

</div>



<!-- =====================================================
     SEARCH RESULTS
===================================================== -->

<?php if ($q !== '') { ?>


<div class="row">

<div class="col-12">

<div class="card">

<div class="card-body">


<div class="d-flex justify-content-between align-items-center mb-3">


    <div>

        <h5 class="mb-1">

            Search Results

        </h5>


        <small class="text-muted">

            Search:

            <strong>
                <?= htmlspecialchars($q); ?>
            </strong>

        </small>

    </div>


    <span class="badge bg-primary">

        <?= count($results); ?>

        Result(s)

    </span>


</div>



<?php if (count($results) > 0) { ?>


<div class="table-responsive">


<table
    class="
        table
        table-hover
        table-bordered
        align-middle
        mb-0
    "
>


<thead class="table-light">

<tr>

    <th>PO Number</th>

    <th>Client</th>

    <th>Product</th>

    <th>Part No.</th>

    <th>Qty</th>

    <th>Current Operation</th>

    <th>Current Vendor</th>

    <th>Current Status</th>

    <th>Good Qty</th>

    <th>Reject Qty</th>

    <th>Pending Qty</th>

    <th>Send Date</th>

    <th>Receive Date</th>

    <th>Days at Vendor</th>

    <th>Next Operation</th>

    <th>Next Vendor</th>

    <th>PO Status</th>

    <th class="text-center">
        Tracking
    </th>

</tr>

</thead>



<tbody>


<?php foreach ($results as $row) { ?>


<tr>


<!-- =================================================
     PO NUMBER
================================================= -->

<td>

    <strong>

        <?= htmlspecialchars(
            $row['po_number'] ?? ''
        ); ?>

    </strong>


    <?php if (!empty($row['po_date'])) { ?>

        <br>

        <small class="text-muted">

            <?= date(
                'd-m-Y',
                strtotime(
                    $row['po_date']
                )
            ); ?>

        </small>

    <?php } ?>

</td>



<!-- =================================================
     CLIENT
================================================= -->

<td>

    <?= htmlspecialchars(
        $row['client_name'] ?? '-'
    ); ?>

</td>



<!-- =================================================
     PRODUCT
================================================= -->

<td>

    <strong>

        <?= htmlspecialchars(
            $row['product_name'] ?? '-'
        ); ?>

    </strong>


    <?php if (!empty($row['product_code'])) { ?>

        <br>

        <small class="text-muted">

            <?= htmlspecialchars(
                $row['product_code']
            ); ?>

        </small>

    <?php } ?>

</td>



<!-- =================================================
     PART NUMBER
================================================= -->

<td>

    <?= htmlspecialchars(
        $row['part_number'] ?? '-'
    ); ?>

</td>



<!-- =================================================
     ORDER QTY
================================================= -->

<td>

    <strong>

        <?= number_format(
            floatval(
                $row['order_qty'] ?? 0
            ),
            2
        ); ?>

    </strong>

</td>



<!-- =================================================
     CURRENT OPERATION
================================================= -->

<td>


<?php if (!empty($row['current_operation'])) { ?>


    <strong>

        <?= htmlspecialchars(
            $row['current_operation']
        ); ?>

    </strong>


    <?php if (!empty($row['current_operation_code'])) { ?>

        <br>

        <small class="text-muted">

            <?= htmlspecialchars(
                $row['current_operation_code']
            ); ?>

        </small>

    <?php } ?>


    <?php if (!empty($row['current_step'])) { ?>

        <br>

        <span class="badge bg-light text-dark mt-1">

            Step
            <?= intval(
                $row['current_step']
            ); ?>

        </span>

    <?php } ?>


<?php } else { ?>


    <span class="badge bg-secondary">

        Not Set

    </span>


<?php } ?>


</td>



<!-- =================================================
     CURRENT VENDOR
================================================= -->

<td>


<?php if (!empty($row['current_vendor'])) { ?>


    <strong>

        <?= htmlspecialchars(
            $row['current_vendor']
        ); ?>

    </strong>


<?php } else { ?>


    <span class="text-muted">

        -

    </span>


<?php } ?>


</td>



<!-- =================================================
     CURRENT STATUS
================================================= -->

<td>


<?php

$current_status =
    trim(
        $row['current_status'] ?? ''
    );


$status_class =
    'bg-secondary';


if ($current_status === 'Pending') {

    $status_class =
        'bg-warning text-dark';

} elseif (
    $current_status === 'In Process'
    ||
    $current_status === 'Running'
) {

    $status_class =
        'bg-info text-dark';

} elseif ($current_status === 'Completed') {

    $status_class =
        'bg-success';

} elseif ($current_status === 'Hold') {

    $status_class =
        'bg-danger';
}

?>


<?php if ($current_status !== '') { ?>


    <span
        class="badge <?= $status_class; ?>"
    >

        <?= htmlspecialchars(
            $current_status
        ); ?>

    </span>


<?php } else { ?>


    <span class="badge bg-secondary">

        Not Set

    </span>


<?php } ?>


</td>



<!-- =================================================
     GOOD QTY
================================================= -->

<td>

    <span class="text-success fw-bold">

        <?= number_format(
            floatval(
                $row['good_qty'] ?? 0
            ),
            2
        ); ?>

    </span>

</td>



<!-- =================================================
     REJECT QTY
================================================= -->

<td>

    <span class="text-danger fw-bold">

        <?= number_format(
            floatval(
                $row['reject_qty'] ?? 0
            ),
            2
        ); ?>

    </span>

</td>



<!-- =================================================
     PENDING QTY
================================================= -->

<td>


<?php

$pending_qty =
    floatval(
        $row['pending_qty'] ?? 0
    );

?>


<?php if ($pending_qty > 0) { ?>


    <span class="text-warning fw-bold">

        <?= number_format(
            $pending_qty,
            2
        ); ?>

    </span>


<?php } else { ?>


    <span class="text-muted">

        0.00

    </span>


<?php } ?>


</td>



<!-- =================================================
     SEND DATE
================================================= -->

<td>


<?php if (!empty($row['send_date'])) { ?>


    <strong>

        <?= date(
            'd-m-Y',
            strtotime(
                $row['send_date']
            )
        ); ?>

    </strong>


<?php } else { ?>


    <span class="text-muted">

        -

    </span>


<?php } ?>


</td>



<!-- =================================================
     RECEIVE DATE
================================================= -->

<td>


<?php if (!empty($row['receive_date'])) { ?>


    <strong>

        <?= date(
            'd-m-Y',
            strtotime(
                $row['receive_date']
            )
        ); ?>

    </strong>


<?php } else { ?>


    <?php if (!empty($row['send_date'])) { ?>


        <span class="badge bg-warning text-dark">

            Awaiting

        </span>


    <?php } else { ?>


        <span class="text-muted">

            -

        </span>


    <?php } ?>


<?php } ?>


</td>



<!-- =================================================
     DAYS AT VENDOR
================================================= -->

<td>


<?php if ($row['days_at_vendor'] !== null) { ?>


    <?php

    $days =
        intval(
            $row['days_at_vendor']
        );

    ?>


    <?php if (
        empty($row['receive_date'])
    ) { ?>


        <?php if ($days >= 7) { ?>


            <span class="badge bg-danger">

                <?= $days; ?> Day(s)

            </span>


        <?php } elseif ($days >= 3) { ?>


            <span class="badge bg-warning text-dark">

                <?= $days; ?> Day(s)

            </span>


        <?php } else { ?>


            <span class="badge bg-info text-dark">

                <?= $days; ?> Day(s)

            </span>


        <?php } ?>


    <?php } else { ?>


        <span class="badge bg-success">

            <?= $days; ?> Day(s)

        </span>


    <?php } ?>


<?php } else { ?>


    <span class="text-muted">

        -

    </span>


<?php } ?>


</td>



<!-- =================================================
     NEXT OPERATION
================================================= -->

<td>


<?php if (
    !empty(
        $row['all_routes_completed']
    )
) { ?>


    <span class="badge bg-success">

        <i class="mdi mdi-truck-check-outline me-1"></i>

        Ready for Dispatch

    </span>


<?php } elseif (
    !empty(
        $row['next_operation']
    )
) { ?>


    <strong>

        <?= htmlspecialchars(
            $row['next_operation']
        ); ?>

    </strong>


    <?php if (
        !empty(
            $row['next_operation_code']
        )
    ) { ?>


        <br>

        <small class="text-muted">

            <?= htmlspecialchars(
                $row['next_operation_code']
            ); ?>

        </small>


    <?php } ?>


    <?php if (
        !empty(
            $row['next_step']
        )
    ) { ?>


        <br>

        <span class="badge bg-light text-dark mt-1">

            Step
            <?= intval(
                $row['next_step']
            ); ?>

        </span>


    <?php } ?>


<?php } else { ?>


    <?php if (
        intval(
            $row['route_count'] ?? 0
        ) === 0
    ) { ?>


        <span class="badge bg-secondary">

            Not Set

        </span>


    <?php } else { ?>


        <span class="badge bg-success">

            Final Stage

        </span>


    <?php } ?>


<?php } ?>


</td>



<!-- =================================================
     NEXT VENDOR
================================================= -->

<td>


<?php if (
    !empty(
        $row['next_vendor']
    )
) { ?>


    <?= htmlspecialchars(
        $row['next_vendor']
    ); ?>


<?php } elseif (
    !empty(
        $row['all_routes_completed']
    )
) { ?>


    <span class="text-success fw-bold">

        Dispatch

    </span>


<?php } else { ?>


    <span class="text-muted">

        -

    </span>


<?php } ?>


</td>



<!-- =================================================
     PO STATUS
================================================= -->

<td>


<?php

$po_status =
    trim(
        $row['po_status'] ?? ''
    );


$po_class =
    'bg-secondary';


if ($po_status === 'Open') {

    $po_class =
        'bg-primary';

} elseif ($po_status === 'Pending') {

    $po_class =
        'bg-warning text-dark';

} elseif (
    $po_status === 'In Production'
    ||
    $po_status === 'In Process'
) {

    $po_class =
        'bg-info text-dark';

} elseif ($po_status === 'Completed') {

    $po_class =
        'bg-success';

} elseif (
    $po_status === 'Partially Dispatched'
) {

    $po_class =
        'bg-warning text-dark';

} elseif ($po_status === 'Dispatched') {

    $po_class =
        'bg-info text-dark';

} elseif ($po_status === 'Closed') {

    $po_class =
        'bg-dark';
}

?>


<span
    class="badge <?= $po_class; ?>"
>

    <?= htmlspecialchars(
        $po_status ?: '-'
    ); ?>

</span>


</td>



<!-- =================================================
     TRACKING
================================================= -->

<td class="text-center">


<a
    href="/erp/client_po/view.php?id=<?= intval(
        $row['client_po_id']
    ); ?>"
    class="btn btn-sm btn-primary"
>

    <i class="mdi mdi-map-marker-path me-1"></i>

    View Tracking

</a>


</td>


</tr>


<?php } ?>


</tbody>


</table>


</div>


<?php } else { ?>


<div class="alert alert-warning mb-0">

    <i class="mdi mdi-alert-outline me-1"></i>

    No Client PO found for

    <strong>
        <?= htmlspecialchars($q); ?>
    </strong>

</div>


<?php } ?>


</div>

</div>

</div>

</div>


<?php } else { ?>


<!-- =====================================================
     EMPTY SEARCH
===================================================== -->

<div class="row">

<div class="col-12">

<div class="card">

<div class="card-body text-center py-5">


    <i
        class="
            mdi
            mdi-magnify
            display-4
            text-muted
        "
    ></i>


    <h5 class="mt-3">

        Search Product Tracking

    </h5>


    <p class="text-muted mb-0">

        Enter Client PO Number,
        Client Name,
        Product Name,
        Product Code
        or Part Number.

    </p>


</div>

</div>

</div>

</div>


<?php } ?>


</div>

</div>

</div>


<!-- =====================================================
     FOOTER
===================================================== -->

<?php

include("includes/footer.php");

?>