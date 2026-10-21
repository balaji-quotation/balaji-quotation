<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('superadmin.change_requests');
require_once __DIR__ . '/../../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
$token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || $token === '' || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(400);
    exit('Request expired. Reload the page and try again.');
}
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
$status = trim((string)($_POST['status'] ?? ''));
$note = mb_substr(trim((string)($_POST['admin_note'] ?? '')), 0, 2000, 'UTF-8');
$appliedVersionId = filter_var($_POST['applied_template_version_id'] ?? null, FILTER_VALIDATE_INT);
$appliedVersionId = $appliedVersionId ? (int)$appliedVersionId : 0;
$allowed = ['Pending','In Review','Approved','Rejected','Completed'];
if (!$id || $id < 1 || !in_array($status, $allowed, true)) {
    http_response_code(400);
    exit('Invalid request update.');
}

$reviewerId = (int)$_SESSION['user_id'];
$conn->begin_transaction();
try {
    $requestStmt = $conn->prepare(
        'SELECT company_id, status, applied_template_version_id FROM template_change_requests WHERE id = ? LIMIT 1 FOR UPDATE'
    );
    $requestStmt->bind_param('i', $id);
    $requestStmt->execute();
    $request = $requestStmt->get_result()->fetch_assoc();
    $requestStmt->close();
    if (!$request) {
        throw new RuntimeException('Template change request not found.');
    }
    $companyId = (int)$request['company_id'];
    $existingAppliedVersionId = (int)($request['applied_template_version_id'] ?? 0);

    if ($existingAppliedVersionId > 0 && !in_array($status, ['Approved','Completed'], true)) {
        throw new RuntimeException('An applied template request can only move to Completed.');
    }
    if ($status === 'Completed' && $existingAppliedVersionId === 0) {
        throw new RuntimeException('Apply an approved company template version before completing this request.');
    }

    if ($status === 'Approved') {
        if ($appliedVersionId < 1) {
            throw new RuntimeException('Choose the company template version to apply.');
        }
        if ($existingAppliedVersionId > 0 && $existingAppliedVersionId !== $appliedVersionId) {
            throw new RuntimeException('This request already has an applied template version.');
        }

        $versionStmt = $conn->prepare(
            'SELECT v.template_id FROM quotation_template_versions v JOIN quotation_templates t ON t.id = v.template_id WHERE v.id = ? AND t.company_id = ? AND t.is_active = 1 LIMIT 1'
        );
        $versionStmt->bind_param('ii', $appliedVersionId, $companyId);
        $versionStmt->execute();
        $version = $versionStmt->get_result()->fetch_assoc();
        $versionStmt->close();
        if (!$version) {
            throw new RuntimeException('Choose an active template version owned by this company.');
        }

        if ($existingAppliedVersionId === 0) {
            $templateId = (int)$version['template_id'];
            $assignStmt = $conn->prepare(
                'UPDATE users u JOIN quotation_template_versions old_version ON old_version.id = u.assigned_template_version_id SET u.assigned_template_version_id = ? WHERE u.company_id = ? AND old_version.template_id = ?'
            );
            $assignStmt->bind_param('iii', $appliedVersionId, $companyId, $templateId);
            $assignStmt->execute();
            $assignStmt->close();
        }

        $stmt = $conn->prepare(
            'UPDATE template_change_requests SET status = ?, admin_note = NULLIF(?, \'\'), applied_template_version_id = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('ssiii', $status, $note, $appliedVersionId, $reviewerId, $id);
    } else {
        $stmt = $conn->prepare(
            'UPDATE template_change_requests SET status = ?, admin_note = NULLIF(?, \'\'), reviewed_by = ?, reviewed_at = NOW() WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('ssii', $status, $note, $reviewerId, $id);
    }
    $stmt->execute();
    $stmt->close();
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    error_log('Template change request update failed: ' . $error->getMessage());
    http_response_code(400);
    exit('The request could not be updated. Check the company template version and request status.');
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
header('Location: index.php?updated=1');
exit;
