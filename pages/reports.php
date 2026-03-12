<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireLogin();

if (!validateSession($pdo)) {
    destroySession($pdo);
    header('Location: /auth/login.php');
    exit;
}

$pageTitle = 'Reports & Analytics - Arawa Hospital';

if (isset($_GET['api'])) {
    header('Content-Type: application/json');

    $resource = $_GET['resource'] ?? '';

    function respond($data) { echo json_encode(['data' => $data]); exit; }

    switch ($resource) {

        case 'analytics':
            // Department stats from appointments
            $deptStats = [];
            $stmt = $pdo->query("SELECT department, COUNT(*) as patients FROM appointments WHERE department IS NOT NULL AND department != '' GROUP BY department");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $deptStats[$row['department']] = ['name' => $row['department'], 'patients' => (int)$row['patients'], 'revenue' => 0, 'admissions' => 0];
            }
            // Revenue per dept from bills
            $stmt = $pdo->query("SELECT service_type, SUM(paid_amount) as revenue FROM bills WHERE service_type IS NOT NULL GROUP BY service_type");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = $row['service_type'] ?: 'General';
                if (!isset($deptStats[$key])) $deptStats[$key] = ['name' => $key, 'patients' => 0, 'revenue' => 0, 'admissions' => 0];
                $deptStats[$key]['revenue'] = (float)$row['revenue'];
            }
            // Admissions count
            $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM admissions");
            $admCount = (int)$stmt->fetchColumn();
            if (!isset($deptStats['Inpatient'])) $deptStats['Inpatient'] = ['name' => 'Inpatient', 'patients' => 0, 'revenue' => 0, 'admissions' => 0];
            $deptStats['Inpatient']['admissions'] = $admCount;

            // Monthly revenue (last 6 months)
            $monthlyRevenue = [];
            for ($i = 5; $i >= 0; $i--) {
                $month = date('M', strtotime("-$i months"));
                $year  = date('Y', strtotime("-$i months"));
                $m     = date('m', strtotime("-$i months"));
                $stmt  = $pdo->prepare("SELECT COALESCE(SUM(paid_amount),0) as revenue FROM bills WHERE YEAR(created_at)=? AND MONTH(created_at)=?");
                $stmt->execute([$year, $m]);
                $rev = (float)$stmt->fetchColumn();
                $monthlyRevenue[] = ['month' => $month, 'revenue' => $rev, 'expenses' => $rev * 0.65, 'profit' => $rev * 0.35];
            }

            // Patient demographics by gender
            $stmt = $pdo->query("SELECT gender, COUNT(*) as cnt FROM patients WHERE gender IS NOT NULL GROUP BY gender");
            $colors = ['#0ea5e9','#60a5fa','#93c5fd','#dbeafe'];
            $demographics = [];
            $i = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $demographics[] = ['name' => $row['gender'], 'value' => (int)$row['cnt'], 'fill' => $colors[$i++ % count($colors)]];
            }

            // Appointment stats by month (last 6)
            $aptStats = [];
            for ($i = 5; $i >= 0; $i--) {
                $month = date('M', strtotime("-$i months"));
                $year  = date('Y', strtotime("-$i months"));
                $m     = date('m', strtotime("-$i months"));
                $stmt  = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM appointments WHERE YEAR(date)=? AND MONTH(date)=? GROUP BY status");
                $stmt->execute([$year, $m]);
                $row = ['name' => $month, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) { if (isset($row[$s['status']])) $row[$s['status']] = (int)$s['cnt']; }
                $aptStats[] = $row;
            }

            // Transactions from bills
            $stmt = $pdo->query("SELECT b.id, b.bill_number, p.full_name as patient_name, p.patient_id, b.paid_amount, b.payment_method, b.service_type, b.created_at FROM bills b LEFT JOIN patients p ON b.patient_id=p.id WHERE b.paid_amount > 0 ORDER BY b.created_at DESC LIMIT 100");
            $transactions = array_map(fn($r) => [
                'id' => $r['id'], 'transactionDate' => $r['created_at'], 'transactionType' => 'Payment',
                'patientName' => $r['patient_name'], 'patientId' => $r['patient_id'],
                'amount' => (float)$r['paid_amount'], 'paymentMethod' => $r['payment_method'] ?: 'Cash',
                'billNumber' => $r['bill_number'], 'description' => 'Payment for ' . ($r['service_type'] ?: 'Medical Services')
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));

            // Insurance claims from bills
            $stmt = $pdo->query("SELECT b.id, b.bill_number, p.full_name as patient_name, p.patient_id, b.insurance_provider, b.insurance_deduction, b.created_at FROM bills b LEFT JOIN patients p ON b.patient_id=p.id WHERE b.insurance_id IS NOT NULL AND b.insurance_deduction > 0 ORDER BY b.created_at DESC");
            $claims = array_map(fn($r) => [
                'id' => $r['id'], 'patientName' => $r['patient_name'], 'patientId' => $r['patient_id'],
                'insuranceProvider' => $r['insurance_provider'] ?: 'Unknown', 'claimAmount' => (float)$r['insurance_deduction'],
                'claimDate' => $r['created_at'], 'status' => 'Approved', 'billNumber' => $r['bill_number']
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));

            // System logs
            $stmt = $pdo->query("SELECT * FROM system_logs ORDER BY timestamp DESC LIMIT 100");
            $logs = array_map(fn($r) => [
                'id' => $r['id'], 'timestamp' => $r['timestamp'], 'action' => $r['action'],
                'module' => $r['module'], 'user' => $r['user'] ?: 'System',
                'details' => $r['details'], 'ipAddress' => $r['ip_address'] ?: '127.0.0.1', 'status' => $r['status'] ?: 'success'
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));

            respond([
                'departmentStats'    => array_values($deptStats),
                'monthlyRevenue'     => $monthlyRevenue,
                'patientDemographics'=> $demographics,
                'appointmentStats'   => $aptStats,
                'transactions'       => $transactions,
                'insuranceClaims'    => $claims,
                'systemLogs'         => $logs,
            ]);
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo $pageTitle; ?></title>
  <link rel="stylesheet" href="../../assets/css/theme.css">
  <link rel="stylesheet" href="../../assets/css/style.css">
  <link rel="stylesheet" href="../../assets/css/sidebar.css">
  <link rel="stylesheet" href="../../assets/css/reports.css">
  <script src="https://unpkg.com/lucide@latest"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>

<?php include_once '../templates/sidebar.php'; ?>

<div class="main-content">
<div class="page">

  <!-- Header -->
  <div class="rpt-header">
    <div>
      <h1>Reports & Analytics</h1>
      <div class="rpt-updated">
        <i data-lucide="clock" class="rpt-icon-sm"></i>
        <span id="lastUpdated">Last updated: --:--:--</span>
      </div>
    </div>
    <div class="rpt-header-actions">
      <button class="btn-refresh" onclick="handleRefresh()">
        <i data-lucide="refresh-cw" id="refreshIcon"></i> Refresh
      </button>
      <select id="periodSelect" class="period-select">
        <option value="7days">Last 7 Days</option>
        <option value="30days" selected>Last 30 Days</option>
        <option value="90days">Last 90 Days</option>
        <option value="year">Last Year</option>
      </select>
    </div>
  </div>

  <!-- Charts Grid -->
  <div class="charts-grid">
    <!-- Department Performance -->
    <div class="chart-card">
      <div class="chart-card-header">
        <h2>Department Performance</h2>
        <button class="btn-download" onclick="downloadCSV('department')">
          <i data-lucide="download"></i> Download
        </button>
      </div>
      <canvas id="deptChart" height="240"></canvas>
    </div>

    <!-- Patient Demographics -->
    <div class="chart-card">
      <h2>Patient Demographics</h2>
      <canvas id="demoChart" height="240"></canvas>
    </div>

    <!-- Monthly Revenue -->
    <div class="chart-card chart-full">
      <h2>Revenue & Profit Trends (Last 6 Months)</h2>
      <canvas id="revenueChart" height="200"></canvas>
    </div>

    <!-- Appointment Trends -->
    <div class="chart-card chart-full">
      <div class="chart-card-header">
        <h2>Appointment Trends</h2>
        <button class="btn-download" onclick="downloadCSV('appointment')">
          <i data-lucide="download"></i> Download
        </button>
      </div>
      <canvas id="aptChart" height="200"></canvas>
    </div>
  </div>

  <!-- Tabbed Reports -->
  <div class="tab-card">
    <div class="tab-bar">
      <button class="tab-btn active" onclick="setTab('transactions', this)">
        <i data-lucide="activity"></i> Financial Transactions
      </button>
      <button class="tab-btn" onclick="setTab('insurance', this)">
        <i data-lucide="shield"></i> Insurance Claims
      </button>
      <button class="tab-btn" onclick="setTab('logs', this)">
        <i data-lucide="file-text"></i> System Logs
      </button>
    </div>

    <!-- Transactions Tab -->
    <div id="tab-transactions" class="tab-panel">
      <div class="tab-panel-header">
        <div>
          <h2>Financial Transactions Report</h2>
          <div class="tab-stats" id="txnStats"></div>
        </div>
        <div class="tab-panel-actions">
          <div class="date-range">
            <i data-lucide="calendar" class="rpt-icon-sm"></i>
            <input type="date" id="startDate" class="date-input" />
            <span>to</span>
            <input type="date" id="endDate" class="date-input" />
          </div>
          <button class="btn-dl-green" onclick="downloadCSV('financial')">
            <i data-lucide="download"></i> Download Report
          </button>
        </div>
      </div>
      <div class="table-wrap">
        <table id="txnTable">
          <thead><tr>
            <th>Transaction Date</th><th>Type</th><th>Patient Name</th>
            <th>Patient ID</th><th>Bill Number</th><th>Payment Method</th>
            <th>Amount</th><th>Description</th>
          </tr></thead>
          <tbody id="txnBody"></tbody>
        </table>
        <div class="empty-msg" id="txnEmpty" style="display:none">No transactions found</div>
      </div>
    </div>

    <!-- Insurance Tab -->
    <div id="tab-insurance" class="tab-panel" style="display:none">
      <div class="tab-panel-header">
        <div>
          <h2>Insurance Claims Report</h2>
          <div class="tab-stats" id="insStats"></div>
        </div>
        <button class="btn-dl-purple" onclick="downloadCSV('insurance')">
          <i data-lucide="download"></i> Download Report
        </button>
      </div>
      <div class="table-wrap">
        <table id="insTable">
          <thead><tr>
            <th>Claim Date</th><th>Patient Name</th><th>Patient ID</th>
            <th>Insurance Provider</th><th>Bill Number</th><th>Claim Amount</th><th>Status</th>
          </tr></thead>
          <tbody id="insBody"></tbody>
        </table>
        <div class="empty-msg" id="insEmpty" style="display:none">No insurance claims found</div>
      </div>
    </div>

    <!-- Logs Tab -->
    <div id="tab-logs" class="tab-panel" style="display:none">
      <div class="tab-panel-header">
        <div>
          <h2>System Activity Logs</h2>
          <div class="tab-stats" id="logsStats"></div>
        </div>
        <button class="btn-dl-blue" onclick="downloadCSV('logs')">
          <i data-lucide="download"></i> Download Logs
        </button>
      </div>
      <div class="table-wrap">
        <table id="logsTable">
          <thead><tr>
            <th>Timestamp</th><th>Action</th><th>Module</th>
            <th>User</th><th>Details</th><th>IP Address</th><th>Status</th>
          </tr></thead>
          <tbody id="logsBody"></tbody>
        </table>
        <div class="empty-msg" id="logsEmpty" style="display:none">No system logs found</div>
      </div>
    </div>
  </div>

</div>
</div>

<script src="../assets/js/theme.js"></script>
<script src="../assets/js/sidebar.js"></script>
<script src="../assets/js/main.js"></script>
<script>
const API = 'reports.php?api=1&resource=analytics';
let data = { departmentStats:[], monthlyRevenue:[], patientDemographics:[], appointmentStats:[], transactions:[], insuranceClaims:[], systemLogs:[] };
let charts = {};

function formatCurrency(n) {
  return new Intl.NumberFormat('en-KE', { style:'currency', currency:'KES', minimumFractionDigits:0, maximumFractionDigits:0 }).format(n);
}
function formatDate(s) { return new Date(s).toLocaleDateString('en-US', { year:'numeric', month:'short', day:'numeric' }); }
function formatDateTime(s) { return new Date(s).toLocaleString('en-US', { year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit', second:'2-digit' }); }

function getChartColors() {
  const style = getComputedStyle(document.documentElement);
  return {
    grid: style.getPropertyValue('--border-color').trim() || '#334155',
    text: style.getPropertyValue('--text-secondary').trim() || '#94a3b8',
    surface: style.getPropertyValue('--surface-color').trim() || '#1e293b',
  };
}

async function fetchData() {
  const res = await fetch(API);
  const json = await res.json();
  data = json.data;
  renderCharts();
  renderTables();
  document.getElementById('lastUpdated').textContent = 'Last updated: ' + new Date().toLocaleTimeString();
}

function renderCharts() {
  const c = getChartColors();
  const chartDefaults = { color: c.text };

  // Destroy old charts
  Object.values(charts).forEach(ch => ch.destroy());
  charts = {};

  // Department bar chart
  charts.dept = new Chart(document.getElementById('deptChart'), {
    type: 'bar',
    data: {
      labels: data.departmentStats.map(d => d.name),
      datasets: [
        { label: 'Patients', data: data.departmentStats.map(d => d.patients), backgroundColor: '#0ea5e9', borderRadius: 8 },
        { label: 'Admissions', data: data.departmentStats.map(d => d.admissions), backgroundColor: '#8b5cf6', borderRadius: 8 }
      ]
    },
    options: { responsive: true, plugins: { legend: { labels: { color: c.text, font: { size: 12 } } } }, scales: { x: { ticks: { color: c.text, font: { size: 11 } }, grid: { color: c.grid } }, y: { ticks: { color: c.text, font: { size: 11 } }, grid: { color: c.grid } } } }
  });

  // Demographics donut
  charts.demo = new Chart(document.getElementById('demoChart'), {
    type: 'doughnut',
    data: {
      labels: data.patientDemographics.map(d => d.name),
      datasets: [{ data: data.patientDemographics.map(d => d.value), backgroundColor: data.patientDemographics.map(d => d.fill), borderWidth: 2 }]
    },
    options: { responsive: true, plugins: { legend: { labels: { color: c.text, font: { size: 12 } } } } }
  });

  // Revenue line chart
  charts.revenue = new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
      labels: data.monthlyRevenue.map(m => m.month),
      datasets: [
        { label: 'Revenue', data: data.monthlyRevenue.map(m => m.revenue), borderColor: '#0ea5e9', borderWidth: 2.5, tension: 0.3, fill: false },
        { label: 'Expenses', data: data.monthlyRevenue.map(m => m.expenses), borderColor: '#ef4444', borderWidth: 2.5, tension: 0.3, fill: false },
        { label: 'Profit', data: data.monthlyRevenue.map(m => m.profit), borderColor: '#10b981', borderWidth: 2.5, tension: 0.3, fill: false }
      ]
    },
    options: { responsive: true, plugins: { legend: { labels: { color: c.text, font: { size: 12 } } } }, scales: { x: { ticks: { color: c.text, font: { size: 11 } }, grid: { color: c.grid } }, y: { ticks: { color: c.text, font: { size: 11 } }, grid: { color: c.grid } } } }
  });

  // Appointment bar chart
  charts.apt = new Chart(document.getElementById('aptChart'), {
    type: 'bar',
    data: {
      labels: data.appointmentStats.map(a => a.name),
      datasets: [
        { label: 'Confirmed', data: data.appointmentStats.map(a => a.confirmed), backgroundColor: '#0ea5e9', borderRadius: 8 },
        { label: 'Completed', data: data.appointmentStats.map(a => a.completed), backgroundColor: '#10b981', borderRadius: 8 },
        { label: 'Cancelled', data: data.appointmentStats.map(a => a.cancelled), backgroundColor: '#ef4444', borderRadius: 8 }
      ]
    },
    options: { responsive: true, plugins: { legend: { labels: { color: c.text, font: { size: 12 } } } }, scales: { x: { ticks: { color: c.text, font: { size: 11 } }, grid: { color: c.grid } }, y: { ticks: { color: c.text, font: { size: 11 } }, grid: { color: c.grid } } } }
  });
}

function renderTables() {
  const totalRevenue = data.monthlyRevenue.reduce((s, m) => s + m.revenue, 0);
  const totalClaims = data.insuranceClaims.reduce((s, c) => s + c.claimAmount, 0);
  const totalPatients = data.patientDemographics.reduce((s, p) => s + p.value, 0);

  document.getElementById('txnStats').innerHTML = `
    <span><i data-lucide="trending-up" class="stat-icon green"></i>Total Revenue: <strong>${formatCurrency(totalRevenue)}</strong></span>
    <span><i data-lucide="activity" class="stat-icon blue"></i>Transactions: <strong>${data.transactions.length}</strong></span>
    <span><i data-lucide="users" class="stat-icon purple"></i>Total Patients: <strong>${totalPatients}</strong></span>`;

  document.getElementById('insStats').innerHTML = `
    <span><i data-lucide="shield" class="stat-icon purple"></i>Total Claims: <strong>${formatCurrency(totalClaims)}</strong></span>
    <span><i data-lucide="activity" class="stat-icon green"></i>Approved Claims: <strong>${data.insuranceClaims.length}</strong></span>`;

  document.getElementById('logsStats').innerHTML = `
    <span><i data-lucide="file-text" class="stat-icon blue"></i>Total Logs: <strong>${data.systemLogs.length}</strong></span>`;

  // Transactions table
  const startDate = document.getElementById('startDate').value;
  const endDate   = document.getElementById('endDate').value;
  const txns = data.transactions.filter(t => {
    const d = new Date(t.transactionDate);
    if (startDate && d < new Date(startDate)) return false;
    if (endDate && d > new Date(endDate)) return false;
    return true;
  }).slice(0, 50);

  document.getElementById('txnBody').innerHTML = txns.map(t => `
    <tr>
      <td>${formatDate(t.transactionDate)}</td>
      <td><span class="badge badge-blue">${t.transactionType}</span></td>
      <td class="text-primary fw">${t.patientName}</td>
      <td class="text-secondary">${t.patientId}</td>
      <td class="text-secondary">${t.billNumber}</td>
      <td class="text-secondary">${t.paymentMethod}</td>
      <td class="text-green fw">${formatCurrency(t.amount)}</td>
      <td class="text-secondary">${t.description}</td>
    </tr>`).join('');
  document.getElementById('txnEmpty').style.display = txns.length ? 'none' : 'block';

  // Insurance table
  document.getElementById('insBody').innerHTML = data.insuranceClaims.map(c => `
    <tr>
      <td>${formatDate(c.claimDate)}</td>
      <td class="text-primary fw">${c.patientName}</td>
      <td class="text-secondary">${c.patientId}</td>
      <td class="text-secondary">${c.insuranceProvider}</td>
      <td class="text-secondary">${c.billNumber}</td>
      <td class="text-purple fw">${formatCurrency(c.claimAmount)}</td>
      <td><span class="badge ${c.status==='Approved'?'badge-green':c.status==='Pending'?'badge-yellow':'badge-red'}">${c.status}</span></td>
    </tr>`).join('');
  document.getElementById('insEmpty').style.display = data.insuranceClaims.length ? 'none' : 'block';

  // Logs table
  document.getElementById('logsBody').innerHTML = data.systemLogs.slice(0, 100).map(l => `
    <tr>
      <td class="text-primary">${formatDateTime(l.timestamp)}</td>
      <td class="text-primary fw">${l.action}</td>
      <td class="text-secondary">${l.module}</td>
      <td class="text-secondary">${l.user}</td>
      <td class="text-secondary ellipsis">${l.details}</td>
      <td class="text-secondary">${l.ipAddress}</td>
      <td><span class="badge ${l.status==='success'?'badge-green':l.status==='warning'?'badge-yellow':'badge-red'}">${l.status}</span></td>
    </tr>`).join('');
  document.getElementById('logsEmpty').style.display = data.systemLogs.length ? 'none' : 'block';

  lucide.createIcons();
}

function setTab(name, el) {
  document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + name).style.display = 'block';
  el.classList.add('active');
  lucide.createIcons();
}

function handleRefresh() {
  const icon = document.getElementById('refreshIcon');
  icon.style.animation = 'spin 1s linear infinite';
  fetchData().then(() => { setTimeout(() => icon.style.animation = 'none', 1000); });
}

function downloadCSV(type) {
  let rows = [], filename = '';
  if (type === 'financial') {
    const s = document.getElementById('startDate').value, e = document.getElementById('endDate').value;
    const txns = data.transactions.filter(t => { const d = new Date(t.transactionDate); if (s && d < new Date(s)) return false; if (e && d > new Date(e)) return false; return true; });
    rows = [['Transaction Date','Type','Patient Name','Patient ID','Amount','Payment Method','Bill Number','Description'], ...txns.map(t => [formatDate(t.transactionDate),t.transactionType,t.patientName,t.patientId,t.amount,t.paymentMethod,t.billNumber,t.description])];
    filename = `Financial_Report_${new Date().toISOString().split('T')[0]}.csv`;
  } else if (type === 'insurance') {
    rows = [['Claim Date','Patient Name','Patient ID','Insurance Provider','Bill Number','Claim Amount','Status'], ...data.insuranceClaims.map(c => [formatDate(c.claimDate),c.patientName,c.patientId,c.insuranceProvider,c.billNumber,c.claimAmount,c.status])];
    filename = `Insurance_Claims_${new Date().toISOString().split('T')[0]}.csv`;
  } else if (type === 'logs') {
    rows = [['Timestamp','Action','Module','User','Details','IP Address','Status'], ...data.systemLogs.map(l => [formatDateTime(l.timestamp),l.action,l.module,l.user,l.details,l.ipAddress,l.status])];
    filename = `System_Logs_${new Date().toISOString().split('T')[0]}.csv`;
  } else if (type === 'department') {
    rows = [['Department','Patients','Revenue','Admissions'], ...data.departmentStats.map(d => [d.name,d.patients,d.revenue,d.admissions])];
    filename = `Department_Performance_${new Date().toISOString().split('T')[0]}.csv`;
  } else if (type === 'appointment') {
    rows = [['Month','Confirmed','Completed','Cancelled'], ...data.appointmentStats.map(a => [a.name,a.confirmed,a.completed,a.cancelled])];
    filename = `Appointment_Report_${new Date().toISOString().split('T')[0]}.csv`;
  }
  const blob = new Blob([rows.map(r => r.join(',')).join('\n')], { type: 'text/csv' });
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = filename; a.click();
}

document.getElementById('startDate').addEventListener('change', renderTables);
document.getElementById('endDate').addEventListener('change', renderTables);

fetchData();
</script>
</body>
</html>