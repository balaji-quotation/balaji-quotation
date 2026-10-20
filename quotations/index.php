<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

include("../includes/db.php");
require_once __DIR__ . '/../includes/permissions.php';
erpRequireCompanyUser($conn);
erpRequire('quotations.view');
$companyId = erpCurrentCompanyId();
include("../includes/header.php");
include("../includes/sidebar.php");


// =====================================================
// FILTERS
// =====================================================

$search =
    trim(
        $_GET['search'] ?? ''
    );

$status =
    trim(
        $_GET['status'] ?? ''
    );

$from_date =
    trim(
        $_GET['from_date'] ?? ''
    );

$to_date =
    trim(
        $_GET['to_date'] ?? ''
    );


// =====================================================
// QUERY
// =====================================================

$sql = "
    SELECT
        q.id,
        q.quotation_no,
        q.client_id,
        q.rfq_no,
        q.rfq_date,
        q.quotation_date,
        q.subject,
        q.currency,
        q.validity_text,
        q.validity_days,
        q.expiry_date,
        q.last_followup_date,
        q.next_followup_date,
        q.status,
        q.remarks,
        q.copied_from_quotation_id,
        q.created_by,
        q.created_at,
        q.updated_at,

        COALESCE(
            q.expiry_date,
            DATE_ADD(
                q.quotation_date,
                INTERVAL COALESCE(NULLIF(q.validity_days, 0), 30) DAY
            )
        ) AS effective_expiry_date,

        DATEDIFF(
            COALESCE(
                q.expiry_date,
                DATE_ADD(
                    q.quotation_date,
                    INTERVAL COALESCE(NULLIF(q.validity_days, 0), 30) DAY
                )
            ),
            CURDATE()
        ) AS days_remaining,

        c.company_name AS client_name,

        (
            SELECT COUNT(*)
            FROM quotation_rows qr
            WHERE qr.quotation_id = q.id
        ) AS item_count

    FROM quotations q

    INNER JOIN clients c
        ON c.id = q.client_id

    WHERE q.company_id = $companyId
";


// =====================================================
// SEARCH
// =====================================================

if ($search !== '') {

    $search_sql =
        mysqli_real_escape_string(
            $conn,
            $search
        );

    $sql .= "
        AND
        (
            q.quotation_no LIKE '%$search_sql%'
            OR q.rfq_no LIKE '%$search_sql%'
            OR q.subject LIKE '%$search_sql%'
            OR c.company_name LIKE '%$search_sql%'
        )
    ";
}


// =====================================================
// STATUS FILTER
// =====================================================

if ($status !== '') {

    $allowed_status = [
        'Draft',
        'Sent',
        'Revised',
        'Accepted',
        'Rejected',
        'Expired'
    ];

    if (
        in_array(
            $status,
            $allowed_status,
            true
        )
    ) {

        $status_sql =
            mysqli_real_escape_string(
                $conn,
                $status
            );

        $sql .= "
            AND q.status = '$status_sql'
        ";
    }
}


// =====================================================
// DATE FILTER
// =====================================================

if ($from_date !== '') {

    $from_date_sql =
        mysqli_real_escape_string(
            $conn,
            $from_date
        );

    $sql .= "
        AND q.quotation_date >= '$from_date_sql'
    ";
}


if ($to_date !== '') {

    $to_date_sql =
        mysqli_real_escape_string(
            $conn,
            $to_date
        );

    $sql .= "
        AND q.quotation_date <= '$to_date_sql'
    ";
}


// =====================================================
// ORDER
// =====================================================

$sql .= "
    ORDER BY
        q.quotation_date DESC,
        q.id DESC
";

$result =
    mysqli_query(
        $conn,
        $sql
    );

if (!$result) {

    die(
        "Quotation List Error: " .
        mysqli_error($conn)
    );
}


// =====================================================
// SUMMARY COUNTS
// =====================================================

$summary_sql = "
    SELECT

        COUNT(*) AS total_quotation,

        SUM(
            CASE
                WHEN status = 'Draft'
                THEN 1
                ELSE 0
            END
        ) AS draft_count,

        SUM(
            CASE
                WHEN status = 'Sent'
                THEN 1
                ELSE 0
            END
        ) AS sent_count,

        SUM(
            CASE
                WHEN status = 'Accepted'
                THEN 1
                ELSE 0
            END
        ) AS accepted_count,

        SUM(
            CASE
                WHEN status = 'Rejected'
                THEN 1
                ELSE 0
            END
        ) AS rejected_count,

        SUM(
            CASE
                WHEN status NOT IN ('Accepted', 'Rejected', 'Revised')
                 AND DATEDIFF(
                        COALESCE(
                            expiry_date,
                            DATE_ADD(
                                quotation_date,
                                INTERVAL COALESCE(NULLIF(validity_days, 0), 30) DAY
                            )
                        ),
                        CURDATE()
                    ) < 0
                THEN 1
                ELSE 0
            END
        ) AS followup_due_count,

        SUM(
            CASE
                WHEN status NOT IN ('Accepted', 'Rejected', 'Revised')
                 AND DATEDIFF(
                        COALESCE(
                            expiry_date,
                            DATE_ADD(
                                quotation_date,
                                INTERVAL COALESCE(NULLIF(validity_days, 0), 30) DAY
                            )
                        ),
                        CURDATE()
                    ) BETWEEN 0 AND 7
                THEN 1
                ELSE 0
            END
        ) AS expiring_soon_count

    FROM quotations
    WHERE company_id = $companyId
";

$summary_result =
    mysqli_query(
        $conn,
        $summary_sql
    );

if (!$summary_result) {

    die(
        "Quotation Summary Error: " .
        mysqli_error($conn)
    );
}

$summary =
    mysqli_fetch_assoc(
        $summary_result
    );

$total_quotation =
    intval(
        $summary[
            'total_quotation'
        ] ?? 0
    );

$draft_count =
    intval(
        $summary[
            'draft_count'
        ] ?? 0
    );

$sent_count =
    intval(
        $summary[
            'sent_count'
        ] ?? 0
    );

$accepted_count =
    intval(
        $summary[
            'accepted_count'
        ] ?? 0
    );

$rejected_count =
    intval(
        $summary[
            'rejected_count'
        ] ?? 0
    );

$followup_due_count =
    intval(
        $summary[
            'followup_due_count'
        ] ?? 0
    );

$expiring_soon_count =
    intval(
        $summary[
            'expiring_soon_count'
        ] ?? 0
    );


// =====================================================
// HELPERS
// =====================================================

function quotationDate($date)
{
    if (
        empty($date)
        ||
        $date === '0000-00-00'
    ) {
        return '-';
    }

    return date(
        'd-m-Y',
        strtotime($date)
    );
}


function quotationFollowupInfo($row)
{
    $status = trim(
        (string)($row['status'] ?? '')
    );

    $closed_statuses = [
        'Accepted',
        'Rejected',
        'Revised'
    ];

    if (
        in_array(
            $status,
            $closed_statuses,
            true
        )
    ) {
        return [
            'state' => 'closed',
            'class' => '',
            'badge' => '<span class="badge bg-light text-dark">Closed</span>',
            'text' => 'No follow-up required'
        ];
    }

    $days = isset($row['days_remaining'])
        ? intval($row['days_remaining'])
        : null;

    if ($days === null) {
        return [
            'state' => 'unknown',
            'class' => '',
            'badge' => '<span class="badge bg-secondary">Not Set</span>',
            'text' => 'Expiry date not available'
        ];
    }

    if ($days < 0) {

        $overdue_days = abs($days);

        return [
            'state' => 'overdue',
            'class' => 'quote-row-overdue',
            'badge' =>
                '<span class="badge bg-danger">' .
                'Follow-up Due' .
                '</span>',
            'text' =>
                $overdue_days .
                ' day' .
                ($overdue_days === 1 ? '' : 's') .
                ' overdue'
        ];
    }

    if ($days <= 7) {

        if ($days === 0) {
            $label = 'Expires Today';
        } else {
            $label =
                $days .
                ' day' .
                ($days === 1 ? '' : 's') .
                ' left';
        }

        return [
            'state' => 'soon',
            'class' => 'quote-row-expiring',
            'badge' =>
                '<span class="badge bg-warning text-dark">' .
                'Expiring Soon' .
                '</span>',
            'text' => $label
        ];
    }

    return [
        'state' => 'active',
        'class' => '',
        'badge' =>
            '<span class="badge bg-success">' .
            'Active' .
            '</span>',
        'text' =>
            $days .
            ' days left'
    ];
}


function quotationStatusBadge($status)
{
    $status =
        trim(
            $status ?? ''
        );

    if ($status === 'Draft') {
        return '<span class="badge bg-secondary">Draft</span>';
    }

    if ($status === 'Sent') {
        return '<span class="badge bg-primary">Sent</span>';
    }

    if ($status === 'Revised') {
        return '<span class="badge bg-info text-dark">Revised</span>';
    }

    if ($status === 'Accepted') {
        return '<span class="badge bg-success">Accepted</span>';
    }

    if ($status === 'Rejected') {
        return '<span class="badge bg-danger">Rejected</span>';
    }

    if ($status === 'Expired') {
        return '<span class="badge bg-warning text-dark">Expired</span>';
    }

    return '<span class="badge bg-light text-dark">' .
        htmlspecialchars(
            $status !== ''
                ? $status
                : '-'
        ) .
        '</span>';
}

?>

<style>

.quotation-page-card {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 6px 24px rgba(31,45,61,.06);
}

.quotation-summary-card {
    border: 0;
    border-radius: 12px;
    box-shadow: 0 4px 18px rgba(31,45,61,.05);
    height: 100%;
}

.quotation-summary-label {
    font-size: 10px;
    color: #7b8794;
    text-transform: uppercase;
    letter-spacing: .35px;
}

.quotation-summary-value {
    margin-top: 4px;
    font-size: 23px;
    line-height: 1.1;
    font-weight: 700;
    color: #26374a;
}

.quotation-title {
    font-weight: 700;
    color: #26374a;
}

.quotation-subtitle {
    font-size: 11px;
    color: #7b8794;
}

.quotation-table {
    font-size: 11px;
    min-width: 1050px;
}

.quotation-table th {
    white-space: nowrap;
    background: #f7f9fb;
    color: #566476;
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: .25px;
    vertical-align: middle;
}

.quotation-table td {
    vertical-align: middle;
    border-color: #edf0f4;
}

.quote-no {
    font-weight: 700;
    color: #26374a;
}

.quote-meta {
    font-size: 10px;
    color: #7b8794;
}

.action-btns {
    display: flex;
    gap: 4px;
    justify-content: center;
    flex-wrap: wrap;
}

.action-btns .btn {
    padding: 4px 7px;
    font-size: 10px;
}

.quote-row-overdue > td {
    background: #fff1f2 !important;
}

.quote-row-overdue:hover > td {
    background: #ffe4e6 !important;
}

.quote-row-expiring > td {
    background: #fff8e1 !important;
}

.quote-row-expiring:hover > td {
    background: #fff3cd !important;
}

.followup-meta {
    font-size: 10px;
    line-height: 1.35;
    margin-top: 4px;
}

.followup-expiry {
    font-size: 9px;
    color: #7b8794;
    margin-top: 2px;
}

</style>


<div class="content-page">

<div class="content">

<div class="container-fluid">


<!-- =====================================================
     PAGE HEADER
===================================================== -->

<div class="row mt-2 mb-2">

<div class="col-12">

<div class="card quotation-page-card">

<div class="card-body py-2 px-3">

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

    <div>

        <h4 class="quotation-title mb-1">
            Quotations
        </h4>

        <div class="quotation-subtitle">
            Client RFQ based quotation register
        </div>

    </div>

    <a
        href="add.php"
        class="btn btn-primary btn-sm"
    >
        <i class="mdi mdi-plus me-1"></i>
        New Quotation
    </a>

</div>

</div>

</div>

</div>

</div>


<!-- =====================================================
     SUMMARY
===================================================== -->

<div class="row g-2 mb-3">

<div class="col-xl col-lg-4 col-md-6">

<div class="card quotation-summary-card">

<div class="card-body">

    <div class="quotation-summary-label">
        Total Quotations
    </div>

    <div class="quotation-summary-value">
        <?= number_format(
            $total_quotation
        ); ?>
    </div>

</div>

</div>

</div>


<div class="col-xl col-lg-4 col-md-6">

<div class="card quotation-summary-card">

<div class="card-body">

    <div class="quotation-summary-label">
        Draft
    </div>

    <div class="quotation-summary-value text-secondary">
        <?= number_format(
            $draft_count
        ); ?>
    </div>

</div>

</div>

</div>


<div class="col-xl col-lg-4 col-md-6">

<div class="card quotation-summary-card">

<div class="card-body">

    <div class="quotation-summary-label">
        Sent
    </div>

    <div class="quotation-summary-value text-primary">
        <?= number_format(
            $sent_count
        ); ?>
    </div>

</div>

</div>

</div>


<div class="col-xl col-lg-4 col-md-6">

<div class="card quotation-summary-card">

<div class="card-body">

    <div class="quotation-summary-label">
        Follow-up Due
    </div>

    <div class="quotation-summary-value text-danger">
        <?= number_format(
            $followup_due_count
        ); ?>
    </div>

    <?php if ($expiring_soon_count > 0) { ?>
        <div class="quote-meta mt-1">
            <?= number_format($expiring_soon_count); ?> expiring soon
        </div>
    <?php } ?>

</div>

</div>

</div>


<div class="col-xl col-lg-4 col-md-6">

<div class="card quotation-summary-card">

<div class="card-body">

    <div class="quotation-summary-label">
        Accepted
    </div>

    <div class="quotation-summary-value text-success">
        <?= number_format(
            $accepted_count
        ); ?>
    </div>

</div>

</div>

</div>


<div class="col-xl col-lg-4 col-md-6">

<div class="card quotation-summary-card">

<div class="card-body">

    <div class="quotation-summary-label">
        Rejected
    </div>

    <div class="quotation-summary-value text-danger">
        <?= number_format(
            $rejected_count
        ); ?>
    </div>

</div>

</div>

</div>

</div>


<!-- =====================================================
     FILTERS + LIST
===================================================== -->

<div class="card quotation-page-card">

<div class="card-body">


<form
    method="GET"
    class="row g-2 mb-3"
>


<div class="col-xl-4 col-lg-4 col-md-6">

<label class="form-label">
    Search
</label>

<input
    type="text"
    name="search"
    class="form-control"
    placeholder="Quotation / RFQ / Client / Subject"
    value="<?= htmlspecialchars(
        $search
    ); ?>"
>

</div>


<div class="col-xl-2 col-lg-2 col-md-6">

<label class="form-label">
    Status
</label>

<select
    name="status"
    class="form-select"
>

<option value="">
    All Status
</option>

<?php
$status_options = [
    'Draft',
    'Sent',
    'Revised',
    'Accepted',
    'Rejected',
    'Expired'
];

foreach (
    $status_options
    as $status_option
) {
?>

<option
    value="<?= htmlspecialchars(
        $status_option
    ); ?>"
    <?= (
        $status ===
        $status_option
    )
        ? 'selected'
        : '';
    ?>
>
    <?= htmlspecialchars(
        $status_option
    ); ?>
</option>

<?php } ?>

</select>

</div>


<div class="col-xl-2 col-lg-2 col-md-6">

<label class="form-label">
    From Date
</label>

<input
    type="date"
    name="from_date"
    class="form-control"
    value="<?= htmlspecialchars(
        $from_date
    ); ?>"
>

</div>


<div class="col-xl-2 col-lg-2 col-md-6">

<label class="form-label">
    To Date
</label>

<input
    type="date"
    name="to_date"
    class="form-control"
    value="<?= htmlspecialchars(
        $to_date
    ); ?>"
>

</div>


<div class="col-xl-2 col-lg-2 col-md-12 d-flex align-items-end">

<div class="d-flex gap-1 w-100">

<button
    type="submit"
    class="btn btn-primary flex-grow-1"
>
    Search
</button>

<a
    href="index.php"
    class="btn btn-secondary"
>
    Reset
</a>

</div>

</div>


</form>


<div class="table-responsive">

<table class="table table-bordered table-hover align-middle quotation-table">

<thead>

<tr>

    <th style="width:55px;">
        ID
    </th>

    <th>
        Quotation No.
    </th>

    <th>
        Client
    </th>

    <th>
        RFQ No.
    </th>

    <th>
        Quote Date
    </th>

    <th>
        Subject
    </th>

    <th class="text-center">
        Items
    </th>

    
    <th class="text-center">
        Status
    </th>

    <th
        class="text-center"
        style="min-width:155px;"
    >
        Validity / Follow-up
    </th>

    <th
        class="text-center"
        style="min-width:300px;"
    >
        Action
    </th>

</tr>

</thead>

<tbody>


<?php

if (
    mysqli_num_rows(
        $result
    ) > 0
) {

    while (
        $row =
        mysqli_fetch_assoc(
            $result
        )
    ) {

?>

<?php
$followup_info =
    quotationFollowupInfo(
        $row
    );
?>

<tr class="<?= htmlspecialchars(
    $followup_info['class']
); ?>">


<td>
    <?= intval(
        $row['id']
    ); ?>
</td>


<td>

    <div class="quote-no">
        <?= htmlspecialchars(
            $row[
                'quotation_no'
            ]
        ); ?>
    </div>

    <?php if (
        !empty(
            $row[
                'validity_text'
            ]
        )
    ) { ?>

        <div class="quote-meta">
            Validity:
            <?= htmlspecialchars(
                $row[
                    'validity_text'
                ]
            ); ?>
        </div>

    <?php } ?>

</td>


<td>
    <?= htmlspecialchars(
        $row[
            'client_name'
        ]
    ); ?>
</td>


<td>

<?php

if (
    !empty(
        $row[
            'rfq_no'
        ]
    )
) {

    echo htmlspecialchars(
        $row[
            'rfq_no'
        ]
    );

    if (
        !empty(
            $row[
                'rfq_date'
            ]
        )
    ) {

        echo
            '<div class="quote-meta">' .
            quotationDate(
                $row[
                    'rfq_date'
                ]
            ) .
            '</div>';
    }

} else {

    echo '-';
}

?>

</td>


<td>
    <?= quotationDate(
        $row[
            'quotation_date'
        ]
    ); ?>
</td>


<td>

<?php

if (
    !empty(
        $row[
            'subject'
        ]
    )
) {

    echo htmlspecialchars(
        $row[
            'subject'
        ]
    );

} else {

    echo '-';
}

?>

</td>


<td class="text-center">

    <span class="badge bg-light text-dark">
        <?= intval(
            $row[
                'item_count'
            ] ?? 0
        ); ?>
    </span>

</td>





<td class="text-center">
    <?= quotationStatusBadge(
        $row[
            'status'
        ]
    ); ?>
</td>


<td class="text-center">

    <?= $followup_info['badge']; ?>

    <div
        class="followup-meta <?= (
            $followup_info['state'] === 'overdue'
        )
            ? 'text-danger fw-bold'
            : (
                $followup_info['state'] === 'soon'
                    ? 'text-warning fw-bold'
                    : 'text-muted'
            );
        ?>"
    >
        <?= htmlspecialchars(
            $followup_info['text']
        ); ?>
    </div>

    <?php if (
        !empty(
            $row['effective_expiry_date']
        )
    ) { ?>

        <div class="followup-expiry">
            Expiry:
            <?= quotationDate(
                $row['effective_expiry_date']
            ); ?>
        </div>

    <?php } ?>

</td>


<td class="text-center">

<div class="action-btns">

    <a
        href="view.php?id=<?= intval(
            $row[
                'id'
            ]
        ); ?>"
        class="btn btn-info text-white"
        title="View"
    >
        <i class="mdi mdi-eye-outline"></i>
        View
    </a>

    <a
        href="edit.php?id=<?= intval(
            $row[
                'id'
            ]
        ); ?>"
        class="btn btn-warning"
        title="Edit"
    >
        <i class="mdi mdi-pencil-outline"></i>
        Edit
    </a>

    <a
        href="revise.php?id=<?= intval(
            $row[
                'id'
            ]
        ); ?>"
        class="btn btn-primary"
        title="Create Revision"
    >
        <i class="mdi mdi-file-document-edit-outline"></i>
        Revise
    </a>


    <a
        href="print.php?id=<?= intval(
            $row[
                'id'
            ]
        ); ?>"
        class="btn btn-success"
        target="_blank"
        title="Print / PDF"
    >
        <i class="mdi mdi-printer-outline"></i>
        Print
    </a>

</div>

</td>


</tr>

<?php

    }

} else {

?>

<tr>

<td
    colspan="11"
    class="text-center text-muted py-5"
>

    <i class="mdi mdi-file-document-outline fs-2 d-block mb-2"></i>

    No quotation found.

</td>

</tr>

<?php
}
?>

</tbody>

</table>

</div>

</div>

</div>


</div>

</div>

</div>


<?php
include("../includes/footer.php");
?>
