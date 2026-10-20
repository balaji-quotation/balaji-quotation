<?php

mysqli_report(MYSQLI_REPORT_OFF);

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include("includes/db.php");
include("includes/header.php");
include("includes/sidebar.php");

function dashQty($qty)
{
    $qty = floatval($qty);
    return floor($qty) == $qty ? number_format($qty, 0) : number_format($qty, 2);
}

function dashDate($date)
{
    if (empty($date) || $date === '0000-00-00') {
        return '-';
    }

    return date('d-m-Y', strtotime($date));
}

function dashPoBadge($status)
{
    $status = trim($status ?? '');

    $map = [
        'Open' => 'bg-primary',
        'In Production' => 'bg-warning text-dark',
        'Completed' => 'bg-success',
        'Partially Dispatched' => 'bg-warning text-dark',
        'Dispatched' => 'bg-info text-dark',
        'Closed' => 'bg-dark text-white'
    ];

    $class = $map[$status] ?? 'bg-secondary';

    return '<span class="badge ' . $class . '">' .
        htmlspecialchars($status !== '' ? $status : '-') .
        '</span>';
}

function dashDispatchBadge($status)
{
    $status = trim($status ?? '');

    if ($status === 'Delivered') {
        return '<span class="badge bg-success">Delivered</span>';
    }

    if ($status === 'Dispatched') {
        return '<span class="badge bg-primary">Dispatched</span>';
    }

    return '<span class="badge bg-warning text-dark">' .
        htmlspecialchars($status !== '' ? $status : 'Pending') .
        '</span>';
}


function dashRejectionBadge($status)
{
    $status = trim($status ?? '');

    $map = [
        'Replacement Pending' => 'bg-danger',
        'Replacement Received' => 'bg-info text-dark',
        'Closed' => 'bg-success',
        'Pending' => 'bg-secondary',
        'In Process' => 'bg-warning text-dark',
        'Sent' => 'bg-primary',
        'Received' => 'bg-info text-dark',
        'Completed' => 'bg-success',
        'Hold' => 'bg-danger'
    ];

    $class = $map[$status] ?? 'bg-secondary';

    return '<span class="badge ' . $class . '">' .
        htmlspecialchars($status !== '' ? $status : 'Pending') .
        '</span>';
}


// =====================================================
// PO STATUS KPI
// =====================================================

$sql = "
    SELECT
        COUNT(DISTINCT id) AS total_po,
        COUNT(DISTINCT CASE WHEN status='Open' THEN id END) AS open_po,
        COUNT(DISTINCT CASE WHEN status='In Production' THEN id END) AS in_production_po,
        COUNT(DISTINCT CASE WHEN status='Completed' THEN id END) AS completed_po,
        COUNT(DISTINCT CASE WHEN status='Partially Dispatched' THEN id END) AS partially_dispatched_po,
        COUNT(DISTINCT CASE WHEN status='Dispatched' THEN id END) AS dispatched_po,
        COUNT(DISTINCT CASE WHEN status='Closed' THEN id END) AS closed_po
    FROM client_po
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die("Dashboard PO Summary Error: " . mysqli_error($conn));
}

$row = mysqli_fetch_assoc($result);

$total_po = intval($row['total_po'] ?? 0);
$open_po = intval($row['open_po'] ?? 0);
$in_production_po = intval($row['in_production_po'] ?? 0);
$completed_po = intval($row['completed_po'] ?? 0);
$partially_dispatched_po = intval($row['partially_dispatched_po'] ?? 0);
$dispatched_po = intval($row['dispatched_po'] ?? 0);
$closed_po = intval($row['closed_po'] ?? 0);

$dispatch_total_po =
    $partially_dispatched_po +
    $dispatched_po;


// =====================================================
// FRESH PRODUCTION QTY
// Order Qty - Carry Forward Used
// =====================================================

$sql = "
    SELECT
        COALESCE(
            SUM(
                GREATEST(
                    0,
                    cpi.order_qty -
                    COALESCE(
                        (
                            SELECT SUM(cfu.used_qty)
                            FROM client_po_carry_forward_usage cfu
                            WHERE cfu.used_client_po_item_id = cpi.id
                        ),
                        0
                    )
                )
            ),
            0
        ) AS fresh_production_qty
    FROM client_po_items cpi
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die("Dashboard Fresh Production Error: " . mysqli_error($conn));
}

$row = mysqli_fetch_assoc($result);

$fresh_production_qty = floatval(
    $row['fresh_production_qty'] ?? 0
);


// =====================================================
// CARRY FORWARD AVAILABLE STOCK
// =====================================================

$sql = "
    SELECT
        COALESCE(SUM(available_qty), 0) AS available_stock
    FROM client_po_carry_forward_stock
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die("Dashboard Carry Forward Error: " . mysqli_error($conn));
}

$row = mysqli_fetch_assoc($result);

$carry_forward_stock = floatval(
    $row['available_stock'] ?? 0
);


// =====================================================
// DELIVERY SUMMARY
// Upcoming window = next 7 days
// =====================================================

$sql = "
    SELECT
        COUNT(DISTINCT CASE
            WHEN delivery_date IS NOT NULL
             AND YEAR(delivery_date) > 0
             AND delivery_date < CURDATE()
             AND status NOT IN ('Dispatched', 'Closed')
            THEN id END
        ) AS overdue_count,

        COUNT(DISTINCT CASE
            WHEN delivery_date = CURDATE()
             AND status NOT IN ('Dispatched', 'Closed')
            THEN id END
        ) AS due_today_count,

        COUNT(DISTINCT CASE
            WHEN delivery_date > CURDATE()
             AND delivery_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             AND status NOT IN ('Dispatched', 'Closed')
            THEN id END
        ) AS upcoming_count

    FROM client_po
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die("Dashboard Delivery Summary Error: " . mysqli_error($conn));
}

$row = mysqli_fetch_assoc($result);

$delivery_overdue_count = intval($row['overdue_count'] ?? 0);
$delivery_due_today_count = intval($row['due_today_count'] ?? 0);
$delivery_upcoming_count = intval($row['upcoming_count'] ?? 0);


// =====================================================
// DELIVERY ALERT LIST
// =====================================================

$delivery_alert_sql = "
    SELECT
        cp.id AS client_po_id,
        cp.po_number,
        cp.delivery_date,
        cp.status AS po_status,
        c.company_name AS client_name,
        p.product_name,
        p.part_number,
        p.product_image
    FROM client_po cp
    INNER JOIN clients c
        ON c.id = cp.client_id
    INNER JOIN client_po_items cpi
        ON cpi.client_po_id = cp.id
    INNER JOIN products p
        ON p.id = cpi.product_id
    WHERE cp.delivery_date IS NOT NULL
      AND YEAR(cp.delivery_date) > 0
      AND cp.delivery_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
      AND cp.status NOT IN ('Dispatched', 'Closed')
    ORDER BY
        CASE
            WHEN cp.delivery_date < CURDATE() THEN 1
            WHEN cp.delivery_date = CURDATE() THEN 2
            ELSE 3
        END ASC,
        cp.delivery_date ASC,
        cp.id DESC
    LIMIT 10
";

$delivery_alert_result = mysqli_query($conn, $delivery_alert_sql);

if (!$delivery_alert_result) {
    die("Dashboard Delivery Alert Error: " . mysqli_error($conn));
}


// =====================================================
// OPERATION EXPECTED RECEIVE SUMMARY
//
// Active vendor operation = route has vendor assigned,
// material not actually received, and progress is still active.
//
// IMPORTANT:
// Missing Expected Receive Date is now also highlighted
// so the dashboard does not look blank when a vendor stage exists.
// =====================================================

$sql = "
    SELECT

        COUNT(*) AS total_pending,

        SUM(
            CASE
                WHEN
                    (
                        cpp.expected_receive_date IS NULL
                        OR YEAR(cpp.expected_receive_date) = 0
                    )
                THEN 1
                ELSE 0
            END
        ) AS date_not_set_count,

        SUM(
            CASE
                WHEN
                    cpp.expected_receive_date IS NOT NULL
                    AND YEAR(cpp.expected_receive_date) > 0
                    AND cpp.expected_receive_date < CURDATE()
                THEN 1
                ELSE 0
            END
        ) AS overdue_count,

        SUM(
            CASE
                WHEN cpp.expected_receive_date = CURDATE()
                THEN 1
                ELSE 0
            END
        ) AS due_today_count,

        SUM(
            CASE
                WHEN
                    cpp.expected_receive_date > CURDATE()
                    AND cpp.expected_receive_date <= DATE_ADD(
                        CURDATE(),
                        INTERVAL 2 DAY
                    )
                THEN 1
                ELSE 0
            END
        ) AS upcoming_count

    FROM client_po_route_progress cpp

    INNER JOIN client_po_routes cpr
        ON cpr.id = cpp.route_id

    WHERE
        (
            cpp.receive_date IS NULL
            OR YEAR(cpp.receive_date) = 0
        )


    AND cpr.id = (
        SELECT cpr2.id

        FROM client_po_routes cpr2

        LEFT JOIN client_po_route_progress cpp2
            ON cpp2.id = (
                SELECT cpp3.id
                FROM client_po_route_progress cpp3
                WHERE cpp3.route_id = cpr2.id
                  AND cpp3.client_po_item_id = cpr2.client_po_item_id
                ORDER BY cpp3.id DESC
                LIMIT 1
            )

        WHERE cpr2.client_po_item_id = cpr.client_po_item_id

          AND COALESCE(
                cpp2.status,
                cpr2.status,
                'Pending'
              ) != 'Completed'

        ORDER BY cpr2.sequence_no ASC, cpr2.id ASC
        LIMIT 1
    )

    AND cpp.status IN
    (
        'Sent',
        'In Process',
        'Pending'
    )
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die("Dashboard Vendor Alert Summary Error: " . mysqli_error($conn));
}

$row = mysqli_fetch_assoc($result);

$vendor_pending_count = intval($row['total_pending'] ?? 0);
$vendor_date_not_set_count = intval($row['date_not_set_count'] ?? 0);
$vendor_overdue_count = intval($row['overdue_count'] ?? 0);
$vendor_due_today_count = intval($row['due_today_count'] ?? 0);
$vendor_upcoming_count = intval($row['upcoming_count'] ?? 0);


// =====================================================
// OPERATION EXPECTED RECEIVE LIST
// =====================================================

$vendor_alert_sql = "
    SELECT
        cpp.id AS progress_id,
        cpp.expected_receive_date,
        cpp.status AS progress_status,
        cpp.send_qty,
        cpp.receive_qty,
        cpp.reject_qty,
        GREATEST(
            0,
            COALESCE(cpp.send_qty, 0)
            - COALESCE(cpp.receive_qty, 0)
            - COALESCE(cpp.reject_qty, 0)
        ) AS pending_qty,
        cpr.sequence_no,
        o.operation_name,
        v.company_name AS vendor_name,
        cp.po_number,
        c.company_name AS client_name,
        p.product_name,
        p.part_number,
        p.product_image
    FROM client_po_route_progress cpp
    INNER JOIN client_po_routes cpr
        ON cpr.id = cpp.route_id
    INNER JOIN operations o
        ON o.id = cpr.operation_id
    LEFT JOIN vendors v
        ON v.id = cpr.vendor_id
    INNER JOIN client_po_items cpi
        ON cpi.id = cpp.client_po_item_id
    INNER JOIN client_po cp
        ON cp.id = cpi.client_po_id
    INNER JOIN clients c
        ON c.id = cp.client_id
    INNER JOIN products p
        ON p.id = cpi.product_id
    WHERE cp.status != 'Closed'

      AND GREATEST(
          0,
          COALESCE(cpp.send_qty, 0)
          - COALESCE(cpp.receive_qty, 0)
          - COALESCE(cpp.reject_qty, 0)
      ) > 0

      AND cpr.id = (
          SELECT cpr2.id
          FROM client_po_routes cpr2
          LEFT JOIN client_po_route_progress cpp2
              ON cpp2.id = (
                  SELECT cpp3.id
                  FROM client_po_route_progress cpp3
                  WHERE cpp3.route_id = cpr2.id
                    AND cpp3.client_po_item_id = cpr2.client_po_item_id
                  ORDER BY cpp3.id DESC
                  LIMIT 1
              )
          WHERE cpr2.client_po_item_id = cpr.client_po_item_id
            AND GREATEST(
                  0,
                  COALESCE(cpp2.send_qty, 0)
                  - COALESCE(cpp2.receive_qty, 0)
                  - COALESCE(cpp2.reject_qty, 0)
                ) > 0
          ORDER BY cpr2.sequence_no ASC, cpr2.id ASC
          LIMIT 1
      )
    ORDER BY
        CASE
            WHEN cpp.expected_receive_date IS NOT NULL
             AND YEAR(cpp.expected_receive_date) > 0
             AND cpp.expected_receive_date < CURDATE()
            THEN 1
            WHEN cpp.expected_receive_date = CURDATE()
            THEN 2
            WHEN cpp.expected_receive_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
            THEN 3
            WHEN cpp.expected_receive_date = DATE_ADD(CURDATE(), INTERVAL 2 DAY)
            THEN 4
            WHEN cpp.expected_receive_date IS NULL
              OR YEAR(cpp.expected_receive_date) = 0
            THEN 5
            ELSE 6
        END ASC,
        cpp.expected_receive_date ASC,
        cpp.id DESC
    LIMIT 10
";

$vendor_alert_result = mysqli_query($conn, $vendor_alert_sql);

if (!$vendor_alert_result) {
    die("Dashboard Vendor Alert Error: " . mysqli_error($conn));
}


// =====================================================
// REJECTION / REPLACEMENT SUMMARY
// =====================================================

$sql = "
    SELECT
        COALESCE(SUM(reject_qty),0) AS total_reject_qty,
        COALESCE(SUM(replacement_qty),0) AS replacement_required_qty,
        COALESCE(SUM(replacement_received_qty),0) AS replacement_received_qty
    FROM client_po_rejections
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die("Dashboard Quality Summary Error: " . mysqli_error($conn));
}

$row = mysqli_fetch_assoc($result);

$total_reject_qty = floatval($row['total_reject_qty'] ?? 0);
$replacement_required_qty = floatval(
    $row['replacement_required_qty'] ?? 0
);
$replacement_received_qty = floatval(
    $row['replacement_received_qty'] ?? 0
);

$replacement_pending_qty =
    $replacement_required_qty -
    $replacement_received_qty;

if ($replacement_pending_qty < 0) {
    $replacement_pending_qty = 0;
}


// =====================================================
// REJECTION CASE COUNTS
// =====================================================

$rejection_case_sql = "
    SELECT
        COUNT(*) AS total_cases,
        SUM(
            CASE
                WHEN status != 'Closed'
                THEN 1
                ELSE 0
            END
        ) AS active_cases,
        SUM(
            CASE
                WHEN status = 'Closed'
                THEN 1
                ELSE 0
            END
        ) AS closed_cases
    FROM client_po_rejections
";

$rejection_case_result =
    mysqli_query(
        $conn,
        $rejection_case_sql
    );

if (!$rejection_case_result) {
    die(
        "Dashboard Rejection Case Summary Error: " .
        mysqli_error($conn)
    );
}

$rejection_case_row =
    mysqli_fetch_assoc(
        $rejection_case_result
    );

$total_rejection_cases =
    intval(
        $rejection_case_row[
            'total_cases'
        ] ?? 0
    );

$active_rejection_cases =
    intval(
        $rejection_case_row[
            'active_cases'
        ] ?? 0
    );

$closed_rejection_cases =
    intval(
        $rejection_case_row[
            'closed_cases'
        ] ?? 0
    );


// =====================================================
// REJECTION / REPLACEMENT TRACKING
//
// Shows the latest replacement batch for each rejection
// and the batch's current route / vendor.
// =====================================================

$rejection_tracking_sql = "
    SELECT

        r.id AS rejection_id,
        r.client_po_item_id,
        r.reject_qty,
        r.replacement_qty,
        r.replacement_received_qty,
        r.defect_reason,
        r.status AS rejection_status,

        cpi.client_po_id,
        cp.po_number,
        c.company_name AS client_name,

        p.product_name,
        p.part_number,

        rb.id AS replacement_batch_id,
        rb.batch_no,
        rb.status AS batch_status,
        rb.current_route_id,

        cpr.sequence_no AS current_sequence,
        o.operation_name AS current_operation,
        v.company_name AS vendor_name,

        rrp.id AS replacement_progress_id,
        rrp.send_qty,
        rrp.receive_qty,
        rrp.reject_qty AS route_reject_qty,
        rrp.send_date,
        rrp.receive_date,
        rrp.status AS progress_status

    FROM client_po_rejections r

    INNER JOIN client_po_items cpi
        ON cpi.id = r.client_po_item_id

    INNER JOIN client_po cp
        ON cp.id = cpi.client_po_id

    INNER JOIN clients c
        ON c.id = cp.client_id

    INNER JOIN products p
        ON p.id = cpi.product_id

    LEFT JOIN client_po_replacement_batches rb
        ON rb.id = (
            SELECT MAX(rb2.id)
            FROM client_po_replacement_batches rb2
            WHERE rb2.rejection_id = r.id
        )

    LEFT JOIN client_po_replacement_route_progress rrp
        ON rrp.id = COALESCE(
            (
                SELECT rrp2.id
                FROM client_po_replacement_route_progress rrp2
                WHERE rrp2.replacement_batch_id = rb.id
                  AND COALESCE(rrp2.status, 'Pending') != 'Completed'
                ORDER BY rrp2.sequence_no ASC, rrp2.id ASC
                LIMIT 1
            ),
            (
                SELECT rrp3.id
                FROM client_po_replacement_route_progress rrp3
                WHERE rrp3.replacement_batch_id = rb.id
                ORDER BY rrp3.sequence_no DESC, rrp3.id DESC
                LIMIT 1
            )
        )

    LEFT JOIN client_po_routes cpr
        ON cpr.id = rrp.route_id

    LEFT JOIN operations o
        ON o.id = cpr.operation_id

    LEFT JOIN vendors v
        ON v.id = cpr.vendor_id

    ORDER BY
        CASE
            WHEN r.status = 'Closed' THEN 2
            ELSE 1
        END ASC,
        r.id DESC

    LIMIT 12
";

$rejection_tracking_result =
    mysqli_query(
        $conn,
        $rejection_tracking_sql
    );

if (!$rejection_tracking_result) {
    die(
        "Dashboard Rejection Tracking Error: " .
        mysqli_error($conn)
    );
}


// =====================================================
// RECENT DISPATCHES
// =====================================================

$recent_dispatch_sql = "
    SELECT
        d.id,
        d.dispatch_no,
        d.dispatch_date,
        d.dispatch_qty,
        d.normal_dispatch_qty,
        d.extra_replacement_qty,
        d.transport_name,
        d.dispatch_status,
        cp.id AS client_po_id,
        cp.po_number,
        c.company_name AS client_name,
        p.product_name,
        p.part_number
    FROM dispatch d
    INNER JOIN client_po_items cpi
        ON cpi.id = d.client_po_item_id
    INNER JOIN client_po cp
        ON cp.id = cpi.client_po_id
    INNER JOIN clients c
        ON c.id = cp.client_id
    INNER JOIN products p
        ON p.id = cpi.product_id
    ORDER BY d.dispatch_date DESC, d.id DESC
    LIMIT 10
";

$recent_dispatch_result = mysqli_query($conn, $recent_dispatch_sql);

if (!$recent_dispatch_result) {
    die("Dashboard Recent Dispatch Error: " . mysqli_error($conn));
}


// =====================================================
// CURRENT / NEXT PROCESS TRACKING
//
// Same working rule as client_po/progress.php:
// Current = first route which is NOT Completed.
// If all routes are completed, current = last route.
// Next = route immediately after current.
// =====================================================

$process_tracking_sql = "
    SELECT

        cpi.id AS item_id,
        cpi.client_po_id,

        cp.po_number,
        cp.status AS po_status,

        c.company_name AS client_name,

        p.product_name,
        p.part_number,

        cpr.id AS route_id,
        cpr.sequence_no,
        cpr.status AS route_status,

        o.operation_name,

        v.company_name AS vendor_name,

        cpp.id AS progress_id,
        cpp.status AS progress_status,
        cpp.expected_receive_date

    FROM client_po_items cpi

    INNER JOIN client_po cp
        ON cp.id = cpi.client_po_id

    INNER JOIN clients c
        ON c.id = cp.client_id

    INNER JOIN products p
        ON p.id = cpi.product_id

    LEFT JOIN client_po_routes cpr
        ON cpr.client_po_item_id = cpi.id

    LEFT JOIN operations o
        ON o.id = cpr.operation_id

    LEFT JOIN vendors v
        ON v.id = cpr.vendor_id

    LEFT JOIN client_po_route_progress cpp
        ON cpp.route_id = cpr.id
       AND cpp.client_po_item_id = cpi.id

    WHERE cp.status != 'Closed'

    ORDER BY
        cpi.id DESC,
        cpr.sequence_no ASC
";

$process_tracking_result =
    mysqli_query(
        $conn,
        $process_tracking_sql
    );

if (!$process_tracking_result) {

    die(
        "Dashboard Process Tracking Error: " .
        mysqli_error($conn)
    );
}


$process_items = [];


while (
    $process_row =
    mysqli_fetch_assoc(
        $process_tracking_result
    )
) {

    $process_item_id =
        intval(
            $process_row[
                'item_id'
            ] ?? 0
        );


    if (
        !isset(
            $process_items[
                $process_item_id
            ]
        )
    ) {

        $process_items[
            $process_item_id
        ] = [
            'item_id' =>
                $process_item_id,

            'client_po_id' =>
                intval(
                    $process_row[
                        'client_po_id'
                    ] ?? 0
                ),

            'po_number' =>
                $process_row[
                    'po_number'
                ] ?? '',

            'po_status' =>
                $process_row[
                    'po_status'
                ] ?? '',

            'client_name' =>
                $process_row[
                    'client_name'
                ] ?? '',

            'product_name' =>
                $process_row[
                    'product_name'
                ] ?? '',

            'part_number' =>
                $process_row[
                    'part_number'
                ] ?? '',

            'routes' => []
        ];
    }


    if (
        !empty(
            $process_row[
                'route_id'
            ]
        )
    ) {

        $process_items[
            $process_item_id
        ][
            'routes'
        ][] =
            $process_row;
    }
}


$process_tracking_rows = [];


foreach (
    $process_items
    as $process_item
) {

    $routes =
        $process_item[
            'routes'
        ];


    if (
        count(
            $routes
        ) === 0
    ) {

        $process_item[
            'current'
        ] = null;

        $process_item[
            'next'
        ] = null;

        $process_item[
            'all_completed'
        ] = false;

        $process_tracking_rows[] =
            $process_item;

        continue;
    }


    $current_index = null;


    foreach (
        $routes
        as $route_index =>
           $route
    ) {

        $route_status =
            trim(
                $route[
                    'progress_status'
                ]
                ??
                $route[
                    'route_status'
                ]
                ??
                'Pending'
            );


        if (
            $route_status !==
            'Completed'
        ) {

            $current_index =
                $route_index;

            break;
        }
    }


    $all_completed = false;


    if (
        $current_index === null
    ) {

        $all_completed = true;

        $current_index =
            count(
                $routes
            ) - 1;
    }


    $process_item[
        'current'
    ] =
        $routes[
            $current_index
        ];


    $process_item[
        'next'
    ] =
        $routes[
            $current_index + 1
        ] ?? null;


    $process_item[
        'all_completed'
    ] =
        $all_completed;


    $process_tracking_rows[] =
        $process_item;
}


// Show recent / active 10 PO items.

$process_tracking_rows =
    array_slice(
        $process_tracking_rows,
        0,
        10
    );


// =====================================================
// DISPATCH TREND CHART DATA
//
// Recent 10 dispatch dates.
// Only Dispatched / Delivered records are counted.
// Normal Qty and Extra Replacement Qty remain separate.
// =====================================================

$dispatch_trend_sql = "
    SELECT
        dispatch_date,

        COALESCE(
            SUM(normal_dispatch_qty),
            0
        ) AS normal_qty,

        COALESCE(
            SUM(extra_replacement_qty),
            0
        ) AS extra_qty,

        COALESCE(
            SUM(dispatch_qty),
            0
        ) AS total_qty

    FROM dispatch

    WHERE dispatch_date IS NOT NULL

    AND YEAR(dispatch_date) > 0

    AND dispatch_status IN
    (
        'Dispatched',
        'Delivered'
    )

    GROUP BY dispatch_date

    ORDER BY dispatch_date DESC

    LIMIT 10
";

$dispatch_trend_result =
    mysqli_query(
        $conn,
        $dispatch_trend_sql
    );

if (!$dispatch_trend_result) {

    die(
        "Dashboard Dispatch Trend Error: " .
        mysqli_error($conn)
    );
}


$dispatch_trend_rows = [];

while (
    $trend_row =
    mysqli_fetch_assoc(
        $dispatch_trend_result
    )
) {

    $dispatch_trend_rows[] =
        $trend_row;
}


// Oldest to newest for chart.

$dispatch_trend_rows =
    array_reverse(
        $dispatch_trend_rows
    );


$dispatch_trend_labels = [];

$dispatch_trend_normal = [];

$dispatch_trend_extra = [];

$dispatch_trend_total = [];


foreach (
    $dispatch_trend_rows
    as $trend_row
) {

    $dispatch_trend_labels[] =
        !empty(
            $trend_row[
                'dispatch_date'
            ]
        )
            ? date(
                'd M',
                strtotime(
                    $trend_row[
                        'dispatch_date'
                    ]
                )
            )
            : '-';


    $dispatch_trend_normal[] =
        floatval(
            $trend_row[
                'normal_qty'
            ] ?? 0
        );


    $dispatch_trend_extra[] =
        floatval(
            $trend_row[
                'extra_qty'
            ] ?? 0
        );


    $dispatch_trend_total[] =
        floatval(
            $trend_row[
                'total_qty'
            ] ?? 0
        );
}


$dispatch_trend_labels_json =
    json_encode(
        $dispatch_trend_labels
    );

$dispatch_trend_normal_json =
    json_encode(
        $dispatch_trend_normal
    );

$dispatch_trend_extra_json =
    json_encode(
        $dispatch_trend_extra
    );

$dispatch_trend_total_json =
    json_encode(
        $dispatch_trend_total
    );


// =====================================================
// DASHBOARD PROFITABILITY SUMMARY
// Same costing basis as Cost & Profit Report:
// Sales - (Production + Packing + Additional)
// =====================================================

$profitability_sql = "
    SELECT
        cp.id AS client_po_id,
        cpi.id AS item_id,
        cpi.order_qty,
        cpi.part_weight,
        cpi.rate,
        cpi.rate_unit,

        COALESCE((
            SELECT SUM(
                CASE
                    WHEN UPPER(COALESCE(cpr.rate_unit, 'PCS')) = 'KG'
                    THEN COALESCE(rp.good_receive_qty, 0)
                         * COALESCE(cpi.part_weight, 0)
                         * COALESCE(cpr.process_rate, 0)
                    ELSE COALESCE(rp.good_receive_qty, 0)
                         * COALESCE(cpr.process_rate, 0)
                END
            )
            FROM client_po_routes cpr
            LEFT JOIN (
                SELECT
                    route_id,
                    MAX(COALESCE(receive_qty, 0)) AS good_receive_qty
                FROM client_po_route_progress
                GROUP BY route_id
            ) rp
                ON rp.route_id = cpr.id
            WHERE cpr.client_po_item_id = cpi.id
        ), 0) AS production_cost,

        COALESCE((
            SELECT SUM(
                (
                    CASE
                        WHEN cpp.actual_used_qty IS NOT NULL
                        THEN cpp.actual_used_qty
                        ELSE cpp.qty
                    END
                ) * COALESCE(cpp.rate, 0)
            )
            FROM client_po_packing cpp
            WHERE cpp.client_po_item_id = cpi.id
        ), 0) AS packing_cost,

        COALESCE((
            SELECT SUM(ac.amount)
            FROM client_po_additional_costs ac
            WHERE ac.client_po_item_id = cpi.id
        ), 0) AS additional_cost

    FROM client_po cp
    INNER JOIN client_po_items cpi
        ON cpi.client_po_id = cp.id
";

$profitability_result = mysqli_query($conn, $profitability_sql);

if (!$profitability_result) {
    die(
        "Dashboard Profitability Error: " .
        mysqli_error($conn)
    );
}

$dashboard_total_sales = 0;
$dashboard_total_cost = 0;
$dashboard_net_profit = 0;
$dashboard_profit_margin = 0;
$dashboard_loss_po_count = 0;

$dashboard_po_profit = [];

while ($profit_row = mysqli_fetch_assoc($profitability_result)) {

    $profit_order_qty =
        floatval($profit_row['order_qty'] ?? 0);

    $profit_part_weight =
        floatval($profit_row['part_weight'] ?? 0);

    $profit_rate =
        floatval($profit_row['rate'] ?? 0);

    $profit_rate_unit =
        strtoupper(
            trim(
                $profit_row['rate_unit'] ?? 'PCS'
            )
        );

    if ($profit_rate_unit === 'KG') {

        $profit_sales =
            $profit_order_qty *
            $profit_part_weight *
            $profit_rate;

    } else {

        $profit_sales =
            $profit_order_qty *
            $profit_rate;
    }

    $profit_production_cost =
        floatval(
            $profit_row['production_cost'] ?? 0
        );

    $profit_packing_cost =
        floatval(
            $profit_row['packing_cost'] ?? 0
        );

    $profit_additional_cost =
        floatval(
            $profit_row['additional_cost'] ?? 0
        );

    $profit_total_cost =
        $profit_production_cost +
        $profit_packing_cost +
        $profit_additional_cost;

    $profit_value =
        $profit_sales -
        $profit_total_cost;

    $dashboard_total_sales +=
        $profit_sales;

    $dashboard_total_cost +=
        $profit_total_cost;

    $dashboard_net_profit +=
        $profit_value;

    $profit_po_id =
        intval(
            $profit_row['client_po_id'] ?? 0
        );

    if (!isset($dashboard_po_profit[$profit_po_id])) {
        $dashboard_po_profit[$profit_po_id] = 0;
    }

    $dashboard_po_profit[$profit_po_id] +=
        $profit_value;
}

if ($dashboard_total_sales > 0) {

    $dashboard_profit_margin =
        (
            $dashboard_net_profit /
            $dashboard_total_sales
        ) * 100;
}

foreach ($dashboard_po_profit as $po_profit_value) {

    if ($po_profit_value < 0) {
        $dashboard_loss_po_count++;
    }
}


// =====================================================
// PO STATUS DONUT CHART DATA
// =====================================================

$po_status_chart_labels = [
    'Open',
    'In Production',
    'Completed',
    'Partially Dispatched',
    'Dispatched',
    'Closed'
];

$po_status_chart_values = [
    $open_po,
    $in_production_po,
    $completed_po,
    $partially_dispatched_po,
    $dispatched_po,
    $closed_po
];

$po_status_chart_labels_json =
    json_encode(
        $po_status_chart_labels
    );

$po_status_chart_values_json =
    json_encode(
        $po_status_chart_values
    );
?>

<style>

.dashboard-head,
.kpi-card,
.dashboard-card {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 6px 24px rgba(31,45,61,.06);
}

/* Compact only the top Production Dashboard card */
.dashboard-head .card-body {
    padding: 10px 14px !important;
}

.dashboard-head .dashboard-title {
    font-size: 18px;
    line-height: 1.15;
    margin-bottom: 2px !important;
}

.dashboard-head .dashboard-subtitle {
    font-size: 10px;
    line-height: 1.2;
}

.dashboard-head .btn {
    padding: 5px 10px;
    font-size: 11px;
    line-height: 1.2;
}

.dashboard-title {
    font-weight: 700;
    color: #26374a;
}

.dashboard-subtitle {
    color: #7a8796;
    font-size: 12px;
}

.kpi-card {
    height: 100%;
}

.kpi-card .card-body {
    padding: 16px 18px;
}

.kpi-card-link {
    display: block;
    height: 100%;
    color: inherit;
    text-decoration: none;
    border-radius: 14px;
}

.kpi-card-link:hover,
.kpi-card-link:focus {
    color: inherit;
    text-decoration: none;
}

.kpi-card-link .kpi-card {
    transition: transform .18s ease, box-shadow .18s ease;
    cursor: pointer;
}

.kpi-card-link:hover .kpi-card,
.kpi-card-link:focus .kpi-card {
    transform: translateY(-2px);
    box-shadow: 0 10px 28px rgba(31,45,61,.12);
}

.kpi-card-link:focus {
    outline: 2px solid rgba(47,128,237,.35);
    outline-offset: 2px;
}

.kpi-label {
    font-size: 11px;
    color: #7b8794;
    text-transform: uppercase;
    letter-spacing: .35px;
    margin-bottom: 6px;
}

.kpi-value {
    font-size: 24px;
    font-weight: 700;
    color: #26374a;
    line-height: 1.1;
}

.kpi-note {
    font-size: 10px;
    color: #8a95a3;
    margin-top: 6px;
}

.kpi-icon {
    width: 46px;
    height: 46px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 23px;
    flex-shrink: 0;
}

.kpi-icon i {
    line-height: 1;
    display: block;
    font-size: 24px;
}

.kpi-icon.blue {
    background: #eef5ff;
    color: #2f80ed;
}

.kpi-icon.orange {
    background: #fff6e5;
    color: #f59f00;
}

.kpi-icon.green {
    background: #eaf8f0;
    color: #20a464;
}

.kpi-icon.cyan {
    background: #e9f8fb;
    color: #24a6bd;
}

.kpi-icon.dark {
    background: #eef1f4;
    color: #425466;
}

.kpi-icon.purple {
    background: #f2edff;
    color: #7b61d1;
}

.kpi-icon.teal {
    background: #e8f8f6;
    color: #1f9d8a;
}

.kpi-icon.gold {
    background: #fff7df;
    color: #d99a00;
}

.chart-wrap {
    min-height: 330px;
}

#po-status-chart .apexcharts-pie-series path,
#po-status-chart .apexcharts-legend-series {
    cursor: pointer;
}

.dispatch-chart-wrap {
    min-height: 340px;
}

.chart-summary-badge {
    display: inline-flex;
    align-items: center;
    padding: 5px 9px;
    border-radius: 999px;
    background: #f4f7fa;
    color: #506176;
    font-size: 10px;
    font-weight: 700;
}

.chart-legend-note {
    font-size: 11px;
    color: #7b8794;
}

.status-summary-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap: 8px;
}

.status-summary-item {
    border: 1px solid #edf0f4;
    border-radius: 10px;
    padding: 10px;
    background: #fafbfd;
}

.status-summary-label {
    font-size: 10px;
    color: #7b8794;
    text-transform: uppercase;
}

.status-summary-value {
    font-size: 17px;
    font-weight: 700;
    color: #26374a;
}

.dashboard-card-title {
    font-size: 15px;
    font-weight: 700;
    color: #26374a;
}

.dashboard-card-subtitle {
    font-size: 11px;
    color: #8a95a3;
}

.alert-counter {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 27px;
    height: 27px;
    padding: 0 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
}

.quality-box {
    background: #f8f9fa;
    border: 1px solid #edf0f4;
    border-radius: 10px;
    padding: 12px;
    height: 100%;
}

.quality-label {
    font-size: 10px;
    color: #7b8794;
    text-transform: uppercase;
    letter-spacing: .3px;
}

.quality-value {
    margin-top: 4px;
    font-size: 20px;
    font-weight: 700;
}

.dashboard-table {
    font-size: 11px;
    margin-bottom: 0;
}

.dashboard-table th {
    white-space: nowrap;
    background: #f7f9fb;
    color: #5b6777;
    text-transform: uppercase;
    letter-spacing: .2px;
    font-size: 10px;
    vertical-align: middle;
}

.dashboard-table td {
    vertical-align: middle;
    border-color: #edf0f4;
}

.product-main {
    font-weight: 700;
    color: #2d3d50;
}

.product-sub {
    font-size: 10px;
    color: #7b8794;
}

.days-badge {
    font-size: 9px;
    font-weight: 700;
    border-radius: 999px;
    padding: 4px 7px;
    white-space: nowrap;
}

/* Due / Upcoming Delivery priority row highlights */
.delivery-row-overdue td {
    background: #fff5f5 !important;
}

.delivery-row-today td {
    background: #fff9e8 !important;
}

.delivery-row-tomorrow td {
    background: #fffdf3 !important;
}

.delivery-row-upcoming td {
    background: #f5fbff !important;
}

.delivery-priority {
    font-weight: 700;
}

/* Vendor Expected Receive priority */
.vendor-row-missing td { background: #f6f7f9 !important; }
.vendor-row-overdue td { background: #fff5f5 !important; }
.vendor-row-today td,
.vendor-row-soon td { background: #fff9e8 !important; }
.vendor-row-schedule td { background: #f3fbf7 !important; }
.vendor-priority { font-weight: 700; }

/* Rejection / Replacement Tracking */
.rejection-row-pending td {
    background: #fff5f5 !important;
}

.rejection-row-process td {
    background: #fff9e8 !important;
}

.rejection-row-received td {
    background: #f5fbff !important;
}

.rejection-row-closed td {
    background: #f3fbf7 !important;
}

.rejection-reason {
    max-width: 240px;
    white-space: normal;
    line-height: 1.35;
}

.rejection-stage {
    font-weight: 700;
    color: #26374a;
    line-height: 1.25;
}

.rejection-vendor {
    font-size: 10px;
    color: #7b8794;
    margin-top: 2px;
}

.rejection-qty-pending {
    font-weight: 700;
    color: #dc3545;
}

.product-thumb-wrap {
    width: 46px;
    height: 46px;
    border-radius: 9px;
    border: 1px solid #e6ebf0;
    background: #f8f9fa;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.product-thumb {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.product-thumb-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #9aa5b1;
    font-size: 20px;
    background: #f5f7f9;
}

.quick-link {
    font-size: 11px;
    font-weight: 600;
    text-decoration: none;
}

.process-current {
    font-weight: 700;
    color: #26374a;
    line-height: 1.25;
}

.process-next {
    font-weight: 700;
    color: #0d6efd;
    line-height: 1.25;
}

.process-vendor {
    font-size: 10px;
    color: #7b8794;
    margin-top: 2px;
}

.process-empty {
    font-size: 10px;
    color: #98a3af;
}

</style>


<div class="content-page">
<div class="content">
<div class="container-fluid">

<!-- PAGE HEADER -->

<div class="row mt-2 mb-2">
<div class="col-12">

<div class="card dashboard-head">
<div class="card-body">

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

    <div>

        <h4 class="dashboard-title mb-1">
            Production Dashboard
        </h4>

        <div class="dashboard-subtitle">
            Client PO, production, delivery, vendor follow-up, quality and dispatch overview
        </div>

    </div>

    <div class="d-flex gap-2 flex-wrap">

        <a
            href="/erp/client_po/index.php"
            class="btn btn-primary btn-sm"
        >
            <i class="ri-file-list-2-line me-1"></i>
            Orders
        </a>

        <a
            href="/erp/reports/production.php"
            class="btn btn-outline-primary btn-sm"
        >
            <i class="ri-bar-chart-box-line me-1"></i>
            Production Report
        </a>

    </div>

</div>

</div>
</div>

</div>
</div>


<!-- QUICK WORK: Daily Diary / Quick Note / Reminder -->
<style>
.qw-section{margin:0 0 22px}
.qw-card{background:#fff;border:1px solid #e7eaf0;border-radius:14px;box-shadow:0 5px 18px rgba(15,23,42,.05);overflow:hidden}
.qw-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px 18px;border-bottom:1px solid #edf0f4;flex-wrap:wrap}
.qw-title{font-size:18px;font-weight:700;color:#172033;margin:0}
.qw-subtitle{font-size:12px;color:#7b8494;margin-top:3px}
.qw-actions{display:flex;gap:8px;flex-wrap:wrap}
.qw-btn{border:0;border-radius:8px;padding:9px 13px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.qw-btn-diary{background:#eef4ff;color:#2456c7}.qw-btn-note{background:#fff7df;color:#946200}.qw-btn-rem{background:#f5efff;color:#6c3bb7}
.qw-body{padding:14px 18px}
.qw-panels{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.qw-panel{border:1px solid #edf0f4;border-radius:11px;min-width:0;background:#fbfcfe}
.qw-panel-head{padding:11px 12px;border-bottom:1px solid #edf0f4;font-weight:700;font-size:13px;display:flex;justify-content:space-between;align-items:center}
.qw-count{min-width:22px;height:22px;padding:0 6px;border-radius:20px;background:#eef2f7;display:inline-flex;align-items:center;justify-content:center;font-size:11px}
.qw-list{padding:8px 10px;min-height:78px}
.qw-item{padding:8px 4px;border-bottom:1px dashed #e5e8ed}.qw-item:last-child{border-bottom:0}
.qw-item-title{font-size:13px;font-weight:600;color:#263044;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.qw-item-meta{font-size:11px;color:#7c8595;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.qw-item-actions{margin-top:5px;display:flex;gap:5px;flex-wrap:wrap}
.qw-mini{border:1px solid #dfe4eb;background:#fff;border-radius:5px;padding:3px 7px;font-size:10px;cursor:pointer}
.qw-empty{font-size:12px;color:#98a1af;text-align:center;padding:15px 5px}
.qw-modal{position:fixed;inset:0;background:rgba(15,23,42,.48);display:none;align-items:center;justify-content:center;padding:18px;z-index:9999}
.qw-modal.show{display:flex}
.qw-modal-box{width:min(560px,100%);background:#fff;border-radius:14px;box-shadow:0 20px 50px rgba(0,0,0,.2);overflow:hidden}
.qw-modal-head{display:flex;justify-content:space-between;align-items:center;padding:15px 18px;border-bottom:1px solid #edf0f4}
.qw-modal-head h3{margin:0;font-size:17px}.qw-close{border:0;background:transparent;font-size:23px;line-height:1;cursor:pointer;color:#7c8492}
.qw-form{padding:18px}.qw-field{margin-bottom:13px}.qw-field label{display:block;font-size:12px;font-weight:700;margin-bottom:6px;color:#394254}.qw-field input,.qw-field textarea,.qw-field select{width:100%;box-sizing:border-box;border:1px solid #d8dee8;border-radius:7px;padding:9px 10px;font-size:13px;outline:none}.qw-field textarea{min-height:90px;resize:vertical}.qw-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.qw-form-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}.qw-save{background:#2563eb;color:#fff}.qw-cancel{background:#eef1f5;color:#273142}.qw-message{font-size:12px;margin-top:8px;min-height:17px}.qw-success{color:#17803d}.qw-error{color:#c0392b}
@media(max-width:900px){.qw-panels{grid-template-columns:1fr}.qw-row{grid-template-columns:1fr}}
</style>

<div class="row qw-section" id="quick-work-widget">
    <div class="col-12">
        <div class="qw-card">
            <div class="qw-head">
                <div>
                    <div class="qw-title">⚡ Quick Work</div>
                    <div class="qw-subtitle">Daily work, quick notes and reminders — directly from Dashboard</div>
                </div>
                <div class="qw-actions">
                    <button type="button" class="qw-btn qw-btn-diary" onclick="qwOpen('diary')">📔 Daily Diary</button>
                    <button type="button" class="qw-btn qw-btn-note" onclick="qwOpen('note')">📝 Quick Note</button>
                    <button type="button" class="qw-btn qw-btn-rem" onclick="qwOpen('reminder')">🔔 Reminder</button>
                </div>
            </div>
            <div class="qw-body">
                <div class="qw-panels">
                    <div class="qw-panel">
                        <div class="qw-panel-head"><span>📔 Today's Diary</span><span class="qw-count" id="qwDiaryCount">0</span></div>
                        <div class="qw-list" id="qwDiaryList"><div class="qw-empty">Loading...</div></div>
                    </div>
                    <div class="qw-panel">
                        <div class="qw-panel-head"><span>📝 Today's Notes</span><span class="qw-count" id="qwNoteCount">0</span></div>
                        <div class="qw-list" id="qwNoteList"><div class="qw-empty">Loading...</div></div>
                    </div>
                    <div class="qw-panel">
                        <div class="qw-panel-head"><span>🔔 Today's Reminders</span><span class="qw-count" id="qwReminderCount">0</span></div>
                        <div class="qw-list" id="qwReminderList"><div class="qw-empty">Loading...</div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="qw-modal" id="qwModal" aria-hidden="true">
    <div class="qw-modal-box" role="dialog" aria-modal="true">
        <div class="qw-modal-head">
            <h3 id="qwModalTitle">Quick Work</h3>
            <button type="button" class="qw-close" onclick="qwClose()" aria-label="Close">&times;</button>
        </div>
        <form class="qw-form" id="qwForm" onsubmit="return qwSubmit(event)">
            <input type="hidden" id="qwAction" name="action">
            <input type="hidden" id="qwId" name="id" value="0">
            <div id="qwFormFields"></div>
            <div class="qw-message" id="qwMessage"></div>
            <div class="qw-form-actions">
                <button type="button" class="qw-btn qw-cancel" onclick="qwClose()">Cancel</button>
                <button type="submit" class="qw-btn qw-save" id="qwSaveBtn">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
(function(){
    const api = 'quick_work_api.php';
    const modal = document.getElementById('qwModal');
    const formFields = document.getElementById('qwFormFields');
    const form = document.getElementById('qwForm');
    const actionEl = document.getElementById('qwAction');
    const idEl = document.getElementById('qwId');
    const titleEl = document.getElementById('qwModalTitle');
    const messageEl = document.getElementById('qwMessage');
    const saveBtn = document.getElementById('qwSaveBtn');

    function esc(v){
        return String(v ?? '').replace(/[&<>'"]/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c];});
    }
    function localDate(){
        const d=new Date();
        return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
    }
    function localDateTime(){
        const d=new Date();
        return localDate()+'T'+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');
    }
    function setMessage(msg, error){messageEl.textContent=msg||'';messageEl.className='qw-message '+(error?'qw-error':'qw-success');}

    window.qwOpen = function(type, id){
        id = id || 0;
        idEl.value=id;
        setMessage('',false);
        if(type==='diary'){
            titleEl.textContent=id?'✏️ Edit Daily Diary':'📔 Daily Diary';
            actionEl.value='save_diary';
            formFields.innerHTML=`
                <div class="qw-row">
                    <div class="qw-field"><label>Diary Date</label><input type="date" name="diary_date" id="qwDiaryDate" value="${localDate()}" required></div>
                    <div class="qw-field"><label>Work / Task</label><input type="text" name="work_task" id="qwWorkTask" maxlength="255" required></div>
                </div>
                <div class="qw-field"><label>Details / Notes</label><textarea name="details" id="qwDetails"></textarea></div>
                <div class="qw-row">
                    <div class="qw-field"><label>Priority</label><select name="priority" id="qwPriority"><option>Low</option><option selected>Medium</option><option>High</option></select></div>
                    <div class="qw-field"><label>Status</label><select name="status" id="qwStatus"><option selected>Pending</option><option>In Progress</option><option>Completed</option></select></div>
                </div>`;
        } else if(type==='note'){
            titleEl.textContent=id?'✏️ Edit Quick Note':'📝 Quick Note';
            actionEl.value='save_note';
            formFields.innerHTML=`<div class="qw-field"><label>Note</label><textarea name="note_text" id="qwNoteText" required placeholder="Write a quick note..."></textarea></div><div class="qw-field"><label>Date & Time</label><input type="datetime-local" name="note_datetime" id="qwNoteDatetime" value="${localDateTime()}"></div>`;
        } else {
            titleEl.textContent=id?'✏️ Edit Reminder':'🔔 Reminder';
            actionEl.value='save_reminder';
            formFields.innerHTML=`
                <div class="qw-field"><label>Reminder Title</label><input type="text" name="reminder_title" id="qwReminderTitle" maxlength="255" required></div>
                <div class="qw-row">
                    <div class="qw-field"><label>Date</label><input type="date" name="reminder_date" id="qwReminderDate" value="${localDate()}" required></div>
                    <div class="qw-field"><label>Time</label><input type="time" name="reminder_time" id="qwReminderTime" value="09:00" required></div>
                </div>
                <div class="qw-field"><label>Details</label><textarea name="details" id="qwReminderDetails"></textarea></div>
                <div class="qw-field"><label>Status</label><select name="status" id="qwReminderStatus"><option selected>Pending</option><option>Completed</option></select></div>`;
        }
        modal.classList.add('show');modal.setAttribute('aria-hidden','false');
        if(type==='diary') document.getElementById('qwWorkTask').focus();
        if(type==='note') document.getElementById('qwNoteText').focus();
        if(type==='reminder') document.getElementById('qwReminderTitle').focus();
        if(id){ qwLoad(type,id); }
    };

    async function qwLoad(type,id){
        const action=type==='diary'?'get_diary':type==='note'?'get_note':'get_reminder';
        const fd=new FormData();fd.append('action',action);fd.append('id',id);
        try{
            const r=await fetch(api,{method:'POST',body:fd});const j=await r.json();
            if(!j.ok){setMessage(j.message||'Unable to load.',true);return;}
            const d=j.data||{};
            if(type==='diary'){
                document.getElementById('qwDiaryDate').value=d.diary_date||localDate();document.getElementById('qwWorkTask').value=d.work_task||'';document.getElementById('qwDetails').value=d.details||'';document.getElementById('qwPriority').value=d.priority||'Medium';document.getElementById('qwStatus').value=d.status||'Pending';
            } else if(type==='note'){
                document.getElementById('qwNoteText').value=d.note_text||'';document.getElementById('qwNoteDatetime').value=(d.note_datetime||localDateTime()).replace(' ','T').slice(0,16);
            } else {
                document.getElementById('qwReminderTitle').value=d.reminder_title||'';document.getElementById('qwReminderDate').value=d.reminder_date||localDate();document.getElementById('qwReminderTime').value=(d.reminder_time||'09:00').slice(0,5);document.getElementById('qwReminderDetails').value=d.details||'';document.getElementById('qwReminderStatus').value=d.status||'Pending';
            }
        }catch(e){setMessage('Unable to load entry.',true);}
    }

    window.qwClose=function(){modal.classList.remove('show');modal.setAttribute('aria-hidden','true');};
    modal.addEventListener('click',function(e){if(e.target===modal)qwClose();});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')qwClose();});

    window.qwSubmit=async function(e){
        e.preventDefault();saveBtn.disabled=true;setMessage('Saving...',false);
        try{
            const r=await fetch(api,{method:'POST',body:new FormData(form)});const j=await r.json();
            if(!j.ok){setMessage(j.message||'Save failed.',true);return false;}
            setMessage(j.message||'Saved.',false);await qwRefresh();setTimeout(qwClose,350);
        }catch(err){setMessage('Server error. Please try again.',true);}
        finally{saveBtn.disabled=false;}
        return false;
    };

    window.qwDelete=async function(type,id){
        if(!confirm('Delete this entry?'))return;
        const fd=new FormData();fd.append('action',type==='diary'?'delete_diary':type==='note'?'delete_note':'delete_reminder');fd.append('id',id);
        try{const r=await fetch(api,{method:'POST',body:fd});const j=await r.json();if(!j.ok){alert(j.message||'Delete failed.');return;}await qwRefresh();}catch(e){alert('Server error.');}
    };
    window.qwCompleteReminder=async function(id){
        const fd=new FormData();fd.append('action','complete_reminder');fd.append('id',id);
        try{const r=await fetch(api,{method:'POST',body:fd});const j=await r.json();if(!j.ok){alert(j.message||'Update failed.');return;}await qwRefresh();}catch(e){alert('Server error.');}
    };

    window.qwRefresh=async function(){
        try{
            const r=await fetch(api+'?action=list',{cache:'no-store'});const j=await r.json();
            if(!j.ok)throw new Error('load');
            const d=j.data||{};
            document.getElementById('qwDiaryCount').textContent=(d.diary||[]).length;
            document.getElementById('qwNoteCount').textContent=(d.notes||[]).length;
            document.getElementById('qwReminderCount').textContent=(d.reminders||[]).length;
            document.getElementById('qwDiaryList').innerHTML=(d.diary||[]).length?d.diary.map(x=>`<div class="qw-item"><div class="qw-item-title">${esc(x.work_task)}</div><div class="qw-item-meta">${esc(x.priority)} · ${esc(x.status)}${x.details?' · '+esc(x.details):''}</div><div class="qw-item-actions"><button class="qw-mini" onclick="qwOpen('diary',${x.id})">Edit</button><button class="qw-mini" onclick="qwDelete('diary',${x.id})">Delete</button></div></div>`).join(''):'<div class="qw-empty">No diary entry today.</div>';
            document.getElementById('qwNoteList').innerHTML=(d.notes||[]).length?d.notes.map(x=>`<div class="qw-item"><div class="qw-item-title">${esc(x.note_text)}</div><div class="qw-item-meta">${esc(x.note_datetime)}</div><div class="qw-item-actions"><button class="qw-mini" onclick="qwOpen('note',${x.id})">Edit</button><button class="qw-mini" onclick="qwDelete('note',${x.id})">Delete</button></div></div>`).join(''):'<div class="qw-empty">No quick note today.</div>';
            document.getElementById('qwReminderList').innerHTML=(d.reminders||[]).length?d.reminders.map(x=>`<div class="qw-item"><div class="qw-item-title">${esc(x.reminder_title)}</div><div class="qw-item-meta">${esc(x.reminder_time)} · ${esc(x.status)}${x.details?' · '+esc(x.details):''}</div><div class="qw-item-actions">${x.status!=='Completed'?`<button class="qw-mini" onclick="qwCompleteReminder(${x.id})">Complete</button>`:''}<button class="qw-mini" onclick="qwOpen('reminder',${x.id})">Edit</button><button class="qw-mini" onclick="qwDelete('reminder',${x.id})">Delete</button></div></div>`).join(''):'<div class="qw-empty">No reminder today.</div>';
        }catch(e){
            document.getElementById('qwDiaryList').innerHTML='<div class="qw-empty">Unable to load.</div>';
            document.getElementById('qwNoteList').innerHTML='<div class="qw-empty">Unable to load.</div>';
            document.getElementById('qwReminderList').innerHTML='<div class="qw-empty">Unable to load.</div>';
        }
    };

    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',qwRefresh);else qwRefresh();
})();
</script>



<!-- DELIVERY + VENDOR ALERTS -->

<div class="row g-3 mb-3">


<div class="col-xl-6">

<div class="card dashboard-card h-100">
<div class="card-body">

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">

    <div>
        <div class="dashboard-card-title">Due / Upcoming Delivery</div>
        <div class="dashboard-card-subtitle">
            Overdue, due today and next 7 day delivery alerts
        </div>
    </div>

    <div class="d-flex gap-1">
        <span class="alert-counter bg-danger text-white" title="Overdue">
            <?= $delivery_overdue_count; ?>
        </span>
        <span class="alert-counter bg-warning text-dark" title="Due Today">
            <?= $delivery_due_today_count; ?>
        </span>
        <span class="alert-counter bg-info text-dark" title="Upcoming">
            <?= $delivery_upcoming_count; ?>
        </span>
    </div>

</div>

<div class="table-responsive">

<table class="table table-bordered table-hover dashboard-table">

<thead>
<tr>
    <th style="width:62px;">Photo</th>
    <th>PO</th>
    <th>Client / Product</th>
    <th>Delivery</th>
    <th>Alert</th>
    <th>Status</th>
</tr>
</thead>

<tbody>

<?php
$delivery_rows = 0;

while ($delivery = mysqli_fetch_assoc($delivery_alert_result)) {

    $delivery_rows++;

    $days = intval(
        (
            strtotime($delivery['delivery_date']) -
            strtotime(date('Y-m-d'))
        ) / 86400
    );

    if ($days < 0) {

        $alert_text =
            abs($days) .
            ' day(s) overdue';

        $alert_class =
            'bg-danger text-white';

        $delivery_row_class =
            'delivery-row-overdue';

    } elseif ($days === 0) {

        $alert_text =
            'Due Today';

        $alert_class =
            'bg-danger text-white';

        $delivery_row_class =
            'delivery-row-today';

    } elseif ($days === 1) {

        $alert_text =
            'Due Tomorrow';

        $alert_class =
            'bg-warning text-dark';

        $delivery_row_class =
            'delivery-row-tomorrow';

    } else {

        $alert_text =
            'In ' .
            $days .
            ' day(s)';

        $alert_class =
            'bg-info text-dark';

        $delivery_row_class =
            'delivery-row-upcoming';
    }
?>

<tr class="<?= htmlspecialchars($delivery_row_class); ?>">

<td>

<?php

$product_image =
    trim(
        $delivery[
            'product_image'
        ] ?? ''
    );

?>

<?php if ($product_image !== '') { ?>

    <div class="product-thumb-wrap">

        <img
            src="/erp/uploads/products/<?= rawurlencode(
                $product_image
            ); ?>"
            alt="<?= htmlspecialchars(
                $delivery[
                    'product_name'
                ] ?? 'Product'
            ); ?>"
            class="product-thumb"
            loading="lazy"
            onerror="
                this.style.display='none';
                this.nextElementSibling.style.display='flex';
            "
        >

        <div
            class="product-thumb-placeholder"
            style="display:none;"
            title="Product image not found"
        >
            <i class="ri-image-line"></i>
        </div>

    </div>

<?php } else { ?>

    <div
        class="product-thumb-wrap"
        title="No product image"
    >

        <div class="product-thumb-placeholder">
            <i class="ri-image-line"></i>
        </div>

    </div>

<?php } ?>

</td>


<td>
    <a
        href="/erp/client_po/view.php?id=<?= intval($delivery['client_po_id']); ?>"
        class="fw-bold"
    >
        <?= htmlspecialchars($delivery['po_number']); ?>
    </a>
</td>

<td>
    <div class="product-main">
        <?= htmlspecialchars($delivery['client_name']); ?>
    </div>
    <div class="product-sub">
        <?= htmlspecialchars($delivery['product_name']); ?>
        <?php if (!empty($delivery['part_number'])) { ?>
            · <?= htmlspecialchars($delivery['part_number']); ?>
        <?php } ?>
    </div>
</td>

<td><?= dashDate($delivery['delivery_date']); ?></td>

<td>
    <span class="days-badge delivery-priority <?= $alert_class; ?>">
        <?= htmlspecialchars($alert_text); ?>
    </span>
</td>

<td><?= dashPoBadge($delivery['po_status']); ?></td>

</tr>

<?php } ?>

<?php if ($delivery_rows === 0) { ?>
<tr>
<td colspan="6" class="text-center text-muted py-4">
    No due / upcoming deliveries.
</td>
</tr>
<?php } ?>

</tbody>
</table>

</div>

<div class="mt-3">
    <a href="/erp/client_po/index.php" class="quick-link">
        View all Orders →
    </a>
</div>

</div>
</div>

</div>


<div class="col-xl-6">

<div class="card dashboard-card h-100">
<div class="card-body">

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">

    <div>
        <div class="dashboard-card-title">
            Operation Expected Receive Alerts
        </div>
        <div class="dashboard-card-subtitle">
            Current active operation only, with expected-date follow-up alerts
        </div>
    </div>

    <div class="d-flex gap-1 flex-wrap">
        <span class="alert-counter bg-secondary text-white" title="Total Active Operation Stages">
            <?= $vendor_pending_count; ?>
        </span>
        <span class="alert-counter bg-dark text-white" title="Expected Date Not Set">
            <?= $vendor_date_not_set_count; ?>
        </span>
        <span class="alert-counter bg-danger text-white" title="Overdue">
            <?= $vendor_overdue_count; ?>
        </span>
        <span class="alert-counter bg-warning text-dark" title="Due Today">
            <?= $vendor_due_today_count; ?>
        </span>
        <span class="alert-counter bg-info text-dark" title="Within 2 Days">
            <?= $vendor_upcoming_count; ?>
        </span>
    </div>

</div>

<div class="table-responsive">

<table class="table table-bordered table-hover dashboard-table">

<thead>
<tr>
    <th style="width:62px;">Photo</th>
    <th>PO</th>
    <th>Operation / Vendor</th>
    <th>Product</th>
    <th>Expected</th>
    <th>Pending Qty</th>
    <th>Alert</th>
</tr>
</thead>

<tbody>

<?php
$vendor_rows = 0;

while ($vendor = mysqli_fetch_assoc($vendor_alert_result)) {

    $vendor_rows++;

    $expected_receive_date =
        trim(
            $vendor[
                'expected_receive_date'
            ] ?? ''
        );


    if (
        $expected_receive_date === ''
        ||
        $expected_receive_date === '0000-00-00'
    ) {

        $alert_text =
            'Expected Date Not Set';

        $alert_class =
            'bg-dark text-white';

        $vendor_row_class =
            'vendor-row-missing';

        $days = null;

    } else {

        $days = intval(
            (
                strtotime(
                    $expected_receive_date
                ) -
                strtotime(
                    date('Y-m-d')
                )
            ) / 86400
        );


        if ($days < 0) {

            $alert_text =
                abs($days) .
                ' day(s) overdue';

            $alert_class =
                'bg-danger text-white';

            $vendor_row_class =
                'vendor-row-overdue';

        } elseif ($days === 0) {

            $alert_text =
                'Due Today';

            $alert_class =
                'bg-danger text-white';

            $vendor_row_class =
                'vendor-row-today';

        } elseif ($days === 1) {

            $alert_text =
                'Due Tomorrow';

            $alert_class =
                'bg-warning text-dark';

            $vendor_row_class =
                'vendor-row-soon';

        } elseif ($days === 2) {

            $alert_text =
                'Follow-up Soon';

            $alert_class =
                'bg-warning text-dark';

            $vendor_row_class =
                'vendor-row-soon';

        } else {

            $alert_text =
                'On Schedule';

            $alert_class =
                'bg-success text-white';

            $vendor_row_class =
                'vendor-row-schedule';
        }
    }
?>

<tr class="<?= htmlspecialchars($vendor_row_class); ?>">

<td>

<?php
$vendor_product_image =
    trim(
        $vendor['product_image'] ?? ''
    );
?>

<?php if ($vendor_product_image !== '') { ?>

    <div class="product-thumb-wrap">
        <img
            src="/erp/uploads/products/<?= rawurlencode($vendor_product_image); ?>"
            alt="<?= htmlspecialchars($vendor['product_name'] ?? 'Product'); ?>"
            class="product-thumb"
            loading="lazy"
            onerror="
                this.style.display='none';
                this.nextElementSibling.style.display='flex';
            "
        >

        <div
            class="product-thumb-placeholder"
            style="display:none;"
            title="Product image not found"
        >
            <i class="ri-image-line"></i>
        </div>
    </div>

<?php } else { ?>

    <div
        class="product-thumb-wrap"
        title="No product image"
    >
        <div class="product-thumb-placeholder">
            <i class="ri-image-line"></i>
        </div>
    </div>

<?php } ?>

</td>

<td>
    <a
        href="/erp/client_po/progress_edit.php?progress_id=<?= intval($vendor['progress_id']); ?>"
        class="fw-bold"
    >
        <?= htmlspecialchars($vendor['po_number']); ?>
    </a>
</td>

<td>
    <div class="product-main">
        <?= !empty($vendor['vendor_name'])
            ? htmlspecialchars($vendor['vendor_name'])
            : 'In-house / Not Assigned';
        ?>
    </div>
    <div class="product-sub">
        Step <?= intval($vendor['sequence_no']); ?>
        · <?= htmlspecialchars($vendor['operation_name']); ?>
    </div>
</td>

<td>
    <div class="product-main">
        <?= htmlspecialchars($vendor['product_name']); ?>
    </div>
    <?php if (!empty($vendor['part_number'])) { ?>
    <div class="product-sub">
        <?= htmlspecialchars($vendor['part_number']); ?>
    </div>
    <?php } ?>
</td>

<td>

<?php

if (
    empty(
        $vendor[
            'expected_receive_date'
        ]
    )
    ||
    $vendor[
        'expected_receive_date'
    ] === '0000-00-00'
) {

    echo
        '<span class="text-danger fw-bold">' .
        'Not Set' .
        '</span>';

} else {

    echo dashDate(
        $vendor[
            'expected_receive_date'
        ]
    );
}

?>

</td>

<td>
<?php
$pending_qty = floatval($vendor['pending_qty'] ?? 0);
$pending_unit = strtoupper(trim($vendor['unit'] ?? 'PCS'));
if ($pending_unit === '') {
    $pending_unit = 'PCS';
}
?>
    <span class="badge bg-warning text-dark">
        <?= rtrim(rtrim(number_format($pending_qty, 2, '.', ''), '0'), '.'); ?> <?= htmlspecialchars($pending_unit); ?>
    </span>
</td>

<td>
    <span class="days-badge vendor-priority <?= $alert_class; ?>">
        <?= htmlspecialchars($alert_text); ?>
    </span>
</td>

</tr>

<?php } ?>

<?php if ($vendor_rows === 0) { ?>
<tr>
<td colspan="7" class="text-center text-muted py-4">
    No vendor expected receive alerts.
</td>
</tr>
<?php } ?>

</tbody>
</table>

</div>

</div>
</div>

</div>

</div>




<!-- =====================================================
     CURRENT / NEXT PROCESS TRACKING
===================================================== -->

<div class="row mb-3">

<div class="col-12">

<div class="card dashboard-card">

<div class="card-body">

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">

    <div>

        <div class="dashboard-card-title">
            Current / Next Process Tracking
        </div>

        <div class="dashboard-card-subtitle">
            Live current operation, vendor and next planned route for active Client PO items
        </div>

    </div>

    <a
        href="/erp/client_po/index.php"
        class="quick-link"
    >
        View Orders →
    </a>

</div>


<div class="table-responsive">

<table class="table table-bordered table-hover dashboard-table">

<thead>

<tr>

    <th>
        PO
    </th>

    <th>
        Client / Product
    </th>

    <th>
        Current Operation
    </th>

    <th>
        Current Vendor
    </th>

    <th>
        Status
    </th>

    <th>
        Expected Receive
    </th>

    <th>
        Next Operation
    </th>

    <th>
        Next Vendor
    </th>

</tr>

</thead>

<tbody>

<?php

if (
    count(
        $process_tracking_rows
    ) > 0
) {

    foreach (
        $process_tracking_rows
        as $process_item
    ) {

        $current =
            $process_item[
                'current'
            ];

        $next =
            $process_item[
                'next'
            ];

        $all_completed =
            !empty(
                $process_item[
                    'all_completed'
                ]
            );


        $current_status =
            'Route Not Set';


        if (
            $current !== null
        ) {

            $current_status =
                trim(
                    $current[
                        'progress_status'
                    ]
                    ??
                    $current[
                        'route_status'
                    ]
                    ??
                    'Pending'
                );


            if (
                $all_completed
            ) {

                $current_status =
                    'Completed';
            }
        }

?>

<tr>

<td>

    <a
        href="/erp/client_po/progress.php?item_id=<?= intval(
            $process_item[
                'item_id'
            ]
        ); ?>"
        class="fw-bold"
    >
        <?= htmlspecialchars(
            $process_item[
                'po_number'
            ]
        ); ?>
    </a>

</td>


<td>

    <div class="product-main">

        <?= htmlspecialchars(
            $process_item[
                'client_name'
            ]
        ); ?>

    </div>

    <div class="product-sub">

        <?= htmlspecialchars(
            $process_item[
                'product_name'
            ]
        ); ?>

        <?php if (
            !empty(
                $process_item[
                    'part_number'
                ]
            )
        ) { ?>

            ·
            <?= htmlspecialchars(
                $process_item[
                    'part_number'
                ]
            ); ?>

        <?php } ?>

    </div>

</td>


<td>

<?php if (
    $current !== null
) { ?>

    <div class="process-current">

        Step
        <?= intval(
            $current[
                'sequence_no'
            ] ?? 0
        ); ?>

        -

        <?= htmlspecialchars(
            $current[
                'operation_name'
            ] ?? '-'
        ); ?>

    </div>

<?php } else { ?>

    <span class="process-empty">
        Route Not Set
    </span>

<?php } ?>

</td>


<td>

<?php if (
    $current !== null
) { ?>

    <?= !empty(
        $current[
            'vendor_name'
        ]
    )
        ? htmlspecialchars(
            $current[
                'vendor_name'
            ]
        )
        : 'In-house / Not Assigned';
    ?>

<?php } else { ?>

    <span class="process-empty">
        -
    </span>

<?php } ?>

</td>


<td>

<?php

if (
    $current === null
) {

    echo
        '<span class="badge bg-secondary">' .
        'Route Not Set' .
        '</span>';

} elseif (
    $current_status ===
    'Completed'
) {

    echo
        '<span class="badge bg-success">' .
        'Completed' .
        '</span>';

} elseif (
    $current_status ===
    'In Process'
) {

    echo
        '<span class="badge bg-primary">' .
        'In Process' .
        '</span>';

} elseif (
    $current_status ===
    'Sent'
) {

    echo
        '<span class="badge bg-warning text-dark">' .
        'Sent' .
        '</span>';

} elseif (
    $current_status ===
    'Hold'
) {

    echo
        '<span class="badge bg-danger">' .
        'Hold' .
        '</span>';

} else {

    echo
        '<span class="badge bg-secondary">' .
        htmlspecialchars(
            $current_status !== ''
                ? $current_status
                : 'Pending'
        ) .
        '</span>';
}

?>

</td>


<td>

<?php

if (
    $current !== null &&
    !empty(
        $current[
            'expected_receive_date'
        ]
    )
) {

    echo dashDate(
        $current[
            'expected_receive_date'
        ]
    );

} else {

    echo '-';
}

?>

</td>


<td>

<?php if (
    $all_completed
) { ?>

    <span class="badge bg-success">
        Ready for Dispatch
    </span>

<?php } elseif (
    $next !== null
) { ?>

    <div class="process-next">

        Step
        <?= intval(
            $next[
                'sequence_no'
            ] ?? 0
        ); ?>

        -

        <?= htmlspecialchars(
            $next[
                'operation_name'
            ] ?? '-'
        ); ?>

    </div>

<?php } elseif (
    $current !== null
) { ?>

    <span class="badge bg-success">
        Final Production Stage
    </span>

<?php } else { ?>

    <span class="process-empty">
        -
    </span>

<?php } ?>

</td>


<td>

<?php

if (
    $next !== null
) {

    echo !empty(
        $next[
            'vendor_name'
        ]
    )
        ? htmlspecialchars(
            $next[
                'vendor_name'
            ]
        )
        : 'In-house / Not Assigned';

} elseif (
    $all_completed
) {

    echo
        '<span class="text-success fw-bold">' .
        'Dispatch' .
        '</span>';

} else {

    echo '-';
}

?>

</td>

</tr>

<?php

    }

} else {

?>

<tr>

<td
    colspan="8"
    class="text-center text-muted py-4"
>
    No active Client PO process data found.
</td>

</tr>

<?php } ?>

</tbody>

</table>

</div>

</div>

</div>

</div>

</div>


<!-- TOP KPI -->

<div class="row g-2 mb-3">

<?php
$cards = [
    ['Total PO',$total_po,'Total client purchase orders','mdi mdi-file-document-multiple-outline','','blue','/erp/client_po/index.php'],
    ['Open PO',$open_po,'New / open orders','mdi mdi-folder-open-outline','text-primary','purple','/erp/client_po/index.php?status=Open'],
    ['In Production',$in_production_po,'Orders currently in production','mdi mdi-cog-outline','text-warning','orange','/erp/client_po/index.php?status=In%20Production'],
    ['Completed',$completed_po,'Production completed orders','mdi mdi-check-circle-outline','text-success','green','/erp/client_po/index.php?status=Completed'],
    ['Partially Dispatched',$partially_dispatched_po,'POs with dispatch balance pending','mdi mdi-truck-fast-outline','text-warning','orange','/erp/client_po/index.php?status=Partially%20Dispatched'],
    ['Dispatched',$dispatched_po,'Fully dispatched POs','mdi mdi-truck-delivery-outline','text-info','cyan','/erp/client_po/index.php?status=Dispatched'],
    ['Closed',$closed_po,'Fully closed client POs','mdi mdi-lock-outline','','dark','/erp/client_po/index.php?status=Closed'],
    ['Carry Forward Stock',dashQty($carry_forward_stock),'Available for future PO allocation','mdi mdi-archive-outline','text-success','teal','/erp/client_po/carry_forward_stock.php'],
    ['Fresh Production Qty',dashQty($fresh_production_qty),'Order qty less carry forward used','mdi mdi-tools','text-warning','gold','/erp/reports/production.php']
];

foreach ($cards as $card) {
?>

<div class="col-xl-4 col-lg-4 col-md-6">
<a href="<?= htmlspecialchars($card[6]); ?>" class="kpi-card-link" aria-label="<?= htmlspecialchars('Open ' . $card[0]); ?>">
<div class="card kpi-card"><div class="card-body">
<div class="d-flex justify-content-between align-items-start">
    <div>
        <div class="kpi-label"><?= htmlspecialchars($card[0]); ?></div>
        <div class="kpi-value <?= $card[4]; ?>"><?= $card[1]; ?></div>
        <div class="kpi-note"><?= htmlspecialchars($card[2]); ?></div>
    </div>
    <div class="kpi-icon <?= htmlspecialchars($card[5]); ?>"><i class="<?= htmlspecialchars($card[3]); ?>"></i></div>
</div>
</div></div>
</a>
</div>

<?php } ?>

</div>


<!-- =====================================================
     PO STATUS DONUT CHART
===================================================== -->

<div class="row g-3 mb-3">

<div class="col-xl-8">

<div class="card dashboard-card h-100">
<div class="card-body">

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">

    <div>

        <div class="dashboard-card-title">
            PO Status Overview
        </div>

        <div class="dashboard-card-subtitle">
            Current distribution of Client PO status
        </div>

    </div>

    <a
        href="/erp/client_po/index.php"
        class="quick-link"
    >
        View Orders →
    </a>

</div>

<div
    id="po-status-chart"
    class="chart-wrap"
></div>

</div>
</div>

</div>


<div class="col-xl-4">

<div class="card dashboard-card h-100">
<div class="card-body">

<div class="d-flex justify-content-between align-items-start gap-2 mb-3">

    <div>
        <div class="dashboard-card-title mb-1">
            Profitability Summary
        </div>

        <div class="dashboard-card-subtitle">
            All PO sales, cost and profit position
        </div>
    </div>

    <a
        href="/erp/reports/cost_profit.php"
        class="quick-link text-nowrap"
    >
        View Report →
    </a>

</div>

<div class="status-summary-list">

    <div class="status-summary-item">
        <div class="status-summary-label">
            Total Sales
        </div>
        <div class="status-summary-value text-primary">
            ₹<?= number_format(
                $dashboard_total_sales,
                2
            ); ?>
        </div>
    </div>

    <div class="status-summary-item">
        <div class="status-summary-label">
            Total Cost
        </div>
        <div class="status-summary-value text-danger">
            ₹<?= number_format(
                $dashboard_total_cost,
                2
            ); ?>
        </div>
    </div>

    <div class="status-summary-item">
        <div class="status-summary-label">
            Net Profit / Loss
        </div>
        <div class="status-summary-value <?= $dashboard_net_profit >= 0 ? 'text-success' : 'text-danger'; ?>">
            ₹<?= number_format(
                $dashboard_net_profit,
                2
            ); ?>
        </div>
    </div>

    <div class="status-summary-item">
        <div class="status-summary-label">
            Margin
        </div>
        <div class="status-summary-value <?= $dashboard_profit_margin >= 0 ? 'text-success' : 'text-danger'; ?>">
            <?= number_format(
                $dashboard_profit_margin,
                2
            ); ?>%
        </div>
    </div>

    <div class="status-summary-item">
        <div class="status-summary-label">
            Loss-making PO
        </div>
        <div class="status-summary-value <?= $dashboard_loss_po_count > 0 ? 'text-danger' : 'text-success'; ?>">
            <?= number_format(
                $dashboard_loss_po_count
            ); ?>
        </div>
    </div>

    <div class="status-summary-item">
        <div class="status-summary-label">
            Net Position
        </div>
        <div class="status-summary-value <?= $dashboard_net_profit >= 0 ? 'text-success' : 'text-danger'; ?>">
            <?= $dashboard_net_profit >= 0
                ? 'Profit'
                : 'Loss';
            ?>
        </div>
    </div>

</div>

<div class="chart-legend-note mt-3">
    Cost = Production + Packing + Additional Cost.
</div>

</div>
</div>

</div>

</div>


<!-- =====================================================
     DISPATCH TREND GRAPH
===================================================== -->

<div class="row mb-3">

<div class="col-12">

<div class="card dashboard-card">

<div class="card-body">

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">

    <div>

        <div class="dashboard-card-title">
            Dispatch Trend
        </div>

        <div class="dashboard-card-subtitle">
            Recent date-wise normal, extra replacement and total dispatched quantity
        </div>

    </div>

    <div class="d-flex gap-2 flex-wrap">

        <span class="chart-summary-badge">
            Last <?= count($dispatch_trend_labels); ?> dispatch date(s)
        </span>

        <a
            href="/erp/dispatch/index.php"
            class="quick-link"
        >
            Open Dispatch →
        </a>

    </div>

</div>


<div
    id="dispatch-trend-chart"
    class="dispatch-chart-wrap"
></div>

</div>

</div>

</div>

</div>


<!-- =====================================================
     REJECTION / REPLACEMENT TRACKING
===================================================== -->

<div class="row mb-3">
<div class="col-12">

<div class="card dashboard-card">
<div class="card-body">

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">

    <div>
        <div class="dashboard-card-title">
            Rejection / Replacement Tracking
        </div>
        <div class="dashboard-card-subtitle">
            Live rejection cases, replacement quantity and current replacement process
        </div>
    </div>

    <span class="chart-summary-badge">
        Active Cases: <?= number_format($active_rejection_cases); ?>
    </span>

</div>


<!-- REJECTION KPI -->

<div class="row g-2 mb-3">

<div class="col-xl-2 col-lg-4 col-md-6">
<div class="quality-box">
    <div class="quality-label">Total Reject Qty</div>
    <div class="quality-value text-danger">
        <?= dashQty($total_reject_qty); ?>
    </div>
</div>
</div>

<div class="col-xl-2 col-lg-4 col-md-6">
<div class="quality-box">
    <div class="quality-label">Replacement Required</div>
    <div class="quality-value">
        <?= dashQty($replacement_required_qty); ?>
    </div>
</div>
</div>

<div class="col-xl-2 col-lg-4 col-md-6">
<div class="quality-box">
    <div class="quality-label">Replacement Received</div>
    <div class="quality-value text-success">
        <?= dashQty($replacement_received_qty); ?>
    </div>
</div>
</div>

<div class="col-xl-2 col-lg-4 col-md-6">
<div class="quality-box">
    <div class="quality-label">Replacement Pending</div>
    <div class="quality-value text-warning">
        <?= dashQty($replacement_pending_qty); ?>
    </div>
</div>
</div>

<div class="col-xl-2 col-lg-4 col-md-6">
<div class="quality-box">
    <div class="quality-label">Active Cases</div>
    <div class="quality-value text-danger">
        <?= number_format($active_rejection_cases); ?>
    </div>
</div>
</div>

<div class="col-xl-2 col-lg-4 col-md-6">
<div class="quality-box">
    <div class="quality-label">Closed Cases</div>
    <div class="quality-value text-success">
        <?= number_format($closed_rejection_cases); ?>
    </div>
</div>
</div>

</div>


<!-- REJECTION TRACKING TABLE -->

<div class="table-responsive">

<table class="table table-bordered table-hover dashboard-table">

<thead>
<tr>
    <th>PO</th>
    <th>Client / Product</th>
    <th class="text-end">Reject Qty</th>
    <th class="text-end">Required</th>
    <th class="text-end">Received</th>
    <th class="text-end">Pending</th>
    <th>Reason</th>
    <th>Replacement Batch</th>
    <th>Current Stage / Vendor</th>
    <th>Movement</th>
    <th>Status</th>
    <th>Action</th>
</tr>
</thead>

<tbody>

<?php

$rejection_rows = 0;

while (
    $rejection =
    mysqli_fetch_assoc(
        $rejection_tracking_result
    )
) {

    $rejection_rows++;

    $required_qty =
        floatval(
            $rejection[
                'replacement_qty'
            ] ?? 0
        );

    $received_qty =
        floatval(
            $rejection[
                'replacement_received_qty'
            ] ?? 0
        );

    $pending_qty =
        $required_qty -
        $received_qty;

    if ($pending_qty < 0) {
        $pending_qty = 0;
    }

    $rejection_status =
        trim(
            $rejection[
                'rejection_status'
            ] ?? ''
        );

    $batch_status =
        trim(
            $rejection[
                'batch_status'
            ] ?? ''
        );

    $progress_status =
        trim(
            $rejection[
                'progress_status'
            ] ?? ''
        );


    if (
        $rejection_status ===
        'Closed'
    ) {

        $rejection_row_class =
            'rejection-row-closed';

    } elseif (
        $received_qty > 0
    ) {

        $rejection_row_class =
            'rejection-row-received';

    } elseif (
        in_array(
            $batch_status,
            [
                'In Process',
                'Sent'
            ],
            true
        )
        ||
        in_array(
            $progress_status,
            [
                'Sent',
                'Received',
                'In Process'
            ],
            true
        )
    ) {

        $rejection_row_class =
            'rejection-row-process';

    } else {

        $rejection_row_class =
            'rejection-row-pending';
    }


    // Dashboard status must follow the actual active replacement step.
    // A completed earlier step must not make the whole rejection look complete.
    if ($rejection_status === 'Closed') {

        $display_status = 'Closed';

    } elseif (
        $progress_status !== '' &&
        $progress_status !== 'Completed'
    ) {

        $display_status = $progress_status;

    } elseif ($rejection_status !== '') {

        $display_status = $rejection_status;

    } elseif ($batch_status !== '') {

        $display_status = $batch_status;

    } else {

        $display_status = 'Pending';
    }

?>

<tr class="<?= htmlspecialchars($rejection_row_class); ?>">

<td>

    <a
        href="/erp/client_po/view.php?id=<?= intval(
            $rejection[
                'client_po_id'
            ]
        ); ?>"
        class="fw-bold"
    >
        <?= htmlspecialchars(
            $rejection[
                'po_number'
            ] ?? '-'
        ); ?>
    </a>

    <div class="product-sub">
        Reject #<?= intval(
            $rejection[
                'rejection_id'
            ]
        ); ?>
    </div>

</td>


<td>

    <div class="product-main">
        <?= htmlspecialchars(
            $rejection[
                'client_name'
            ] ?? '-'
        ); ?>
    </div>

    <div class="product-sub">

        <?= htmlspecialchars(
            $rejection[
                'product_name'
            ] ?? '-'
        ); ?>

        <?php if (
            !empty(
                $rejection[
                    'part_number'
                ]
            )
        ) { ?>

            ·
            <?= htmlspecialchars(
                $rejection[
                    'part_number'
                ]
            ); ?>

        <?php } ?>

    </div>

</td>


<td class="text-end text-danger fw-bold">
    <?= dashQty(
        $rejection[
            'reject_qty'
        ] ?? 0
    ); ?>
</td>


<td class="text-end">
    <?= dashQty(
        $required_qty
    ); ?>
</td>


<td class="text-end text-success fw-bold">
    <?= dashQty(
        $received_qty
    ); ?>
</td>


<td class="text-end">
    <span class="rejection-qty-pending">
        <?= dashQty(
            $pending_qty
        ); ?>
    </span>
</td>


<td class="rejection-reason">
    <?= !empty(
        $rejection[
            'defect_reason'
        ]
    )
        ? htmlspecialchars(
            $rejection[
                'defect_reason'
            ]
        )
        : '-';
    ?>
</td>


<td>

<?php if (
    !empty(
        $rejection[
            'batch_no'
        ]
    )
) { ?>

    <div class="product-main">
        <?= htmlspecialchars(
            $rejection[
                'batch_no'
            ]
        ); ?>
    </div>

    <div class="product-sub">
        <?= $batch_status !== ''
            ? htmlspecialchars(
                $batch_status
            )
            : 'Pending';
        ?>
    </div>

<?php } else { ?>

    <span class="text-danger fw-bold">
        Batch Not Created
    </span>

<?php } ?>

</td>


<td>

<?php if (
    !empty(
        $rejection[
            'current_operation'
        ]
    )
) { ?>

    <div class="rejection-stage">

        Step
        <?= intval(
            $rejection[
                'current_sequence'
            ] ?? 0
        ); ?>

        -

        <?= htmlspecialchars(
            $rejection[
                'current_operation'
            ]
        ); ?>

    </div>

    <div class="rejection-vendor">

        <?= !empty(
            $rejection[
                'vendor_name'
            ]
        )
            ? htmlspecialchars(
                $rejection[
                    'vendor_name'
                ]
            )
            : 'In-house / Not Assigned';
        ?>

    </div>

<?php } else { ?>

    <span class="text-muted">
        Not Started
    </span>

<?php } ?>

</td>


<td>

    <?php if (
        !empty(
            $rejection[
                'send_date'
            ]
        )
    ) { ?>

        <div>
            <strong>Sent:</strong>
            <?= dashDate(
                $rejection[
                    'send_date'
                ]
            ); ?>
        </div>

    <?php } ?>

    <?php if (
        !empty(
            $rejection[
                'receive_date'
            ]
        )
    ) { ?>

        <div>
            <strong>Received:</strong>
            <?= dashDate(
                $rejection[
                    'receive_date'
                ]
            ); ?>
        </div>

    <?php } ?>

    <?php if (
        empty(
            $rejection[
                'send_date'
            ]
        )
        &&
        empty(
            $rejection[
                'receive_date'
            ]
        )
    ) { ?>

        <span class="text-muted">
            Not moved
        </span>

    <?php } ?>

</td>


<td>
    <?= dashRejectionBadge(
        $display_status
    ); ?>
</td>


<td>

    <a
        href="/erp/client_po/replacement_progress.php?item_id=<?= intval(
            $rejection[
                'client_po_item_id'
            ]
        ); ?>"
        class="btn btn-sm btn-primary"
    >
        Track
    </a>

</td>


</tr>

<?php } ?>


<?php if (
    $rejection_rows === 0
) { ?>

<tr>

<td
    colspan="12"
    class="text-center text-muted py-4"
>
    No rejection / replacement cases found.
</td>

</tr>

<?php } ?>

</tbody>

</table>

</div>


<div class="mt-3">

    <div class="chart-legend-note">
        Red = replacement pending,
        yellow = replacement in process,
        blue = replacement received,
        green = closed.
    </div>

</div>


</div>
</div>

</div>
</div>


<!-- RECENT DISPATCH -->

<div class="row mb-3">
<div class="col-12">

<div class="card dashboard-card">
<div class="card-body">

<div class="mb-3">
    <div class="dashboard-card-title">Recent Dispatches</div>
    <div class="dashboard-card-subtitle">
        Latest client PO dispatch activity
    </div>
</div>

<div class="table-responsive">

<table class="table table-bordered table-hover dashboard-table">

<thead>
<tr>
    <th>Dispatch No.</th>
    <th>Date</th>
    <th>Client / PO</th>
    <th>Product</th>
    <th class="text-end">Normal Qty</th>
    <th class="text-end">Extra Qty</th>
    <th class="text-end">Total Qty</th>
    <th>Transporter</th>
    <th>Status</th>
</tr>
</thead>

<tbody>

<?php
$dispatch_rows = 0;

while ($dispatch = mysqli_fetch_assoc($recent_dispatch_result)) {

    $dispatch_rows++;
?>

<tr>

<td>
    <strong>
        <?= htmlspecialchars($dispatch['dispatch_no'] ?? '-'); ?>
    </strong>
</td>

<td><?= dashDate($dispatch['dispatch_date']); ?></td>

<td>
    <div class="product-main">
        <?= htmlspecialchars($dispatch['client_name']); ?>
    </div>
    <div class="product-sub">
        <a href="/erp/client_po/view.php?id=<?= intval($dispatch['client_po_id']); ?>">
            <?= htmlspecialchars($dispatch['po_number']); ?>
        </a>
    </div>
</td>

<td>
    <div class="product-main">
        <?= htmlspecialchars($dispatch['product_name']); ?>
    </div>
    <?php if (!empty($dispatch['part_number'])) { ?>
    <div class="product-sub">
        <?= htmlspecialchars($dispatch['part_number']); ?>
    </div>
    <?php } ?>
</td>

<td class="text-end">
    <?= dashQty($dispatch['normal_dispatch_qty'] ?? 0); ?>
</td>

<td class="text-end">
    <?= dashQty($dispatch['extra_replacement_qty'] ?? 0); ?>
</td>

<td class="text-end">
    <strong>
        <?= dashQty($dispatch['dispatch_qty'] ?? 0); ?>
    </strong>
</td>

<td>
    <?= !empty($dispatch['transport_name'])
        ? htmlspecialchars($dispatch['transport_name'])
        : '-';
    ?>
</td>

<td>
    <?= dashDispatchBadge($dispatch['dispatch_status'] ?? ''); ?>
</td>

</tr>

<?php } ?>

<?php if ($dispatch_rows === 0) { ?>
<tr>
<td colspan="9" class="text-center text-muted py-4">
    No dispatch records found.
</td>
</tr>
<?php } ?>

</tbody>
</table>

</div>

<div class="mt-3">
    <a href="/erp/dispatch/index.php" class="quick-link">
        Open Dispatch →
    </a>
</div>

</div>
</div>

</div>
</div>


</div>
</div>
</div>



<script>

(function () {

    function renderPoStatusChart() {

        const element =
            document.getElementById(
                "po-status-chart"
            );

        if (!element) {
            return;
        }

        if (typeof ApexCharts === "undefined") {
            return;
        }

        const labels =
            <?= $po_status_chart_labels_json ?: '[]'; ?>;

        const values =
            <?= $po_status_chart_values_json ?: '[]'; ?>;


        const options = {

            chart: {
                type: "donut",
                height: 325,
                toolbar: {
                    show: false
                },
                events: {
                    dataPointSelection: function (
                        event,
                        chartContext,
                        config
                    ) {
                        const statusLinks = {
                            "Open": "/erp/client_po/index.php?status=Open",
                            "In Production": "/erp/client_po/index.php?status=In%20Production",
                            "Completed": "/erp/client_po/index.php?status=Completed",
                            "Partially Dispatched": "/erp/client_po/index.php?status=Partially%20Dispatched",
                            "Dispatched": "/erp/client_po/index.php?status=Dispatched",
                            "Closed": "/erp/client_po/index.php?status=Closed"
                        };

                        const selectedLabel =
                            labels[
                                config.dataPointIndex
                            ] || "";

                        if (
                            statusLinks[
                                selectedLabel
                            ]
                        ) {
                            window.location.href =
                                statusLinks[
                                    selectedLabel
                                ];
                        }
                    }
                }
            },

            series: values,

            labels: labels,

            legend: {
                position: "bottom",
                fontSize: "11px"
            },

            dataLabels: {
                enabled: true
            },

            stroke: {
                width: 2
            },

            plotOptions: {
                pie: {
                    donut: {
                        size: "68%",
                        labels: {
                            show: true,

                            name: {
                                show: true
                            },

                            value: {
                                show: true,
                                fontSize: "22px",
                                fontWeight: 700
                            },

                            total: {
                                show: true,
                                label: "Total PO",
                                formatter: function () {
                                    return <?= intval($total_po); ?>;
                                }
                            }
                        }
                    }
                }
            },

            tooltip: {
                y: {
                    formatter: function (value) {
                        return value + " PO";
                    }
                }
            },

            noData: {
                text: "No PO status data available"
            }

        };


        const chart =
            new ApexCharts(
                element,
                options
            );

        chart.render();

    }


    function renderDispatchTrendChart() {

        const element =
            document.getElementById(
                "dispatch-trend-chart"
            );

        if (!element) {
            return;
        }

        if (typeof ApexCharts === "undefined") {
            return;
        }


        const labels =
            <?= $dispatch_trend_labels_json ?: '[]'; ?>;

        const normalData =
            <?= $dispatch_trend_normal_json ?: '[]'; ?>;

        const extraData =
            <?= $dispatch_trend_extra_json ?: '[]'; ?>;

        const totalData =
            <?= $dispatch_trend_total_json ?: '[]'; ?>;


        if (!labels.length) {

            element.innerHTML =
                '<div class="text-center text-muted py-5">' +
                'No dispatch trend data available.' +
                '</div>';

            return;
        }


        const options = {

            chart: {
                height: 330,
                type: "line",
                stacked: false,
                toolbar: {
                    show: true
                }
            },

            series: [
                {
                    name: "Normal Qty",
                    type: "column",
                    data: normalData
                },
                {
                    name: "Extra Qty",
                    type: "column",
                    data: extraData
                },
                {
                    name: "Total Qty",
                    type: "line",
                    data: totalData
                }
            ],

            xaxis: {
                categories: labels,
                title: {
                    text: "Dispatch Date"
                }
            },

            yaxis: {
                min: 0,
                title: {
                    text: "Quantity"
                }
            },

            stroke: {
                width: [
                    0,
                    0,
                    3
                ],
                curve: "smooth"
            },

            plotOptions: {
                bar: {
                    columnWidth: "52%",
                    borderRadius: 3
                }
            },

            dataLabels: {
                enabled: false
            },

            legend: {
                position: "top"
            },

            tooltip: {
                shared: true,
                intersect: false,
                y: {
                    formatter: function (value) {

                        return Number(value)
                            .toLocaleString(
                                "en-IN",
                                {
                                    maximumFractionDigits: 2
                                }
                            );

                    }
                }
            },

            noData: {
                text: "No dispatch trend data available"
            }

        };


        const chart =
            new ApexCharts(
                element,
                options
            );

        chart.render();

    }


    function renderDashboardCharts() {

        renderPoStatusChart();

        renderDispatchTrendChart();

    }


    function loadApexCharts() {

        if (typeof ApexCharts !== "undefined") {
            renderDashboardCharts();
            return;
        }

        const scripts = [
            "/erp/dist/assets/libs/apexcharts/apexcharts.min.js",
            "/erp/assets/libs/apexcharts/apexcharts.min.js",
            "https://cdn.jsdelivr.net/npm/apexcharts"
        ];

        let index = 0;

        function loadNext() {

            if (typeof ApexCharts !== "undefined") {
                renderDashboardCharts();
                return;
            }

            if (index >= scripts.length) {
                const chartIds = [
                    "po-status-chart",
                    "dispatch-trend-chart"
                ];

                chartIds.forEach(
                    function (id) {

                        const el =
                            document.getElementById(
                                id
                            );

                        if (el) {

                            el.innerHTML =
                                '<div class="text-center text-muted py-5">' +
                                'Unable to load chart library.' +
                                '</div>';

                        }

                    }
                );

                return;
            }

            const script =
                document.createElement(
                    "script"
                );

            script.src =
                scripts[index++];

            script.onload =
                function () {

                    if (
                        typeof ApexCharts !==
                        "undefined"
                    ) {
                        renderDashboardCharts();
                    } else {
                        loadNext();
                    }

                };

            script.onerror =
                loadNext;

            document.head.appendChild(
                script
            );

        }

        loadNext();

    }


    if (
        document.readyState ===
        "loading"
    ) {

        document.addEventListener(
            "DOMContentLoaded",
            loadApexCharts
        );

    } else {

        loadApexCharts();

    }

})();

</script>


<?php
include("includes/footer.php");
?>
