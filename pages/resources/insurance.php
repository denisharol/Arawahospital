<?php
require_once '../../config/database.php';
require_once '../../config/session.php';

requireLogin();

if (!validateSession($pdo)) {
    destroySession($pdo);
    header('Location: /auth/login.php');
    exit;
}

$pageTitle = 'Insurance Plans - Arawa Hospital';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (isset($_GET['api'])) {
    header('Content-Type: application/json');

    $method = $_SERVER['REQUEST_METHOD'];
    $id = $_GET['id'] ?? null;
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    function respond($data, $code = 200) {
        http_response_code($code);
        echo json_encode(['data' => $data]);
        exit;
    }

    function respondError($msg, $code = 400) {
        http_response_code($code);
        echo json_encode(['error' => $msg]);
        exit;
    }

    if ($method === 'GET' && !$id) {
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? '';
        $sql = 'SELECT * FROM insurance_plans WHERE 1=1';
        $params = [];
        if ($search) {
            $sql .= ' AND (plan_name LIKE :s1 OR provider_name LIKE :s2)';
            $like = "%$search%";
            $params[':s1'] = $like; $params[':s2'] = $like;
        }
        if ($status && $status !== 'all') { $sql .= ' AND status = :status'; $params[':status'] = $status; }
        $sql .= ' ORDER BY created_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['covered_services'] = $row['covered_services'] ? explode(',', $row['covered_services']) : [];
        }
        respond($rows);
    }

    if ($method === 'POST') {
        if (empty($body['planName']) || empty($body['providerName'])) respondError('Missing required fields');
        $covered = is_array($body['coveredServices']) ? implode(',', $body['coveredServices']) : ($body['coveredServices'] ?? '');
        $stmt = $pdo->prepare('INSERT INTO insurance_plans (plan_name, provider_name, coverage_type, covered_services, exclusions, status) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$body['planName'], $body['providerName'], $body['coverageType'] ?? 'individual', $covered, $body['exclusions'] ?? '', $body['status'] ?? 'active']);
        respond(['id' => $pdo->lastInsertId()], 201);
    }

    if ($method === 'PUT' && $id) {
        $covered = is_array($body['coveredServices']) ? implode(',', $body['coveredServices']) : ($body['coveredServices'] ?? '');
        $stmt = $pdo->prepare('UPDATE insurance_plans SET plan_name=?, provider_name=?, coverage_type=?, covered_services=?, exclusions=?, status=? WHERE id=?');
        $stmt->execute([$body['planName'], $body['providerName'], $body['coverageType'] ?? 'individual', $covered, $body['exclusions'] ?? '', $body['status'] ?? 'active', $id]);
        respond(['updated' => $id]);
    }

    if ($method === 'DELETE' && $id) {
        $stmt = $pdo->prepare('DELETE FROM insurance_plans WHERE id=?');
        $stmt->execute([$id]);
        respond(['deleted' => $id]);
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
  <link rel="stylesheet" href="../../assets/css/theme.css">
  <link rel="stylesheet" href="../../assets/css/style.css">
  <link rel="stylesheet" href="../../assets/css/sidebar.css">
  <link rel="stylesheet" href="../../assets/css/insurance.css">
  <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>

<?php include_once '../../templates/sidebar.php'; ?>

<div class="main-content">
<div class="page">

  <div class="header">
    <div>
      <h1>Insurance Plans</h1>
      <p>Manage insurance providers, plans, and coverage details.</p>
    </div>
    <div class="header-actions">
      <div class="search-wrap">
        <i data-lucide="search" class="search-icon"></i>
        <input id="searchInput" type="text" placeholder="Search plans..." oninput="onSearch()" />
      </div>
      <button class="btn-primary" onclick="openModal()">
        <i data-lucide="plus"></i> Add Plan
      </button>
    </div>
  </div>

  <div class="filter-bar">
    <div class="filter-left">
      <span class="filter-label">Filter by status:</span>
      <select id="statusFilter" onchange="onFilterChange()">
        <option value="all">All Plans</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
      </select>
    </div>
    <div class="count-badge" id="countBadge">0 plans found</div>
  </div>

  <div class="card">
    <div class="card-header">
      <h3>All Insurance Plans</h3>
    </div>
    <div id="listContainer">
      <div class="state-msg">Loading insurance plans...</div>
    </div>
  </div>

</div>
</div>

<div id="modal" class="modal-overlay" onclick="onOverlayClick(event)" style="display:none;">
  <div class="modal custom-scrollbar">
    <h2 id="modalTitle">Add New Insurance Plan</h2>
    <div class="form-grid">
      <div class="field">
        <label>Plan Name *</label>
        <input type="text" id="f_planName" />
      </div>
      <div class="field">
        <label>Provider Name *</label>
        <input type="text" id="f_providerName" />
      </div>
      <div class="field">
        <label>Coverage Type *</label>
        <select id="f_coverageType">
          <option value="individual">Individual</option>
          <option value="family">Family</option>
          <option value="corporate">Corporate</option>
        </select>
      </div>
      <div class="field">
        <label>Status</label>
        <select id="f_status">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      <div class="field field-full">
        <label>Covered Services (comma-separated)</label>
        <input type="text" id="f_coveredServices" placeholder="e.g., Inpatient, Outpatient, Surgery" />
      </div>
      <div class="field field-full">
        <label>Exclusions</label>
        <textarea id="f_exclusions" rows="2"></textarea>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-cancel" onclick="closeModal()">Cancel</button>
      <button class="btn-primary" onclick="handleSave()" id="submitBtn">Add Plan</button>
    </div>
  </div>
</div>

<script src="../assets/js/theme.js"></script>
<script src="../assets/js/sidebar.js"></script>
<script src="../assets/js/main.js"></script>
<script>
const API = 'insurance.php?api=1';
let plans = [];
let editId = null;

const coverageLabels = { individual: 'Individual', family: 'Family', corporate: 'Corporate' };

async function fetchPlans() {
  const search = document.getElementById('searchInput').value;
  const status = document.getElementById('statusFilter').value;
  const params = new URLSearchParams({ search });
  if (status !== 'all') params.append('status', status);
  const res = await fetch(`${API}&${params}`);
  const json = await res.json();
  plans = json.data || [];
  renderList();
}

function renderList() {
  const container = document.getElementById('listContainer');
  const search = document.getElementById('searchInput').value.toLowerCase();

  const filtered = plans.filter(p =>
    p.plan_name.toLowerCase().includes(search) ||
    p.provider_name.toLowerCase().includes(search)
  );

  document.getElementById('countBadge').textContent = `${filtered.length} plans found`;

  if (filtered.length === 0) {
    container.innerHTML = '<div class="state-msg">No insurance plans found</div>';
    return;
  }

  container.innerHTML = filtered.map(p => {
    const services = Array.isArray(p.covered_services) ? p.covered_services : [];
    return `
    <div class="plan-row">
      <div class="plan-icon">
        <i data-lucide="shield"></i>
      </div>
      <div class="plan-info">
        <div class="plan-title-row">
          <h3>${p.plan_name}</h3>
          <span class="badge badge-coverage">${coverageLabels[p.coverage_type] || p.coverage_type}</span>
          <span class="badge ${p.status === 'active' ? 'badge-active' : 'badge-inactive'}">${p.status}</span>
        </div>
        <p class="sub">Provider: ${p.provider_name}</p>
        ${services.length ? `<p class="covered"><i data-lucide="check-circle" class="covered-icon"></i> Covered Services: ${services.join(', ')}</p>` : ''}
        ${p.exclusions ? `<p class="exclusions">Exclusions: ${p.exclusions}</p>` : ''}
      </div>
      <div class="row-actions">
        <button class="btn-edit" onclick='handleEdit(${JSON.stringify(p)})'>
          <i data-lucide="edit-2"></i> Edit
        </button>
        <button class="btn-delete" onclick="handleDelete('${p.id}')">
          <i data-lucide="trash-2"></i> Delete
        </button>
      </div>
    </div>`;
  }).join('');

  lucide.createIcons();
}

function openModal() {
  editId = null;
  document.getElementById('modalTitle').textContent = 'Add New Insurance Plan';
  document.getElementById('submitBtn').textContent = 'Add Plan';
  document.getElementById('f_planName').value = '';
  document.getElementById('f_providerName').value = '';
  document.getElementById('f_coverageType').value = 'individual';
  document.getElementById('f_status').value = 'active';
  document.getElementById('f_coveredServices').value = '';
  document.getElementById('f_exclusions').value = '';
  document.getElementById('modal').style.display = 'flex';
}

function handleEdit(p) {
  editId = p.id;
  document.getElementById('modalTitle').textContent = 'Edit Insurance Plan';
  document.getElementById('submitBtn').textContent = 'Update Plan';
  document.getElementById('f_planName').value = p.plan_name;
  document.getElementById('f_providerName').value = p.provider_name;
  document.getElementById('f_coverageType').value = p.coverage_type;
  document.getElementById('f_status').value = p.status;
  document.getElementById('f_coveredServices').value = Array.isArray(p.covered_services) ? p.covered_services.join(', ') : '';
  document.getElementById('f_exclusions').value = p.exclusions || '';
  document.getElementById('modal').style.display = 'flex';
}

async function handleSave() {
  const planName = document.getElementById('f_planName').value.trim();
  const providerName = document.getElementById('f_providerName').value.trim();
  if (!planName || !providerName) { alert('Please fill in all required fields'); return; }

  const raw = document.getElementById('f_coveredServices').value;
  const payload = {
    planName,
    providerName,
    coverageType: document.getElementById('f_coverageType').value,
    coveredServices: raw.split(',').map(s => s.trim()).filter(Boolean),
    exclusions: document.getElementById('f_exclusions').value,
    status: document.getElementById('f_status').value
  };

  if (editId) {
    await fetch(`${API}&id=${editId}`, { method: 'PUT', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
  } else {
    await fetch(API, { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
  }

  closeModal();
  fetchPlans();
}

async function handleDelete(id) {
  if (!confirm('Are you sure you want to delete this insurance plan? This action cannot be undone.')) return;
  await fetch(`${API}&id=${id}`, { method: 'DELETE' });
  fetchPlans();
}

function closeModal() { document.getElementById('modal').style.display = 'none'; editId = null; }
function onOverlayClick(e) { if (e.target === e.currentTarget) closeModal(); }
function onSearch() { renderList(); }
function onFilterChange() { fetchPlans(); }

fetchPlans();
</script>
</body>
</html>