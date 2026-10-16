<?php
$pageTitle = 'Admin Management';
$activePage = 'admins';
include __DIR__ . '/../components/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title"><i class="fas fa-user-shield"></i> Admin Management</h1>
        <p class="page-subtitle">Control all tenant admins: create, delete, reset passwords and limit voucher usage.</p>
    </div>
</div>

<div id="adminsAlert" style="display:none;margin-bottom:20px;"></div>

<!-- Create Admin -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header">
        <span class="card-title"><i class="fas fa-user-plus"></i> Create Tenant Admin</span>
    </div>
    <div class="card-body">
        <form id="adminForm">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="admName">Full Name</label>
                    <input type="text" id="admName" class="form-control" placeholder="e.g. OneLink Mombasa" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="admEmail">Email (login)</label>
                    <input type="email" id="admEmail" class="form-control" placeholder="admin@wisp.co" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="admPassword">Temporary Password</label>
                    <input type="password" id="admPassword" class="form-control" placeholder="min 4 characters" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="admLimit">Voucher Limit</label>
                    <input type="number" id="admLimit" class="form-control" placeholder="empty = unlimited" min="1">
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;">
                    <button type="submit" class="btn btn-primary" style="width:100%;">Create Admin</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Admins List -->
<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="fas fa-users"></i> All Tenant Admins</span>
    </div>
    <div class="card-body p-0">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Routers</th>
                        <th>Vouchers Used / Limit</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="adminsTable">
                    <tr><td colspan="7" style="text-align:center;color:var(--on-surface-med);padding:20px;">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="adm-modal" style="display:none;">
    <div class="adm-modal-box">
        <div class="adm-modal-header">
            <span>Edit Admin</span>
            <button type="button" onclick="closeModal('editModal')" aria-label="Close">&times;</button>
        </div>
        <div class="adm-modal-body">
            <input type="hidden" id="editId">
            <div class="form-group">
                <label class="form-label" for="editLimit">Voucher Limit</label>
                <input type="number" id="editLimit" class="form-control" placeholder="Empty = unlimited" min="1">
                <small style="color:var(--on-surface-med);">Maximum vouchers this admin may ever create. Leave empty for unlimited.</small>
            </div>
            <div class="form-group">
                <label class="form-label" for="editActive">Status</label>
                <label class="flex-row" style="gap:8px;align-items:center;">
                    <input type="checkbox" id="editActive" checked>
                    <span>Active (can log in)</span>
                </label>
            </div>
        </div>
        <div class="adm-modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="saveEdit()">Save</button>
        </div>
    </div>
</div>

<!-- Password Modal -->
<div id="pwModal" class="adm-modal" style="display:none;">
    <div class="adm-modal-box">
        <div class="adm-modal-header">
            <span>Reset Password</span>
            <button type="button" onclick="closeModal('pwModal')" aria-label="Close">&times;</button>
        </div>
        <div class="adm-modal-body">
            <input type="hidden" id="pwId">
            <div class="form-group">
                <label class="form-label" for="pwNew">New Password</label>
                <input type="password" id="pwNew" class="form-control" placeholder="min 4 characters">
            </div>
            <div class="form-group">
                <label class="form-label" for="pwConfirm">Confirm Password</label>
                <input type="password" id="pwConfirm" class="form-control" placeholder="Repeat password">
            </div>
        </div>
        <div class="adm-modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('pwModal')">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="savePassword()">Reset Password</button>
        </div>
    </div>
</div>

<style>
.adm-modal {
    position: fixed; inset: 0; background: rgba(10,10,14,0.7);
    z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 20px;
}
.adm-modal-box {
    background: var(--surface); border: 1px solid var(--surface-4);
    border-radius: var(--radius-lg); box-shadow: var(--shadow-3);
    width: 100%; max-width: 420px; overflow: hidden;
}
.adm-modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 20px; border-bottom: 1px solid var(--surface-4);
    font-family: 'Google Sans', sans-serif; font-size: 17px; font-weight: 500;
}
.adm-modal-header button { background: none; border: none; color: var(--on-surface-med); font-size: 26px; line-height: 1; cursor: pointer; }
.adm-modal-header button:hover { color: var(--red); }
.adm-modal-body { padding: 20px; }
.adm-modal-footer { padding: 14px 20px; border-top: 1px solid var(--surface-4); display: flex; justify-content: flex-end; gap: 10px; }
</style>

<script>
const adminsApi = '/api/admins.php';
let currentAdmins = [];

function showAlert(msg, ok = true) {
    const el = document.getElementById('adminsAlert');
    el.style.display = 'block';
    el.className = ok ? 'alert alert-success' : 'alert alert-danger';
    el.innerHTML = (ok ? '<i class="fas fa-check-circle"></i> ' : '<i class="fas fa-exclamation-triangle"></i> ') + msg;
    if (ok) setTimeout(() => { el.style.display = 'none'; }, 4000);
}

function openModal(id) { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fmtLimit(a) {
    if (a.voucher_limit === -1) return a.vouchers_used + ' / Unlimited';
    return a.vouchers_used + ' / ' + a.voucher_limit;
}

async function loadAdmins() {
    const res = await fetch(adminsApi);
    const data = await res.json();
    if (!data.success) { showAlert(data.error || 'Failed to load admins', false); return; }
    currentAdmins = data.admins || [];
    const tbody = document.getElementById('adminsTable');
    if (!currentAdmins.length) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--on-surface-med);padding:20px;">No admins yet</td></tr>';
        return;
    }
    tbody.innerHTML = currentAdmins.map(a => `
        <tr>
            <td><strong>${escapeHtml(a.name)}</strong></td>
            <td>${escapeHtml(a.email)}</td>
            <td>${a.routers_count}</td>
            <td>${fmtLimit(a)}</td>
            <td>${a.status ? '<span class="status-pill active">Active</span>' : '<span class="status-pill expired">Disabled</span>'}</td>
            <td>${a.created_at ? new Date(a.created_at.replace(' ', 'T') + 'Z').toLocaleDateString('en-GB', {day:'numeric',month:'short',year:'numeric'}) : '—'}</td>
            <td>
                <div class="td-actions" style="justify-content:flex-end;">
                    <button class="btn btn-outline btn-sm" onclick="openEdit(${a.id})" title="Edit limit / status"><i class="fas fa-edit"></i></button>
                    <button class="btn btn-outline btn-sm" onclick="openPassword(${a.id})" title="Reset password"><i class="fas fa-key"></i></button>
                    <button class="btn btn-outline btn-sm" onclick="deleteAdmin(${a.id})" title="Delete"><i class="fas fa-trash" style="color:var(--red);"></i></button>
                </div>
            </td>
        </tr>
    `).join('');
}

function openEdit(id) {
    const a = currentAdmins.find(x => x.id === id);
    if (!a) return;
    document.getElementById('editId').value = a.id;
    document.getElementById('editLimit').value = a.voucher_limit === -1 ? '' : a.voucher_limit;
    document.getElementById('editActive').checked = a.status === 1;
    openModal('editModal');
}

async function saveEdit() {
    const id = parseInt(document.getElementById('editId').value);
    const limitRaw = document.getElementById('editLimit').value.trim();
    const voucherLimit = limitRaw === '' ? -1 : parseInt(limitRaw);
    const status = document.getElementById('editActive').checked ? 1 : 0;
    const res = await fetch(adminsApi, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update', id, voucher_limit: voucherLimit, status })
    });
    const data = await res.json();
    if (data.success) { showAlert(data.message); closeModal('editModal'); loadAdmins(); }
    else showAlert(data.error || 'Update failed', false);
}

function openPassword(id) {
    document.getElementById('pwId').value = id;
    document.getElementById('pwNew').value = '';
    document.getElementById('pwConfirm').value = '';
    openModal('pwModal');
}

async function savePassword() {
    const id = parseInt(document.getElementById('pwId').value);
    const pw = document.getElementById('pwNew').value;
    const confirm = document.getElementById('pwConfirm').value;
    if (pw !== confirm) { showAlert('Passwords do not match', false); return; }
    const res = await fetch(adminsApi, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'password', id, new_password: pw })
    });
    const data = await res.json();
    if (data.success) { showAlert(data.message); closeModal('pwModal'); }
    else showAlert(data.error || 'Password reset failed', false);
}

async function deleteAdmin(id) {
    const a = currentAdmins.find(x => x.id === id);
    if (!a) return;
    if (!confirm('Delete admin "' + a.name + '"? This cannot be undone.')) return;
    const res = await fetch(adminsApi, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id })
    });
    const data = await res.json();
    if (data.success) { showAlert(data.message); loadAdmins(); }
    else showAlert(data.error || 'Delete failed', false);
}

document.getElementById('adminForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = document.getElementById('admName').value.trim();
    const email = document.getElementById('admEmail').value.trim();
    const password = document.getElementById('admPassword').value;
    const limitRaw = document.getElementById('admLimit').value.trim();
    const voucherLimit = limitRaw === '' ? -1 : parseInt(limitRaw);

    const res = await fetch(adminsApi, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'create', name, email, password, voucher_limit: voucherLimit, active: 1 })
    });
    const data = await res.json();
    if (data.success) {
        showAlert(data.message);
        document.getElementById('adminForm').reset();
        loadAdmins();
    } else {
        showAlert(data.error || 'Failed to create admin', false);
    }
});

loadAdmins();
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
