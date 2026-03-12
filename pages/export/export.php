<?php
// export/export.php
require_once '../config/database.php';
require_once '../config/session.php';

requireLogin();
if (!validateSession($pdo)) {
    destroySession($pdo);
    header('Location: /auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['export_type'])) {
    die('Invalid request');
}

$exportType = $_POST['export_type'];
$filename = $exportType . '_export_' . date('Y-m-d_His') . '.csv';

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

try {
    switch ($exportType) {
        case 'patients':
            // Export patients
            fputcsv($output, ['Patient ID', 'Full Name', 'Phone Number', 'Age', 'Gender', 'Date of Birth', 'ID Number', 'Email', 'Address', 'Registration Date', 'Status']);
            
            $stmt = $pdo->query("SELECT patient_id, full_name, phone_number, age, gender, date_of_birth, id_number, email, address, registration_date, status FROM patients ORDER BY registration_date DESC");
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
            break;
            
        case 'admissions':
            // Export admissions
            fputcsv($output, ['Admission ID', 'Patient Name', 'Patient ID', 'Room Number', 'Ward Name', 'Admission Date', 'Discharge Date', 'Admitted By', 'Diagnosis', 'Status']);
            
            $stmt = $pdo->query("
                SELECT 
                    a.id,
                    p.full_name,
                    p.patient_id,
                    r.room_number,
                    r.ward_name,
                    a.admission_date,
                    a.discharge_date,
                    s.full_name as admitted_by,
                    a.diagnosis,
                    a.status
                FROM admissions a
                LEFT JOIN patients p ON a.patient_id = p.id
                LEFT JOIN rooms r ON a.room_id = r.id
                LEFT JOIN staff_users s ON a.admitted_by = s.id
                ORDER BY a.admission_date DESC
            ");
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
            break;
            
        case 'inventory':
            // Export inventory
            fputcsv($output, ['Item Code', 'Item Name', 'Category', 'Quantity', 'Unit', 'Price Per Unit', 'Reorder Level', 'Status', 'Last Restocked']);
            
            $stmt = $pdo->query("SELECT item_code, item_name, category, quantity, unit, price_per_unit, reorder_level, status, last_restocked FROM inventory ORDER BY item_name ASC");
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
            break;
            
        case 'departments':
            // Export departments
            fputcsv($output, ['Department ID', 'Department Code', 'Name', 'Description', 'Consultation Fee', 'Status', 'Created At']);
            
            $stmt = $pdo->query("SELECT department_id, code, name, description, consultation_fee, status, created_at FROM departments ORDER BY name ASC");
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
            break;
            
        default:
            fputcsv($output, ['Error: Invalid export type']);
            break;
    }
    
    fclose($output);
    exit;
    
} catch (PDOException $e) {
    fputcsv($output, ['Error exporting data: ' . $e->getMessage()]);
    fclose($output);
    exit;
}
?>