<?php

require_once '../config/database.php';
require_once '../config/session.php';

requireLogin();

if (!validateSession($pdo)) {
    destroySession($pdo);
    header('Location: /auth/login.php');
    exit;
}

$pageTitle = 'Patient Registration - Arawa Hospital';

// Fetch departments
$stmt = $pdo->query("SELECT * FROM departments WHERE status = 'active' ORDER BY name ASC");
$departments = $stmt->fetchAll();

// Handle form submission
$errors = [];
$successMessage = '';
$receiptData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_registration'])) {
    // Validate inputs
    $fullName = trim($_POST['full_name'] ?? '');
    $phoneNumber = trim($_POST['phone_number'] ?? '');
    $age = trim($_POST['age'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $dateOfBirth = $_POST['date_of_birth'] ?? '';
    $idNumber = trim($_POST['id_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $targetDepartment = $_POST['target_department'] ?? '';
    $paymentMode = $_POST['payment_mode'] ?? 'mpesa';
    
    // Validation
    if (empty($fullName)) $errors['full_name'] = 'Full name is required';
    if (empty($phoneNumber)) {
        $errors['phone_number'] = 'Phone number is required';
    } elseif (!preg_match('/^(07|01)[0-9]{8}$/', $phoneNumber)) {
        $errors['phone_number'] = 'Please enter a valid Kenyan phone number';
    }
    if (empty($age) || $age < 1) $errors['age'] = 'Valid age is required';
    if (empty($gender)) $errors['gender'] = 'Gender is required';
    if (empty($dateOfBirth)) $errors['date_of_birth'] = 'Date of birth is required';
    if (empty($idNumber)) $errors['id_number'] = 'ID number is required';
    if (empty($address)) $errors['address'] = 'Address is required';
    if (empty($targetDepartment)) $errors['target_department'] = 'Target department is required';
    
    // If no errors, insert patient
    if (empty($errors)) {
        try {
            // Get department consultation fee
            $deptStmt = $pdo->prepare("SELECT id, consultation_fee, name FROM departments WHERE id = ?");
            $deptStmt->execute([$targetDepartment]);
            $dept = $deptStmt->fetch();
            
            if (!$dept) {
                $errors['target_department'] = 'Invalid department selected';
            } else {
                // Format phone number
                $formattedPhone = '+254' . substr($phoneNumber, 1);
                
                // Capitalize name
                $fullName = ucwords(strtolower($fullName));
                
                // Insert patient
                $stmt = $pdo->prepare("
                    INSERT INTO patients (
                        full_name, phone_number, age, gender, date_of_birth, 
                        id_number, email, address, target_department_id, 
                        payment_mode, registration_fee, payment_status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                
                $stmt->execute([
                    $fullName,
                    $formattedPhone,
                    $age,
                    $gender,
                    $dateOfBirth,
                    $idNumber,
                    $email ?: null,
                    $address,
                    $dept['id'],
                    $paymentMode,
                    $dept['consultation_fee']
                ]);
                
                $patientId = $pdo->lastInsertId();
                
                // Get generated patient_id
                $patientStmt = $pdo->prepare("SELECT patient_id FROM patients WHERE id = ?");
                $patientStmt->execute([$patientId]);
                $patient = $patientStmt->fetch();
                
                // Generate receipt number
                $receiptNumber = 'RCP' . date('Ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                // Insert receipt
                $receiptStmt = $pdo->prepare("
                    INSERT INTO registration_receipts (
                        receipt_number, patient_id, department_id, 
                        consultation_fee, payment_mode, payment_status
                    ) VALUES (?, ?, ?, ?, ?, 'pending')
                ");
                
                $receiptStmt->execute([
                    $receiptNumber,
                    $patientId,
                    $dept['id'],
                    $dept['consultation_fee'],
                    $paymentMode
                ]);
                
                // Prepare receipt data
                $receiptData = [
                    'receipt_number' => $receiptNumber,
                    'patient_name' => $fullName,
                    'patient_phone' => $phoneNumber,
                    'patient_id' => $patient['patient_id'],
                    'department' => $dept['name'],
                    'consultation_fee' => $dept['consultation_fee'],
                    'payment_mode' => ucfirst($paymentMode),
                    'date' => date('d/m/Y'),
                    'time' => date('h:i A')
                ];
                
                $successMessage = 'Patient registered successfully!';
                
                // Clear form
                $_POST = [];
            }
        } catch (PDOException $e) {
            $errors['database'] = 'Registration failed. Please try again.';
            error_log($e->getMessage());
            $errors['database'] .= ' (Debug: ' . $e->getMessage() . ')';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <link rel="stylesheet" href="../assets/css/pages.css">
</head>
<body>

<?php include_once '../templates/sidebar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title">Patient Registration</h1>
    </div>

    <?php if ($successMessage && $receiptData): ?>
    <!-- Receipt Modal -->
    <div class="receipt-modal active" id="receiptModal">
        <div class="receipt-overlay" onclick="closeReceipt()"></div>
        <div class="receipt-container">
            <div class="receipt-header">
                <div class="receipt-logo">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="white">
                        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
                    </svg>
                </div>
                <h2>ARAWA HOSPITAL</h2>
                <p>Registration Receipt</p>
            </div>
            
            <div class="receipt-body">
                <div class="receipt-row">
                    <span class="receipt-label">Receipt No:</span>
                    <span class="receipt-value"><?php echo htmlspecialchars($receiptData['receipt_number']); ?></span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Patient Name:</span>
                    <span class="receipt-value"><?php echo htmlspecialchars($receiptData['patient_name']); ?></span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Patient ID:</span>
                    <span class="receipt-value"><?php echo htmlspecialchars($receiptData['patient_id']); ?></span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Phone:</span>
                    <span class="receipt-value"><?php echo htmlspecialchars($receiptData['patient_phone']); ?></span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Department:</span>
                    <span class="receipt-value"><?php echo htmlspecialchars($receiptData['department']); ?></span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Date:</span>
                    <span class="receipt-value"><?php echo htmlspecialchars($receiptData['date']); ?></span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Time:</span>
                    <span class="receipt-value"><?php echo htmlspecialchars($receiptData['time']); ?></span>
                </div>
                
                <div class="receipt-divider"></div>
                
                <div class="receipt-total">
                    <span class="receipt-label">Registration Fee:</span>
                    <span class="receipt-amount">KSh <?php echo number_format($receiptData['consultation_fee'], 2); ?></span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Payment Mode:</span>
                    <span class="receipt-value"><?php echo htmlspecialchars($receiptData['payment_mode']); ?></span>
                </div>
            </div>
            
            <div class="receipt-footer">
                <button class="btn-print" onclick="window.print()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 6 2 18 2 18 9"></polyline>
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                        <rect x="6" y="14" width="12" height="8"></rect>
                    </svg>
                    Print Receipt
                </button>
                <button class="btn-close" onclick="closeReceipt()">Close</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="error-alert" style="background:#fee; border:1px solid #f99; padding:15px; border-radius:5px; margin-bottom:20px; color:#c33;">
        <strong>Validation Errors:</strong>
        <ul>
            <?php foreach ($errors as $field => $message): ?>
                <li><?php echo htmlspecialchars($message); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST" class="registration-form" id="registrationForm" novalidate>
        <div class="form-grid">
            <!-- Patient Information -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </div>
                    <h3>Patient Information</h3>
                </div>
                
                <div class="section-body">
                    <div class="form-grid-2">
                        <!-- Full Name -->
                        <div class="form-group">
                            <label for="full_name">Full Name *</label>
                            <input 
                                type="text" 
                                id="full_name" 
                                name="full_name" 
                                value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
                                class="<?php echo isset($errors['full_name']) ? 'error' : ''; ?>"
                                required
                            >
                            <?php if (isset($errors['full_name'])): ?>
                                <span class="error-message"><?php echo $errors['full_name']; ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Phone Number -->
                        <div class="form-group">
                            <label for="phone_number">Phone Number *</label>
                            <input 
                                type="tel" 
                                id="phone_number" 
                                name="phone_number" 
                                placeholder="0712345678"
                                value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>"
                                class="<?php echo isset($errors['phone_number']) ? 'error' : ''; ?>"
                                required
                            >
                            <?php if (isset($errors['phone_number'])): ?>
                                <span class="error-message"><?php echo $errors['phone_number']; ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Date of Birth -->
                        <div class="form-group">
                            <label for="date_of_birth">Date of Birth *</label>
                            <input 
                                type="date" 
                                id="date_of_birth" 
                                name="date_of_birth" 
                                value="<?php echo htmlspecialchars($_POST['date_of_birth'] ?? ''); ?>"
                                class="<?php echo isset($errors['date_of_birth']) ? 'error' : ''; ?>"
                                onchange="calculateAge()"
                                required
                            >
                            <?php if (isset($errors['date_of_birth'])): ?>
                                <span class="error-message"><?php echo $errors['date_of_birth']; ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Age -->
                        <div class="form-group">
                            <label for="age">Age *</label>
                            <input 
                                type="number" 
                                id="age" 
                                name="age" 
                                value="<?php echo htmlspecialchars($_POST['age'] ?? ''); ?>"
                                class="<?php echo isset($errors['age']) ? 'error' : ''; ?>"
                                readonly
                                required
                            >
                            <?php if (isset($errors['age'])): ?>
                                <span class="error-message"><?php echo $errors['age']; ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Gender -->
                        <div class="form-group">
                            <label for="gender">Gender *</label>
                            <select 
                                id="gender" 
                                name="gender" 
                                class="<?php echo isset($errors['gender']) ? 'error' : ''; ?>"
                                required
                            >
                                <option value="">Select Gender</option>
                                <option value="Male" <?php echo ($_POST['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($_POST['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                <option value="Other" <?php echo ($_POST['gender'] ?? '') === 'Other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                            <?php if (isset($errors['gender'])): ?>
                                <span class="error-message"><?php echo $errors['gender']; ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- ID Number -->
                        <div class="form-group">
                            <label for="id_number">ID Number *</label>
                            <input 
                                type="text" 
                                id="id_number" 
                                name="id_number" 
                                value="<?php echo htmlspecialchars($_POST['id_number'] ?? ''); ?>"
                                class="<?php echo isset($errors['id_number']) ? 'error' : ''; ?>"
                                required
                            >
                            <?php if (isset($errors['id_number'])): ?>
                                <span class="error-message"><?php echo $errors['id_number']; ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Email -->
                        <div class="form-group">
                            <label for="email">Email (Optional)</label>
                            <input 
                                type="email" 
                                id="email" 
                                name="email" 
                                value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                            >
                        </div>

                        <!-- Address -->
                        <div class="form-group">
                            <label for="address">Address *</label>
                            <input 
                                type="text" 
                                id="address" 
                                name="address" 
                                value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>"
                                class="<?php echo isset($errors['address']) ? 'error' : ''; ?>"
                                required
                            >
                            <?php if (isset($errors['address'])): ?>
                                <span class="error-message"><?php echo $errors['address']; ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Target Department -->
                        <div class="form-group">
                            <label for="target_department">Target Department *</label>
                            <select 
                                id="target_department" 
                                name="target_department" 
                                class="<?php echo isset($errors['target_department']) ? 'error' : ''; ?>"
                                onchange="updateConsultationFee()"
                                required
                            >
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option 
                                        value="<?php echo $dept['id']; ?>" 
                                        data-fee="<?php echo $dept['consultation_fee']; ?>"
                                        <?php echo ($_POST['target_department'] ?? '') == $dept['id'] ? 'selected' : ''; ?>
                                    >
                                        <?php echo htmlspecialchars($dept['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['target_department'])): ?>
                                <span class="error-message"><?php echo $errors['target_department']; ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Payment Mode -->
                        <div class="form-group">
                            <label for="payment_mode">Payment Mode *</label>
                            <select id="payment_mode" name="payment_mode" required>
                                <option value="mpesa" <?php echo ($_POST['payment_mode'] ?? 'mpesa') === 'mpesa' ? 'selected' : ''; ?>>M-Pesa</option>
                                <option value="cash" <?php echo ($_POST['payment_mode'] ?? '') === 'cash' ? 'selected' : ''; ?>>Cash</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Section -->
            <div class="payment-section">
                <div class="section-header">
                    <div class="section-icon green">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                            <line x1="1" y1="10" x2="23" y2="10"></line>
                        </svg>
                    </div>
                    <h3>Payment Information</h3>
                </div>
                
                <div class="section-body">
                    <div class="fee-display">
                        <div class="fee-label">Registration Fee</div>
                        <div class="fee-amount">KSh <span id="consultationFee">0.00</span></div>
                    </div>
                    
                    <div class="payment-info">
                        <p><strong>Payment Instructions:</strong></p>
                        <ul>
                            <li>Select your preferred department</li>
                            <li>Registration fee will be displayed</li>
                            <li>Complete payment to activate registration</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" name="submit_registration" class="btn btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                    <polyline points="17 21 17 13 7 13 7 21"></polyline>
                    <polyline points="7 3 7 8 15 8"></polyline>
                </svg>
                Register Patient
            </button>
        </div>
    </form>
</div>

<script src="../assets/js/theme.js"></script>
<script src="../assets/js/sidebar.js"></script>
<script src="../assets/js/main.js"></script>
<script>
function calculateAge() {
    const dob = document.getElementById('date_of_birth').value;
    if (dob) {
        const birthDate = new Date(dob);
        const today = new Date();
        let age = today.getFullYear() - birthDate.getFullYear();
        const monthDiff = today.getMonth() - birthDate.getMonth();
        
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
            age--;
        }
        
        document.getElementById('age').value = age > 0 ? age : '';
    }
}

function updateConsultationFee() {
    const select = document.getElementById('target_department');
    const selectedOption = select.options[select.selectedIndex];
    const fee = selectedOption.getAttribute('data-fee') || '0.00';
    document.getElementById('consultationFee').textContent = parseFloat(fee).toFixed(2);
}

function closeReceipt() {
    document.getElementById('receiptModal').classList.remove('active');
    window.location.href = 'registration.php';
}

// Initialize fee on page load and handle form submission
document.addEventListener('DOMContentLoaded', function() {
    updateConsultationFee();
    const dob = document.getElementById('date_of_birth').value;
    if (dob) calculateAge();
    
    // Submit handler to validate before submission
    const form = document.getElementById('registrationForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const age = document.getElementById('age').value;
            const fullName = document.getElementById('full_name').value.trim();
            const phone = document.getElementById('phone_number').value.trim();
            const gender = document.getElementById('gender').value;
            const dob = document.getElementById('date_of_birth').value;
            const idNum = document.getElementById('id_number').value.trim();
            const address = document.getElementById('address').value.trim();
            const dept = document.getElementById('target_department').value;
            
            // Validate age first
            if (!age || parseInt(age) < 1) {
                alert('Please select a valid date of birth to calculate age');
                e.preventDefault();
                return false;
            }
            
            // Validate other fields
            if (!fullName) {
                alert('Please enter full name');
                e.preventDefault();
                return false;
            }
            if (!phone) {
                alert('Please enter phone number');
                e.preventDefault();
                return false;
            }
            if (!dob) {
                alert('Please select date of birth');
                e.preventDefault();
                return false;
            }
            if (!gender) {
                alert('Please select gender');
                e.preventDefault();
                return false;
            }
            if (!idNum) {
                alert('Please enter ID number');
                e.preventDefault();
                return false;
            }
            if (!address) {
                alert('Please enter address');
                e.preventDefault();
                return false;
            }
            if (!dept) {
                alert('Please select a department');
                e.preventDefault();
                return false;
            }
            
            console.log('All validations passed, form submitting...');
        });
    }
});
</script>
</body>
</html>