<?php
declare(strict_types=1);

/**
 * Keep print-facing company settings aligned with the Super Admin Company Master.
 * Call inside the same transaction as the company insert/update.
 */
function syncQuotationCompanySettings(
    mysqli $db,
    int $companyId,
    string $companyName,
    string $contactEmail,
    string $mobile,
    string $alternateMobile,
    string $gstin,
    string $billingAddress,
    string $city,
    string $state,
    string $pincode,
    string $quotationNumberFormat,
    int $quotationStartingNumber = 1
): void {
    $addressParts = [$billingAddress, $city, $state, $pincode];
    $addressParts = array_values(array_filter($addressParts, static function ($part): bool {
        return trim((string)$part) !== '';
    }));
    $printAddress = implode(', ', $addressParts);
    $printPhone = trim($mobile) !== '' ? $mobile : $alternateMobile;

    $find = $db->prepare(
        'SELECT id FROM quotation_company_settings WHERE company_id = ? ORDER BY id ASC LIMIT 1'
    );
    $find->bind_param('i', $companyId);
    $find->execute();
    $row = $find->get_result()->fetch_assoc();
    $find->close();

    if ($row) {
        $settingsId = (int)$row['id'];
        $update = $db->prepare(
            "UPDATE quotation_company_settings
             SET company_name = ?, company_address = NULLIF(?, ''), gst_no = NULLIF(?, ''),
                 phone = NULLIF(?, ''), email = NULLIF(?, ''), quotation_prefix = ?, quotation_start_number = ?
             WHERE id = ? LIMIT 1"
        );
        $update->bind_param(
            'ssssssii',
            $companyName,
            $printAddress,
            $gstin,
            $printPhone,
            $contactEmail,
            $quotationNumberFormat,
            $quotationStartingNumber,
            $settingsId
        );
        $update->execute();
        $update->close();
        return;
    }

    $insert = $db->prepare(
        "INSERT INTO quotation_company_settings
            (company_id, company_name, company_address, gst_no, phone, email, quotation_prefix, quotation_start_number)
         VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, ?)"
    );
    $insert->bind_param(
        'issssssi',
        $companyId,
        $companyName,
        $printAddress,
        $gstin,
        $printPhone,
        $contactEmail,
        $quotationNumberFormat,
        $quotationStartingNumber
    );
    $insert->execute();
    $insert->close();
}
