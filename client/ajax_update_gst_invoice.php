<?php
include '../includes/client_auth_check.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_meta') {
        $id = intval($_POST['id'] ?? 0);
        $notes = $_POST['notes'] ?? null;
        $subject = $_POST['subject'] ?? null;
        $terms = $_POST['terms'] ?? null;
        $bank_custom = $_POST['bank_details_custom'] ?? null;
        
        $updates = [];
        $params = [];
        $types = "";
        
        if ($notes !== null) { $updates[] = "notes=?"; $params[] = sanitize($notes); $types .= "s"; }
        if ($subject !== null) { $updates[] = "subject=?"; $params[] = sanitize($subject); $types .= "s"; }
        if ($terms !== null) { $updates[] = "terms=?"; $params[] = sanitize($terms); $types .= "s"; }
        if ($bank_custom !== null) { $updates[] = "bank_details_custom=?"; $params[] = sanitize($bank_custom); $types .= "s"; }
        
        if (empty($updates)) {
            echo json_encode(['success' => false, 'error' => 'No fields to update']);
            exit();
        }
        
        $sql = "UPDATE gst_invoices SET " . implode(", ", $updates) . " WHERE id=? AND company_id=?";
        $params[] = $id; $params[] = $companyId;
        $types .= "ii";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
    } 
    elseif ($action === 'update_bank_global') {

        $bank_name = sanitize($_POST['bank_name'] ?? '');
        $account_number = sanitize($_POST['account_number'] ?? '');
        $ifsc_code = sanitize($_POST['ifsc_code'] ?? '');
        $upi_id = sanitize($_POST['upi_id'] ?? '');
        
        $stmt = $conn->prepare("UPDATE gst_settings SET bank_name=?, account_number=?, ifsc_code=?, upi_id=? WHERE company_id=?");
        $stmt->bind_param("ssssi", $bank_name, $account_number, $ifsc_code, $upi_id, $companyId);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
    }
    exit();
}
