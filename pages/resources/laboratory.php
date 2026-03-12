<?php
require_once '../../config/database.php';
require_once '../../config/session.php';

requireLogin();

if (!validateSession($pdo)) {
    destroySession($pdo);
    header('Location: /auth/login.php');
    exit;
}

$pageTitle = 'Laboratory Procedures - Arawa Hospital';

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
        $sql = 'SELECT * FROM lab_procedures WHERE 1=1';
        $params = [];
        if ($search) {
            $sql .= ' AND procedure_name LIKE :s1';
            $params[':s1'] = "%$search%";
        }
        if ($status && $status !== 'all') { $sql .= ' AND status = :status'; $params[':status'] = $status; }
        $sql .= ' ORDER BY created_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        respond($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($method === 'POST') {
        if (empty($body['procedureName']) || !isset($body['price'])) respondError('Missing required fields');
        $stmt = $pdo->prepare('INSERT INTO lab_procedures (procedure_name, category, sample_type, price, status) VALUES (?,?,?,?,?)');
        $stmt->execute([$body['procedureName'], $body['category'] ?? 'hematology', $body['sampleType'] ?? '', $body['price'], $body['status'] ?? 'available']);
        respond(['id' => $pdo->lastInsertId()], 201);
    }

    if ($method === 'PUT' && $id) {
        $stmt = $pdo->prepare('UPDATE lab_procedures SET procedure_name=?, category=?, sample_type=?, price=?, status=? WHERE id=?');
        $stmt->execute([$body['procedureName'], $body['category'] ?? 'hematology', $body['sampleType'] ?? '', $body['price'], $body['status'] ?? 'available', $id]);
        respond(['updated' => $id]);
    }

    if ($method === 'DELETE' && $id) {
        $stmt = $pdo->prepare('DELETE FROM lab_procedures WHERE id=?');
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
  <link rel="stylesheet" href="../../assets/css/laboratory.css">
  <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>

<?php include_once '../../templates/sidebar.php'; ?>

<div class="main-content">
<div class="page">

  <div class="header">
    <div>
      <h1>Laboratory Procedures</h1>
      <p>Manage laboratory tests and procedures.</p>
    </div>
    <div class="header-actions">
      <div class="search-wrap">
        <i data-lucide="search" class="search-icon"></i>
        <input id="searchInput" type="text" placeholder="Search procedures..." oninput="onSearch()" />
      </div>
      <button class="btn-primary" onclick="openModal()">
        <i data-lucide="plus"></i> Add Procedure
      </button>
    </div>
  </div>

  <div class="filter-bar">
    <div class="filter-left">
      <span class="filter-label">Filter by status:</span>
      <select id="statusFilter" onchange="onFilterChange()">
        <option value="all">All Procedures</option>
        <option value="available">Available</option>
        <option value="unavailable">Unavailable</option>
      </select>
    </div>
    <div class="count-badge" id="countBadge">0 procedures found</div>
  </div>

  <div class="card">
    <div class="card-header">
      <h3>All Procedures</h3>
    </div>
    <div id="listContainer">
      <div class="state-msg">Loading laboratory procedures...</div>
    </div>
  </div>

</div>
</div>

<div id="modal" class="modal-overlay" onclick="onOverlayClick(event)" style="display:none;">
  <div class="modal custom-scrollbar">
    <h2 id="modalTitle">Add New Lab Procedure</h2>
    <div class="form-grid">
      <div class="field">
        <label>Procedure Name *</label>
        <input type="text" id="f_procedureName" />
      </div>
      <div class="field">
        <label>Category *</label>
        <select id="f_category">
          <option value="hematology">Hematology</option>
          <option value="biochemistry">Biochemistry</option>
          <option value="microbiology">Microbiology</option>
          <option value="immunology">Immunology</option>
          <option value="pathology">Pathology</option>
        </select>
      </div>
      <div class="field">
        <label>Sample Type *</label>
        <input type="text" id="f_sampleType" placeholder="e.g., Blood (EDTA)" />
      </div>
      <div class="field">
        <label>Price (KSh) *</label>
        <input type="number" id="f_price" />
      </div>
      <div class="field">
        <label>Status</label>
        <select id="f_status">
          <option value="available">Available</option>
          <option value="unavailable">Unavailable</option>
        </select>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-cancel" onclick="closeModal()">Cancel</button>
      <button class="btn-primary form-btn-primary" onclick="handleSave()" id="submitBtn">Add Procedure</button>
    </div>
  </div>
</div>

<script src="../assets/js/theme.js"></script>
<script src="../assets/js/sidebar.js"></script>
<script src="../assets/js/main.js"></script>
<script>
const API = 'laboratory.php?api=1';
let procedures = [];
let editId = null;

const categoryLabels = { hematology:'Hematology', biochemistry:'Biochemistry', microbiology:'Microbiology', immunology:'Immunology', pathology:'Pathology' };

async function fetchProcedures() {
  const search = document.getElementById('searchInput').value;
  const status = document.getElementById('statusFilter').value;
  const params = new URLSearchParams({ search });
  if (status !== 'all') params.append('status', status);
  const res = await fetch(`${API}&${params}`);
  const json = await res.json();
  procedures = json.data || [];
  renderList();
}

function renderList() {
  const container = document.getElementById('listContainer');
  const search = document.getElementById('searchInput').value.toLowerCase();

  const filtered = procedures.filter(p => p.procedure_name.toLowerCase().includes(search));

  document.getElementById('countBadge').textContent = `${filtered.length} procedures found`;

  if (filtered.length === 0) {
    container.innerHTML = '<div class="state-msg">No procedures found</div>';
    return;
  }

  container.innerHTML = filtered.map(p => `
    <div class="proc-row">
      <div class="proc-icon">
        <i data-lucide="test-tube"></i>
      </div>
      <div class="proc-info">
        <div class="proc-title-row">
          <h3>${p.procedure_name}</h3>
          <span class="badge badge-category">${categoryLabels[p.category] || p.category}</span>
          <span class="badge ${p.status === 'available' ? 'badge-available' : 'badge-unavailable'}">${p.status}</span>
        </div>
        <p class="sub">Sample: ${p.sample_type || ''}</p>
      </div>
      <div class="proc-price">
        <div class="price-amount">KSh ${parseFloat(p.price).toLocaleString('en-KE', {minimumFractionDigits:2})}</div>
        <div class="price-label">Price</div>
      </div>
      <div class="row-actions">
        <button class="btn-edit" onclick='handleEdit(${JSON.stringify(p)})'>
          <i data-lucide="edit-2"></i> Edit
        </button>
        <button class="btn-delete" onclick="handleDelete('${p.id}')">
          <i data-lucide="trash-2"></i> Delete
        </button>
      </div>
    </div>
  `).join('');

  lucide.createIcons();
}

function openModal() {
  editId = null;
  document.getElementById('modalTitle').textContent = 'Add New Lab Procedure';
  document.getElementById('submitBtn').textContent = 'Add Procedure';
  document.getElementById('f_procedureName').value = '';
  document.getElementById('f_category').value = 'hematology';
  document.getElementById('f_sampleType').value = '';
  document.getElementById('f_price').value = '';
  document.getElementById('f_status').value = 'available';
  document.getElementById('modal').style.display = 'flex';
}

function handleEdit(p) {
  editId = p.id;
  document.getElementById('modalTitle').textContent = 'Edit Lab Procedure';
  document.getElementById('submitBtn').textContent = 'Update Procedure';
  document.getElementById('f_procedureName').value = p.procedure_name;
  document.getElementById('f_category').value = p.category;
  document.getElementById('f_sampleType').value = p.sample_type || '';
  document.getElementById('f_price').value = p.price;
  document.getElementById('f_status').value = p.status;
  document.getElementById('modal').style.display = 'flex';
}

async function handleSave() {
  const procedureName = document.getElementById('f_procedureName').value.trim();
  const price = document.getElementById('f_price').value;
  if (!procedureName || !price) { alert('Please fill in all required fields'); return; }

  const payload = {
    procedureName,
    category: document.getElementById('f_category').value,
    sampleType: document.getElementById('f_sampleType').value,
    price: parseFloat(price),
    status: document.getElementById('f_status').value
  };

  if (editId) {
    await fetch(`${API}&id=${editId}`, { method: 'PUT', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
  } else {
    await fetch(API, { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
  }

  closeModal();
  fetchProcedures();
}

async function handleDelete(id) {
  if (!confirm('Are you sure you want to delete this procedure? This action cannot be undone.')) return;
  await fetch(`${API}&id=${id}`, { method: 'DELETE' });
  fetchProcedures();
}

function closeModal() { document.getElementById('modal').style.display = 'none'; editId = null; }
function onOverlayClick(e) { if (e.target === e.currentTarget) closeModal(); }
function onSearch() { renderList(); }
function onFilterChange() { fetchProcedures(); }

fetchProcedures();
</script>
</body>
</html>