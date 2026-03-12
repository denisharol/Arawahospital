<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireLogin();

if (!validateSession($pdo)) {
    destroySession($pdo);
    header('Location: /auth/login.php');
    exit;
}

$pageTitle = 'Billing Records - Arawa Hospital';

if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $method  = $_SERVER['REQUEST_METHOD'];
    $resource = $_GET['resource'] ?? '';
    $id      = $_GET['id'] ?? null;
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];

    function respond($data, $code = 200) { http_response_code($code); echo json_encode(['data' => $data]); exit; }
    function respondError($msg, $code = 400) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }

    // Lookup data
    if ($resource === 'lookup') {
        global $pdo;
        $patients   = $pdo->query("SELECT id, patient_id, full_name FROM patients ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
        $services   = $pdo->query("SELECT id, service_name, base_price FROM services ORDER BY service_name")->fetchAll(PDO::FETCH_ASSOC);
        $labs       = $pdo->query("SELECT id, procedure_name, price FROM lab_procedures ORDER BY procedure_name")->fetchAll(PDO::FETCH_ASSOC);
        $inventory  = $pdo->query("SELECT id, item_name, item_code, price_per_unit FROM inventory ORDER BY item_name")->fetchAll(PDO::FETCH_ASSOC);
        $insurances = $pdo->query("SELECT id, plan_name, provider_name FROM insurance_plans ORDER BY provider_name")->fetchAll(PDO::FETCH_ASSOC);
        respond(['patients' => $patients, 'services' => $services, 'labs' => $labs, 'inventory' => $inventory, 'insurances' => $insurances]);
    }

    // Room charge calculation
    function calculateRoomCharges(PDO $pdo, $patientId): float {
        // Find most recent active admission for this patient
        $stmt = $pdo->prepare("SELECT a.id, a.admission_date, a.discharge_date, r.daily_rate FROM admissions a LEFT JOIN rooms r ON a.room_id = r.id WHERE a.patient_id = ? AND a.status = 'admitted' ORDER BY a.admission_date DESC LIMIT 1");
        $stmt->execute([$patientId]);
        $admission = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$admission) return 0.0;

        // Get transfers for this patient, oldest first
        $stmt2 = $pdo->prepare("SELECT t.transfer_date, fr.daily_rate AS from_rate, tr.daily_rate AS to_rate FROM room_transfers t LEFT JOIN rooms fr ON t.from_room_id = fr.id LEFT JOIN rooms tr ON t.to_room_id = tr.id WHERE t.patient_id = ? ORDER BY t.transfer_date ASC");
        $stmt2->execute([$patientId]);
        $transfers = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $total = 0.0;
        $endDate = $admission['discharge_date'] ? new DateTime($admission['discharge_date']) : new DateTime();
        $admissionDate = new DateTime($admission['admission_date']);

        if (empty($transfers)) {
            $dailyRate = (float)($admission['daily_rate'] ?? 0);
            $days = max(1, (int)ceil(($endDate->getTimestamp() - $admissionDate->getTimestamp()) / 86400));
            $total = $dailyRate * $days;
        } else {
            // First room: admission → first transfer
            $firstTransfer = new DateTime($transfers[0]['transfer_date']);
            $days = max(1, (int)ceil(($firstTransfer->getTimestamp() - $admissionDate->getTimestamp()) / 86400));
            $total += (float)$transfers[0]['from_rate'] * $days;

            // Intermediate rooms
            for ($i = 0; $i < count($transfers) - 1; $i++) {
                $from = new DateTime($transfers[$i]['transfer_date']);
                $to   = new DateTime($transfers[$i + 1]['transfer_date']);
                $days = max(1, (int)ceil(($to->getTimestamp() - $from->getTimestamp()) / 86400));
                $total += (float)$transfers[$i]['to_rate'] * $days;
            }

            // Last room: last transfer → end
            $last = $transfers[count($transfers) - 1];
            $lastDate = new DateTime($last['transfer_date']);
            $days = max(1, (int)ceil(($endDate->getTimestamp() - $lastDate->getTimestamp()) / 86400));
            $total += (float)$last['to_rate'] * $days;
        }

        return $total;
    }

    // Generate bill number
    function generateBillNumber(PDO $pdo): string {
        $pdo->exec("INSERT INTO counters (name, seq) VALUES ('bill_number', 1) ON DUPLICATE KEY UPDATE seq = seq + 1");
        $seq = (int)$pdo->query("SELECT seq FROM counters WHERE name='bill_number'")->fetchColumn();
        return 'INV' . str_pad($seq, 6, '0', STR_PAD_LEFT);
    }

    // Bills CRUD
    if ($resource === 'bills') {

        // GET all bills
        if ($method === 'GET' && !$id) {
            $search    = $_GET['search'] ?? '';
            $status    = $_GET['status'] ?? '';
            $startDate = $_GET['startDate'] ?? '';
            $endDate   = $_GET['endDate'] ?? '';

            $sql    = "SELECT * FROM bills WHERE 1=1";
            $params = [];

            if ($search) {
                $sql .= " AND (bill_number LIKE :s1 OR patient_name LIKE :s2 OR patient_id LIKE :s3)";
                $params[':s1'] = "%$search%";
                $params[':s2'] = "%$search%";
                $params[':s3'] = "%$search%";
            }
            if ($status && $status !== 'all') {
                $sql .= " AND payment_status = :status";
                $params[':status'] = $status;
            }
            if ($startDate) { $sql .= " AND created_at >= :sd"; $params[':sd'] = $startDate; }
            if ($endDate)   { $sql .= " AND created_at <= :ed"; $params[':ed'] = $endDate . ' 23:59:59'; }

            $sql .= " ORDER BY FIELD(payment_status,'unpaid','partial','paid'), created_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decode JSON columns
            foreach ($bills as &$b) {
                $b['laboratories']    = json_decode($b['laboratories'] ?? '[]', true) ?: [];
                $b['inventory_items'] = json_decode($b['inventory_items'] ?? '{}', true) ?: (object)[];
            }
            unset($b);
            respond($bills);
        }

        // GET single bill
        if ($method === 'GET' && $id) {
            $stmt = $pdo->prepare("SELECT * FROM bills WHERE id = ?");
            $stmt->execute([$id]);
            $bill = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$bill) respondError('Bill not found', 404);
            $bill['laboratories']    = json_decode($bill['laboratories'] ?? '[]', true) ?: [];
            $bill['inventory_items'] = json_decode($bill['inventory_items'] ?? '{}', true) ?: (object)[];
            respond($bill);
        }

        // POST create bill
        if ($method === 'POST') {
            $patientDbId  = $body['patientId'] ?? null;
            $serviceDbId  = $body['serviceId'] ?? null;

            if (!$patientDbId || !$serviceDbId) respondError('Patient and Service are required');

            // Fetch patient
            $stmt = $pdo->prepare("SELECT id, patient_id, full_name FROM patients WHERE id = ?");
            $stmt->execute([$patientDbId]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$patient) respondError('Patient not found', 404);

            // Fetch service
            $stmt = $pdo->prepare("SELECT id, service_name, base_price FROM services WHERE id = ?");
            $stmt->execute([$serviceDbId]);
            $service = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$service) respondError('Service not found', 404);

            $totalAmount = (float)$service['base_price'];

            // Add lab costs
            $labs = $body['laboratories'] ?? [];
            if (!empty($labs)) {
                $placeholders = implode(',', array_fill(0, count($labs), '?'));
                $stmt = $pdo->prepare("SELECT SUM(price) as total FROM lab_procedures WHERE id IN ($placeholders)");
                $stmt->execute($labs);
                $totalAmount += (float)$stmt->fetchColumn();
            }

            // Add inventory costs
            $inventoryItems = $body['inventoryItems'] ?? [];
            if (!empty($inventoryItems)) {
                foreach ($inventoryItems as $itemId => $qty) {
                    $stmt = $pdo->prepare("SELECT price_per_unit FROM inventory WHERE id = ?");
                    $stmt->execute([$itemId]);
                    $price = (float)$stmt->fetchColumn();
                    $totalAmount += $price * (int)$qty;
                }
            }

            // Room charges
            $roomCharge = calculateRoomCharges($pdo, $patientDbId);
            $totalAmount += $roomCharge;

            // Insurance deduction
            $insuranceDeduction = (float)($body['insuranceDeduction'] ?? 0);
            $totalAmount -= $insuranceDeduction;
            $totalAmount = max(0, $totalAmount);

            // Insurance provider name
            $insuranceProvider = 'None';
            $insuranceDbId = $body['insuranceId'] ?? null;
            if ($insuranceDbId) {
                $stmt = $pdo->prepare("SELECT provider_name, plan_name FROM insurance_plans WHERE id = ?");
                $stmt->execute([$insuranceDbId]);
                $ins = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($ins) $insuranceProvider = $ins['provider_name'] . ' - ' . $ins['plan_name'];
            }

            // Determine patient type
            $stmt = $pdo->prepare("SELECT id FROM admissions WHERE patient_id = ? AND status = 'admitted' LIMIT 1");
            $stmt->execute([$patientDbId]);
            $patientType = $stmt->fetchColumn() ? 'inpatient' : 'outpatient';

            $billNumber = generateBillNumber($pdo);
            $amountPaid = (float)($body['paidAmount'] ?? 0);
            $balance    = max(0, $totalAmount - $amountPaid);

            // balance‑zero => paid; no payment at all => unpaid; otherwise partial
            $paymentStatus = ($balance === 0.0)
                   ? 'paid'
                   : ($amountPaid > 0 ? 'partial' : 'unpaid');

            $stmt = $pdo->prepare("INSERT INTO bills (bill_number, patient_db_id, patient_id, patient_name, service_id, service_type, insurance_id, insurance_provider, insurance_deduction, laboratories, inventory_items, total_amount, amount_paid, balance, payment_status, payment_method, mpesa_phone, room_charge, patient_type, date_issued) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
            $stmt->execute([
                $billNumber,
                $patientDbId,
                $patient['patient_id'],
                $patient['full_name'],
                $serviceDbId,
                $service['service_name'],
                $insuranceDbId,
                $insuranceProvider,
                $insuranceDeduction,
                json_encode($labs),
                json_encode($inventoryItems),
                $totalAmount,
                $amountPaid,
                $balance,
                $paymentStatus,
                $body['paymentMethod'] ?? 'cash',
                ($body['paymentMethod'] ?? 'cash') === 'mpesa' ? ($body['mpesaPhone'] ?? null) : null,
                $roomCharge,
                $patientType
            ]);

            respond(['id' => $pdo->lastInsertId(), 'bill_number' => $billNumber], 201);
        }

        // PUT update bill
        if ($method === 'PUT' && $id) {
            $stmt = $pdo->prepare("SELECT * FROM bills WHERE id = ?");
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) respondError('Bill not found', 404);

            $serviceDbId = $body['serviceId'] ?? $existing['service_id'];

            // Fetch service
            $stmt = $pdo->prepare("SELECT service_name, base_price FROM services WHERE id = ?");
            $stmt->execute([$serviceDbId]);
            $service = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$service) respondError('Service not found', 404);

            $totalAmount = (float)$service['base_price'];

            // Labs
            $labs = $body['laboratories'] ?? [];
            if (!empty($labs)) {
                $placeholders = implode(',', array_fill(0, count($labs), '?'));
                $stmt = $pdo->prepare("SELECT SUM(price) as total FROM lab_procedures WHERE id IN ($placeholders)");
                $stmt->execute($labs);
                $totalAmount += (float)$stmt->fetchColumn();
            }

            // Inventory
            $inventoryItems = $body['inventoryItems'] ?? [];
            if (!empty($inventoryItems)) {
                foreach ($inventoryItems as $itemId => $qty) {
                    $stmt = $pdo->prepare("SELECT price_per_unit FROM inventory WHERE id = ?");
                    $stmt->execute([$itemId]);
                    $price = (float)$stmt->fetchColumn();
                    $totalAmount += $price * (int)$qty;
                }
            }

            // Room charges
            $roomCharge = calculateRoomCharges($pdo, $existing['patient_db_id']);
            $totalAmount += $roomCharge;

            // Insurance
            $insuranceDeduction = (float)($body['insuranceDeduction'] ?? 0);
            $totalAmount -= $insuranceDeduction;
            $totalAmount = max(0, $totalAmount);

            $insuranceProvider = 'None';
            $insuranceDbId = $body['insuranceId'] ?? null;
            if ($insuranceDbId) {
                $stmt = $pdo->prepare("SELECT provider_name, plan_name FROM insurance_plans WHERE id = ?");
                $stmt->execute([$insuranceDbId]);
                $ins = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($ins) $insuranceProvider = $ins['provider_name'] . ' - ' . $ins['plan_name'];
            }

            // Payments — existing paid + additional
            $existingPaid    = (float)($existing['amount_paid'] ?? 0);
            $additionalPaid  = (float)($body['paidAmount'] ?? 0);
            $totalPaid       = $existingPaid + $additionalPaid;
            $balance         = max(0, $totalAmount - $totalPaid);

            $paymentStatus = ($balance === 0.0)
                   ? 'paid'
                   : ($totalPaid > 0 ? 'partial' : 'unpaid');

            $stmt = $pdo->prepare("UPDATE bills SET service_id=?, service_type=?, insurance_id=?, insurance_provider=?, insurance_deduction=?, laboratories=?, inventory_items=?, total_amount=?, amount_paid=?, balance=?, payment_status=?, payment_method=?, mpesa_phone=?, room_charge=? WHERE id=?");
            $stmt->execute([
                $serviceDbId,
                $service['service_name'],
                $insuranceDbId,
                $insuranceProvider,
                $insuranceDeduction,
                json_encode($labs),
                json_encode($inventoryItems),
                $totalAmount,
                $totalPaid,
                $balance,
                $paymentStatus,
                $body['paymentMethod'] ?? $existing['payment_method'],
                ($body['paymentMethod'] ?? $existing['payment_method']) === 'mpesa' ? ($body['mpesaPhone'] ?? $existing['mpesa_phone']) : null,
                $roomCharge,
                $id
            ]);

            respond(['updated' => $id]);
        }

        // DELETE bill
        if ($method === 'DELETE' && $id) {
            $stmt = $pdo->prepare("DELETE FROM bills WHERE id = ?");
            $stmt->execute([$id]);
            respond(['deleted' => $id]);
        }
    }

    respondError('Not found', 404);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo $pageTitle; ?></title>
  <link rel="stylesheet" href="../assets/css/theme.css">
  <link rel="stylesheet" href="../assets/css/style.css">
  <link rel="stylesheet" href="../assets/css/sidebar.css">
  <link rel="stylesheet" href="../assets/css/billing.css">
  <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>

<?php include_once '../templates/sidebar.php'; ?>

<div class="main-content">
<div class="page">

  <!-- Header -->
  <div class="header">
    <div>
      <h1>Billing Records</h1>
      <p>Manage billing and payment records.</p>
    </div>
    <button class="btn-primary" onclick="openNewModal()">
      <i data-lucide="plus"></i> Add Bill
    </button>
  </div>

  <!-- Search + Filter bar -->
  <div class="filter-row">
    <div class="search-wrap">
      <i data-lucide="search" class="search-icon"></i>
      <input id="searchInput" type="text" placeholder="Search bills..." oninput="renderList()" />
    </div>
    <div class="filter-select-wrap">
      <i data-lucide="filter" class="search-icon"></i>
      <select id="statusFilter" onchange="renderList()">
        <option value="all">All Status</option>
        <option value="paid">Paid</option>
        <option value="partial">Partial</option>
        <option value="unpaid">Unpaid</option>
      </select>
    </div>
  </div>

  <!-- Bills list -->
  <div class="card" id="billsCard">
    <div class="state-msg">Loading billing records...</div>
  </div>

</div>
</div>

<div id="modal" class="modal-overlay" onclick="onOverlayClick(event)" style="display:none;">
  <div class="modal custom-scrollbar" id="modalBox">

    <div class="modal-header">
      <h2 id="modalTitle">New Bill</h2>
      <button class="modal-close" onclick="closeModal()"><i data-lucide="x"></i></button>
    </div>

    <!-- Patient selector (new only) -->
    <div class="field" id="patientField">
      <label>Patient Name *</label>
      <select id="f_patient" onchange="onPatientChange()">
        <option value="">Select Patient</option>
      </select>
    </div>

    <!-- Service + Insurance -->
    <div class="form-grid">
      <div class="field">
        <label>Service Type *</label>
        <select id="f_service" onchange="recalcSummary()">
          <option value="">Select Service</option>
        </select>
      </div>
      <div class="field">
        <label>Insurance Provider</label>
        <select id="f_insurance" onchange="recalcSummary()">
          <option value="">None</option>
        </select>
      </div>
    </div>

    <!-- Labs -->
    <div class="field">
      <label>Laboratory Tests</label>
      <select id="f_labs_picker" onchange="addLab(this)">
        <option value="">Add Lab Test</option>
      </select>
      <div id="selectedLabsWrap" class="tags-wrap"></div>
    </div>

    <!-- Inventory -->
    <div class="field">
      <label>Inventory Items</label>
      <select id="f_inv_picker" onchange="addInventory(this)">
        <option value="">Add Inventory Item</option>
      </select>
      <div id="selectedInvWrap" class="inv-wrap"></div>
    </div>

    <!-- Deduction + Amount Paid -->
    <div class="form-grid">
      <div class="field">
        <label>Insurance Deduction (KSh)</label>
        <input type="number" id="f_deduction" value="0" min="0" oninput="recalcSummary()" />
      </div>
      <div class="field">
        <label id="amountPaidLabel">Amount Paid (KSh)</label>
        <input type="number" id="f_amount_paid" value="0" min="0" oninput="recalcSummary()" />
      </div>
    </div>

    <!-- Payment method -->
    <div class="form-grid" id="paymentGrid">
      <div class="field">
        <label>Payment Method</label>
        <select id="f_payment_method" onchange="onPaymentMethodChange()">
          <option value="cash">Cash</option>
          <option value="mpesa">M-Pesa</option>
          <option value="card">Card</option>
          <option value="insurance">Insurance</option>
        </select>
      </div>
      <div class="field" id="mpesaField" style="display:none;">
        <label>M-Pesa Phone</label>
        <input type="text" id="f_mpesa_phone" placeholder="+254 700 000 000" />
      </div>
    </div>

    <!-- Bill summary -->
    <div class="summary-box">
      <h3>Bill Summary</h3>
      <div id="summaryContent"></div>
    </div>

    <div class="form-actions">
      <button class="btn-cancel" onclick="closeModal()">Cancel</button>
      <button class="btn-primary form-btn-primary" id="submitBtn" onclick="handleSubmit()">Save Bill</button>
    </div>

  </div>
</div>

<script src="../assets/js/theme.js"></script>
<script src="../assets/js/sidebar.js"></script>
<script src="../assets/js/main.js"></script>
<script>

const API = 'billing.php?api=1';
let bills        = [];
let lookupData   = { patients: [], services: [], labs: [], inventory: [], insurances: [] };
let editingId    = null;
let editingBill  = null;
let selectedLabs = {};
let selectedInv  = {};

async function init() {
  await Promise.all([fetchLookup(), fetchBills()]);
}

async function fetchLookup() {
  const res  = await fetch(`${API}&resource=lookup`);
  const json = await res.json();
  lookupData = json.data;
  populateSelects();
}

function populateSelects() {
  const { patients, services, labs, inventory, insurances } = lookupData;

  populateSelect('f_patient', patients, p => ({ value: p.id, label: `${p.patient_id} - ${p.full_name}` }));
  populateSelect('f_service', services, s => ({ value: s.id, label: `${s.service_name} - KSh ${parseFloat(s.base_price).toLocaleString()}` }));
  populateSelect('f_labs_picker', labs, l => ({ value: l.id, label: `${l.procedure_name} - KSh ${parseFloat(l.price).toLocaleString()}` }));
  populateSelect('f_inv_picker', inventory, i => ({ value: i.id, label: `${i.item_name} (${i.item_code})` }));
  populateSelect('f_insurance', insurances, ins => ({ value: ins.id, label: `${ins.provider_name} - ${ins.plan_name}` }));
}

function populateSelect(id, data, mapper) {
  const el = document.getElementById(id);
  const firstOption = el.options[0];
  el.innerHTML = '';
  el.appendChild(firstOption);
  data.forEach(item => {
    const { value, label } = mapper(item);
    const opt = document.createElement('option');
    opt.value = value;
    opt.textContent = label;
    el.appendChild(opt);
  });
}

async function fetchBills() {
  const res  = await fetch(`${API}&resource=bills`);
  const json = await res.json();
  bills = json.data || [];
  renderList();
}

function renderList() {
  const search = document.getElementById('searchInput').value.toLowerCase();
  const filter = document.getElementById('statusFilter').value;

  let filtered = bills.filter(b => {
    const matchSearch = (b.patient_name || '').toLowerCase().includes(search) || (b.bill_number || '').toLowerCase().includes(search);
    const matchFilter = filter === 'all' || b.payment_status === filter;
    return matchSearch && matchFilter;
  });

  // Sort: unpaid first, then partial, then paid
  const order = { unpaid: 0, partial: 1, paid: 2 };
  filtered.sort((a, b) => (order[a.payment_status] || 0) - (order[b.payment_status] || 0));

  const card = document.getElementById('billsCard');

  if (filtered.length === 0) {
    card.innerHTML = '<div class="state-msg">No bills found</div>';
    return;
  }

  card.innerHTML = filtered.map(b => {
    const balance   = Math.max(0, (parseFloat(b.total_amount) || 0) - (parseFloat(b.amount_paid) || 0));
    const statusCls = { paid: 'badge-green', partial: 'badge-yellow', unpaid: 'badge-red' }[b.payment_status] || 'badge-grey';
    const billJSON  = escapeJson(JSON.stringify(b));
    return `
    <div class="bill-row">
      <div class="bill-icon"><i data-lucide="file-text"></i></div>
      <div class="bill-info">
        <div class="bill-title-row">
          <h3>${b.patient_name || 'N/A'}</h3>
          <span class="bill-number">${b.bill_number || ''}</span>
        </div>
        <p class="sub">Payment: ${b.payment_method || 'N/A'} &bull; Insurance: ${b.insurance_provider || 'None'} &bull; Type: ${b.patient_type || 'outpatient'}</p>
      </div>
      <div class="bill-amounts">
        <div class="bill-total">KSh ${parseFloat(b.total_amount || 0).toLocaleString('en-KE', {minimumFractionDigits:2})}</div>
        <div class="bill-balance ${balance > 0 ? 'balance-due' : 'balance-clear'}">Balance: KSh ${balance.toLocaleString('en-KE', {minimumFractionDigits:2})}</div>
      </div>
      <span class="badge ${statusCls}">${(b.payment_status || 'unpaid').toUpperCase()}</span>
      <div class="row-actions">
        <button class="btn-edit" onclick='openEditModal(${billJSON})'>
          <i data-lucide="edit-2"></i> Edit
        </button>
        <button class="btn-print" onclick='printInvoice(${billJSON})'>
          <i data-lucide="printer"></i> Print
        </button>
      </div>
    </div>`;
  }).join('');

  lucide.createIcons();
}

function escapeJson(str) {
  return str.replace(/'/g, '&#39;');
}

function clearForm() {
  selectedLabs = {};
  selectedInv  = {};
  document.getElementById('f_patient').value        = '';
  document.getElementById('f_service').value        = '';
  document.getElementById('f_insurance').value      = '';
  document.getElementById('f_labs_picker').value    = '';
  document.getElementById('f_inv_picker').value     = '';
  document.getElementById('f_deduction').value      = '0';
  document.getElementById('f_amount_paid').value    = '0';
  document.getElementById('f_payment_method').value = 'cash';
  document.getElementById('f_mpesa_phone').value    = '';
  document.getElementById('mpesaField').style.display = 'none';
  renderSelectedLabs();
  renderSelectedInv();
  recalcSummary();
}

function openNewModal() {
  editingId   = null;
  editingBill = null;
  clearForm();
  document.getElementById('modalTitle').textContent        = 'New Bill';
  document.getElementById('submitBtn').textContent         = 'Save Bill';
  document.getElementById('patientField').style.display   = 'flex';
  document.getElementById('amountPaidLabel').textContent   = 'Amount Paid (KSh)';
  document.getElementById('modal').style.display = 'flex';
  lucide.createIcons();
}

function openEditModal(bill) {
  editingId   = bill.id;
  editingBill = bill;
  clearForm();

  document.getElementById('modalTitle').textContent       = `Edit Bill — ${bill.patient_name}`;
  document.getElementById('submitBtn').textContent        = 'Update Bill';
  document.getElementById('patientField').style.display  = 'none';
  document.getElementById('amountPaidLabel').textContent  = 'Additional Payment (KSh)';

  document.getElementById('f_service').value        = bill.service_id || '';
  document.getElementById('f_insurance').value      = bill.insurance_id || '';
  document.getElementById('f_deduction').value      = bill.insurance_deduction || '0';
  document.getElementById('f_amount_paid').value    = '0';
  document.getElementById('f_payment_method').value = bill.payment_method || 'cash';
  document.getElementById('f_mpesa_phone').value    = bill.mpesa_phone || '';
  document.getElementById('mpesaField').style.display = bill.payment_method === 'mpesa' ? 'flex' : 'none';

  // Pre-fill labs
  const labIds = Array.isArray(bill.laboratories) ? bill.laboratories : [];
  labIds.forEach(labId => {
    const lab = lookupData.labs.find(l => l.id == labId);
    if (lab) selectedLabs[lab.id] = { id: lab.id, name: lab.procedure_name, price: parseFloat(lab.price) };
  });

  // Pre-fill inventory
  const invItems = typeof bill.inventory_items === 'object' ? bill.inventory_items : {};
  Object.entries(invItems).forEach(([itemId, qty]) => {
    const item = lookupData.inventory.find(i => i.id == itemId);
    if (item) selectedInv[item.id] = { id: item.id, name: item.item_name, pricePerUnit: parseFloat(item.price_per_unit), qty: parseInt(qty) || 1 };
  });

  renderSelectedLabs();
  renderSelectedInv();
  recalcSummary();

  document.getElementById('modal').style.display = 'flex';
  lucide.createIcons();
}

function closeModal() {
  document.getElementById('modal').style.display = 'none';
  editingId = null;
  editingBill = null;
}

function onOverlayClick(e) { if (e.target === e.currentTarget) closeModal(); }

function addLab(select) {
  const labId = select.value;
  if (!labId) return;
  const lab = lookupData.labs.find(l => l.id == labId);
  if (lab && !selectedLabs[lab.id]) {
    selectedLabs[lab.id] = { id: lab.id, name: lab.procedure_name, price: parseFloat(lab.price) };
    renderSelectedLabs();
    recalcSummary();
  }
  select.value = '';
}

function removeLab(id) {
  delete selectedLabs[id];
  renderSelectedLabs();
  recalcSummary();
}

function renderSelectedLabs() {
  const wrap = document.getElementById('selectedLabsWrap');
  const entries = Object.values(selectedLabs);
  if (entries.length === 0) { wrap.innerHTML = ''; return; }
  wrap.innerHTML = entries.map(l => `
    <span class="tag tag-blue">
      ${l.name}
      <button onclick="removeLab('${l.id}')"><i data-lucide="x" style="width:12px;height:12px;"></i></button>
    </span>`).join('');
  lucide.createIcons();
}

function addInventory(select) {
  const itemId = select.value;
  if (!itemId) return;
  const item = lookupData.inventory.find(i => i.id == itemId);
  if (item && !selectedInv[item.id]) {
    selectedInv[item.id] = { id: item.id, name: item.item_name, pricePerUnit: parseFloat(item.price_per_unit), qty: 1 };
    renderSelectedInv();
    recalcSummary();
  }
  select.value = '';
}

function removeInv(id) {
  delete selectedInv[id];
  renderSelectedInv();
  recalcSummary();
}

function updateInvQty(id, qty) {
  if (selectedInv[id]) {
    selectedInv[id].qty = Math.max(1, parseInt(qty) || 1);
    recalcSummary();
  }
}

function renderSelectedInv() {
  const wrap = document.getElementById('selectedInvWrap');
  const entries = Object.values(selectedInv);
  if (entries.length === 0) { wrap.innerHTML = ''; return; }
  wrap.innerHTML = entries.map(item => `
    <div class="inv-item tag-yellow">
      <span>${item.name}</span>
      <div class="inv-controls">
        <input type="number" min="1" value="${item.qty}" onchange="updateInvQty('${item.id}', this.value)" />
        <button onclick="removeInv('${item.id}')"><i data-lucide="x" style="width:12px;height:12px;"></i></button>
      </div>
    </div>`).join('');
  lucide.createIcons();
}

function recalcSummary() {
  let serviceAmount = 0, labsAmount = 0, invAmount = 0, roomChargeAmount = 0;

  const serviceId = document.getElementById('f_service').value;
  const service   = lookupData.services.find(s => s.id == serviceId);
  if (service) serviceAmount = parseFloat(service.base_price);

  Object.values(selectedLabs).forEach(l => { labsAmount += l.price; });
  Object.values(selectedInv).forEach(i => { invAmount += i.pricePerUnit * i.qty; });

  // Room charge from existing bill (edit mode)
  if (editingBill) roomChargeAmount = parseFloat(editingBill.room_charge || 0);

  const subtotal          = serviceAmount + labsAmount + invAmount + roomChargeAmount;
  const deduction         = Math.max(0, parseFloat(document.getElementById('f_deduction').value) || 0);
  const totalAfterIns     = Math.max(0, subtotal - deduction);
  const existingPaid      = editingBill ? (parseFloat(editingBill.amount_paid) || 0) : 0;
  const additionalPayment = parseFloat(document.getElementById('f_amount_paid').value) || 0;
  const totalPaid         = existingPaid + additionalPayment;
  const balance           = Math.max(0, totalAfterIns - totalPaid);

  let html = '';
  if (serviceAmount > 0) html += summaryRow('Service', serviceAmount);
  if (roomChargeAmount > 0) html += summaryRow('Room Charges', roomChargeAmount, true);
  if (labsAmount > 0) html += summaryRow('Lab Tests', labsAmount);
  if (invAmount > 0) html += summaryRow('Items', invAmount);
  html += `<div class="summary-row summary-subtotal"><span>Subtotal</span><span>KSh ${summaryNum(subtotal)}</span></div>`;
  if (deduction > 0) html += `<div class="summary-row text-red"><span>Insurance Deduction</span><span>- KSh ${summaryNum(deduction)}</span></div>`;
  html += `<div class="summary-row"><span style="color:var(--text-secondary)">Total After Insurance</span><span>KSh ${summaryNum(totalAfterIns)}</span></div>`;
  if (existingPaid > 0) html += `<div class="summary-row text-green"><span>Previous Payments</span><span>- KSh ${summaryNum(existingPaid)}</span></div>`;
  if (additionalPayment > 0) html += `<div class="summary-row text-green"><span>Additional Payment</span><span>- KSh ${summaryNum(additionalPayment)}</span></div>`;
  html += `<div class="summary-row summary-balance ${balance === 0 ? 'balance-clear' : 'balance-due'}"><span>BALANCE DUE</span><span>KSh ${summaryNum(balance)}</span></div>`;

  document.getElementById('summaryContent').innerHTML = html;
}

function summaryRow(label, amount, topBorder = false) {
  return `<div class="summary-row${topBorder ? ' top-dashed' : ''}"><span style="color:var(--text-secondary)">${label}</span><span>KSh ${summaryNum(amount)}</span></div>`;
}

function summaryNum(n) { return parseFloat(n || 0).toLocaleString('en-KE', {minimumFractionDigits:0}); }

function onPatientChange() { recalcSummary(); }

function onPaymentMethodChange() {
  const method = document.getElementById('f_payment_method').value;
  document.getElementById('mpesaField').style.display = method === 'mpesa' ? 'flex' : 'none';
}


async function handleSubmit() {
  const serviceId = document.getElementById('f_service').value;
  if (!serviceId) { alert('Please select a service'); return; }

  const paymentMethod = document.getElementById('f_payment_method').value;
  if (paymentMethod === 'mpesa' && !document.getElementById('f_mpesa_phone').value.trim()) {
    alert('Please enter an M-Pesa phone number'); return;
  }

  const payload = {
    serviceId,
    insuranceId: document.getElementById('f_insurance').value || null,
    insuranceDeduction: parseFloat(document.getElementById('f_deduction').value) || 0,
    laboratories: Object.keys(selectedLabs),
    inventoryItems: Object.fromEntries(Object.values(selectedInv).map(i => [i.id, i.qty])),
    paidAmount: parseFloat(document.getElementById('f_amount_paid').value) || 0,
    paymentMethod,
    mpesaPhone: paymentMethod === 'mpesa' ? document.getElementById('f_mpesa_phone').value : null,
  };

  if (!editingId) {
    const patientId = document.getElementById('f_patient').value;
    if (!patientId) { alert('Please select a patient'); return; }
    payload.patientId = patientId;
  }

  try {
    if (editingId) {
      await fetch(`${API}&resource=bills&id=${editingId}`, { method: 'PUT', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
    } else {
      await fetch(`${API}&resource=bills`, { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
    }
    closeModal();
    await fetchBills();
  } catch (err) {
    console.error(err);
    alert('Failed to save bill');
  }
}

function printInvoice(bill) {
  const balance      = Math.max(0, (parseFloat(bill.total_amount) || 0) - (parseFloat(bill.amount_paid) || 0));
  const servicePrice = (() => {
    const svc = lookupData.services.find(s => s.service_name === bill.service_type);
    return svc ? parseFloat(svc.base_price) : 0;
  })();

  const labRows = Array.isArray(bill.laboratories) ? bill.laboratories.map(id => {
    const lab = lookupData.labs.find(l => l.id == id);
    return lab ? `<div class="item-row"><span class="item-name">${lab.procedure_name}</span><span class="item-price">KSh ${parseFloat(lab.price).toLocaleString()}</span></div>` : '';
  }).join('') : '';

  const invRows = typeof bill.inventory_items === 'object' ? Object.entries(bill.inventory_items).map(([id, qty]) => {
    const item = lookupData.inventory.find(i => i.id == id);
    return item ? `<div class="item-row"><span class="item-name">${qty}x ${item.item_name}</span><span class="item-price">KSh ${(parseFloat(item.price_per_unit) * qty).toLocaleString()}</span></div>` : '';
  }).join('') : '';

  const w = window.open('', '', 'width=340,height=700');
  if (!w) return;
  w.document.write(`<!DOCTYPE html><html><head><title>Invoice ${bill.bill_number}</title><style>
    @media print { @page { size: 80mm auto; margin: 0; } body { margin: 0; padding: 0; } }
    body { font-family: 'Courier New', monospace; width: 80mm; margin: 0 auto; padding: 10mm; font-size: 11px; line-height: 1.4; background: white; }
    .header { text-align: center; margin-bottom: 15px; border-bottom: 2px dashed #000; padding-bottom: 10px; }
    .hospital-name { font-size: 16px; font-weight: bold; margin-bottom: 3px; }
    .hospital-info { font-size: 9px; color: #333; }
    .info-row { display: flex; justify-content: space-between; margin: 3px 0; }
    .value { font-weight: bold; }
    .items { margin: 10px 0; border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 8px 0; }
    .section-title { font-weight: bold; margin-bottom: 5px; font-size: 12px; }
    .item-row { display: flex; justify-content: space-between; margin: 4px 0; }
    .item-name { flex: 1; }
    .item-price { text-align: right; font-weight: bold; }
    .total-row { display: flex; justify-content: space-between; margin: 5px 0; }
    .grand-total { border-top: 2px solid #000; padding-top: 8px; margin-top: 8px; font-size: 13px; }
    .footer { text-align: center; margin-top: 15px; padding-top: 10px; border-top: 2px dashed #000; font-size: 9px; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 9px; font-weight: bold; margin-top: 5px; }
    .paid { background: #dcfce7; color: #166534; } .unpaid { background: #fee2e2; color: #991b1b; } .partial { background: #fef3c7; color: #92400e; }
  </style></head><body>
    <div class="header">
      <div class="hospital-name">ARAWA HOSPITAL</div>
      <div class="hospital-info">Arawa, Mombasa</div>
    </div>
    <div style="margin:12px 0">
      <div class="info-row"><span>Invoice #:</span><span class="value">${bill.bill_number || 'N/A'}</span></div>
      <div class="info-row"><span>Date:</span><span class="value">${bill.date_issued ? new Date(bill.date_issued).toLocaleDateString() : 'N/A'}</span></div>
      <div class="info-row"><span>Patient:</span><span class="value">${bill.patient_name || 'N/A'}</span></div>
      <div class="info-row"><span>Patient ID:</span><span class="value">${bill.patient_id || 'N/A'}</span></div>
      <div class="info-row"><span>Type:</span><span class="value">${(bill.patient_type || 'outpatient').toUpperCase()}</span></div>
    </div>
    <div class="items">
      <div class="section-title">SERVICES</div>
      ${bill.service_type ? `<div class="item-row"><span class="item-name">${bill.service_type}</span><span class="item-price">KSh ${servicePrice.toLocaleString()}</span></div>` : ''}
      ${parseFloat(bill.room_charge) > 0 ? `<div class="item-row"><span class="item-name">Room Charges</span><span class="item-price">KSh ${parseFloat(bill.room_charge).toLocaleString()}</span></div>` : ''}
      ${labRows ? `<div style="margin-top:8px"><div class="section-title">LAB TESTS</div>${labRows}</div>` : ''}
      ${invRows ? `<div style="margin-top:8px"><div class="section-title">ITEMS</div>${invRows}</div>` : ''}
    </div>
    <div style="margin-top:10px">
      ${parseFloat(bill.insurance_deduction) > 0 ? `<div class="total-row"><span>Insurance Deduction:</span><span>- KSh ${parseFloat(bill.insurance_deduction).toLocaleString()}</span></div>` : ''}
      <div class="total-row"><span>Subtotal:</span><span class="value">KSh ${parseFloat(bill.total_amount || 0).toLocaleString()}</span></div>
      <div class="total-row"><span>Amount Paid:</span><span class="value">KSh ${parseFloat(bill.amount_paid || 0).toLocaleString()}</span></div>
      <div class="total-row grand-total"><span>BALANCE DUE:</span><span class="value">KSh ${balance.toLocaleString()}</span></div>
    </div>
    <div style="margin:12px 0">
      <div class="info-row"><span>Payment Method:</span><span class="value">${(bill.payment_method || 'cash').toUpperCase()}</span></div>
      ${bill.mpesa_phone ? `<div class="info-row"><span>M-Pesa Phone:</span><span class="value">${bill.mpesa_phone}</span></div>` : ''}
      ${bill.insurance_provider && bill.insurance_provider !== 'None' ? `<div class="info-row"><span>Insurance:</span><span class="value">${bill.insurance_provider}</span></div>` : ''}
      <div style="text-align:center"><span class="badge ${bill.payment_status || 'unpaid'}">${(bill.payment_status || 'UNPAID').toUpperCase()}</span></div>
    </div>
    <div class="footer"><strong>Thank you for choosing us!</strong><br>Get well soon</div>
  </body></html>`);
  w.document.close();
  w.focus();
  setTimeout(() => { w.print(); w.close(); }, 250);
}

init();
</script>
</body>
</html>