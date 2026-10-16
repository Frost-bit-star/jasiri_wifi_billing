<?php
ob_start();
$pageTitle = 'Internet Vouchers';
$activePage = 'vouchers';
include __DIR__ . '/../components/header.php';
?>
<style>
.status-pill {
    padding: 3px 10px; border-radius: var(--radius-full); font-size: 11px; font-weight: 600; letter-spacing: .3px;
}
.status-pill.active { background: #E6F4EA; color: #137333; }
.status-pill.used { background: #FCE8E6; color: #C5221F; }
.status-pill.expired { background: var(--surface-3); color: var(--on-surface-med); }

.print-area {
    position: absolute; left: -10000px; top: 0;
    width: 100%;
}

@media print {
    body * { visibility: hidden; }
    .print-area, .print-area * { visibility: visible; }
    .print-area {
        position: absolute; left: 0; top: 0;
        width: 100%; background: #fff; padding: 0.5rem;
    }
    .print-voucher {
        border: 2px dashed #333; padding: 0.8rem; margin: 0.3rem;
        width: 46%; display: inline-block; vertical-align: top;
        page-break-inside: avoid; text-align: center;
        border-radius: 8px;
    }
    .print-voucher h4 {
        margin: 0 0 0.3rem; font-size: 0.9rem; color: var(--blue-500);
        text-transform: uppercase; letter-spacing: 1px;
    }
    .print-voucher .code {
        font-size: 1.6rem; font-weight: 700; letter-spacing: 4px;
        text-align: center; margin: 0.4rem 0; color: #1a1a2e;
        font-family: 'Courier New', monospace;
    }
    .print-voucher .detail {
        font-size: 0.75rem; color: #555; margin: 2px 0;
    }
    .print-voucher .qr-code {
        margin: 6px auto 4px;
    }
    .print-voucher .qr-code canvas,
    .print-voucher .qr-code img {
        display: block;
        margin: 0 auto;
    }
}
</style>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title"><i class="fas fa-ticket-alt"></i> Internet Vouchers</h1>
        <p class="page-subtitle">Generate, track and manage vouchers for customer access</p>
    </div>
</div>

<!-- Stats Cards -->
<div class="stat-grid" id="statsRow">
    <div class="stat-card">
        <div class="stat-card-header">
            <span class="stat-card-label">Total Vouchers</span>
            <div class="stat-icon blue"><i class="fas fa-ticket-alt"></i></div>
        </div>
        <div class="stat-value" id="statTotal">0</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-header">
            <span class="stat-card-label">Active</span>
            <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
        </div>
        <div class="stat-value" id="statActive">0</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-header">
            <span class="stat-card-label">Used</span>
            <div class="stat-icon red"><i class="fas fa-times-circle"></i></div>
        </div>
        <div class="stat-value" id="statUsed">0</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-header">
            <span class="stat-card-label">Expired</span>
            <div class="stat-icon yellow"><i class="fas fa-clock"></i></div>
        </div>
        <div class="stat-value" id="statExpired">0</div>
    </div>
</div>

<!-- Tabs -->
<div class="seg-tabs" id="voucherTabs">
    <span class="seg-tab active" data-tab="generate" onclick="showTab('generate')">
        <i class="fas fa-plus-circle"></i> Generate Vouchers
    </span>
    <span class="seg-tab" data-tab="online" onclick="showTab('online')">
        <i class="fas fa-wifi"></i> Online Vouchers
    </span>
    <span class="seg-tab" data-tab="print" onclick="showTab('print')">
        <i class="fas fa-print"></i> Print Vouchers
    </span>
    <span class="seg-tab" data-tab="track" onclick="showTab('track')">
        <i class="fas fa-search"></i> Track Voucher
    </span>
</div>

<!-- Generate Tab -->
<div class="tab-content" id="tab-generate">
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-magic"></i> Generate New Vouchers</span>
        </div>
        <div class="card-body">
            <form id="generateForm">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Router *</label>
                        <select class="form-control" id="genRouter" required>
                            <option value="">Select router...</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Package / Plan *</label>
                        <select class="form-control" id="genPlan" required>
                            <option value="">Select plan...</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Quantity</label>
                        <input type="number" class="form-control" id="genQuantity" value="1" min="1" max="100">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Price (<?= htmlspecialchars($appCurrencySymbol) ?>)</label>
                        <input type="number" class="form-control" id="genPrice" value="500" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Customer Name</label>
                        <input type="text" class="form-control" id="genCustomer" placeholder="Optional">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Speed Cap</label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding-top:8px;">
                            <input type="checkbox" id="genCapped" style="width:18px;height:18px;cursor:pointer;">
                            <span style="font-size:13px;font-weight:500;">Capped (1 Mbps / 500 Kbps)</span>
                        </label>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" id="generateBtn">
                    <i class="fas fa-cogs"></i> Generate Vouchers
                </button>
            </form>
            <div id="generatedCodes" style="display:none;margin-top:16px;">
                <div class="alert alert-success">
                    <div class="flex-row">
                        <strong><i class="fas fa-check-circle"></i> Generated!</strong>
                        <button type="button" class="btn btn-primary btn-sm" id="printGeneratedBtn" onclick="printGenerated()" style="display:none;">
                            <i class="fas fa-print"></i> Print Vouchers
                        </button>
                    </div>
                    <div id="generatedList" style="margin-top:8px;"></div>
                </div>
                <div id="generatedPreview"></div>
            </div>
        </div>
    </div>
</div>

<!-- Online Vouchers Tab -->
<div class="tab-content" id="tab-online" style="display:none;">
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-list"></i> Vouchers</span>
            <div class="flex-row">
                <select class="form-control" id="filterRouter" style="width:auto;display:inline-block;" onchange="loadVouchers()">
                    <option value="">All Routers</option>
                </select>
                <select class="form-control" id="filterStatus" style="width:auto;display:inline-block;" onchange="loadVouchers()">
                    <option value="">All Status</option>
                    <option value="active">Active</option>
                    <option value="used">Used</option>
                    <option value="expired">Expired</option>
                </select>
            </div>
        </div>
        <div class="card-body">
            <div id="batchActions" style="display:none;margin-bottom:12px;padding:10px 14px;background:var(--surface-2);border-radius:var(--radius-md);align-items:center;gap:12px;">
                <span id="batchCount" style="font-weight:600;font-size:13px;"></span>
                <button class="btn btn-danger btn-sm" onclick="batchDelete()"><i class="fas fa-trash"></i> Delete Selected</button>
                <button class="btn btn-outline btn-sm" onclick="clearSelection()">Cancel</button>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th style="width:36px;"><input type="checkbox" id="selectAllOnline" onchange="toggleSelectAllOnline()" style="cursor:pointer;"></th>
                            <th>Voucher Code</th>
                            <th>Router</th>
                            <th>Package</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Price</th>
                            <th>Speed</th>
                            <th>Status</th>
                            <th>Expires</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="voucherTableBody">
                        <tr><td colspan="11" style="text-align:center;color:var(--on-surface-med);padding:24px;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Print Tab -->
<div class="tab-content" id="tab-print" style="display:none;">
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-print"></i> Print Vouchers</span>
            <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print Now</button>
        </div>
        <div class="card-body">
            <div class="form-row" style="margin-bottom:16px;">
                <div class="form-group">
                    <label class="form-label">Filter by router</label>
                    <select class="form-control" id="printRouter" onchange="loadPrintVouchers()">
                        <option value="">All Routers</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Filter by status</label>
                    <select class="form-control" id="printFilter" onchange="loadPrintVouchers()">
                        <option value="active">Active Only</option>
                        <option value="">All</option>
                    </select>
                </div>
            </div>
            <div class="flex-row" style="margin-bottom:8px;">
                <span style="font-weight:600;font-size:13px;">Select vouchers to print <span id="printCount" class="chip info" style="display:none;">0</span></span>
                <label style="cursor:pointer;display:flex;align-items:center;gap:6px;font-size:0.85rem;font-weight:600;margin:0;">
                    <input type="checkbox" id="selectAllPrint" onchange="toggleSelectAll()">
                    Select All
                </label>
            </div>
            <div id="printCheckboxes" style="max-height:250px;overflow-y:auto;border:1px solid var(--surface-4);padding:8px;border-radius:var(--radius-md);">
                Loading...
            </div>
            <div id="printPreview" style="margin-top:16px;"></div>
        </div>
    </div>
</div>

<!-- Track Voucher Tab -->
<div class="tab-content" id="tab-track" style="display:none;">
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-search"></i> Track Voucher Usage</span>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group" style="max-width:420px;">
                    <label class="form-label" for="trackCode">Enter Voucher Code</label>
                    <div class="flex-row">
                        <input type="text" class="form-control" id="trackCode" placeholder="0000 0000" maxlength="11" style="flex:1;"
                            oninput="this.value = this.value.replace(/[^0-9]/g,'').replace(/(.{4})/g,'$1 ').trim()">
                        <button class="btn btn-primary" onclick="trackVoucher()"><i class="fas fa-search"></i> Track</button>
                    </div>
                </div>
            </div>
            <div id="trackResult" style="display:none;"></div>
            <div id="trackLoading" class="text-center py-4" style="display:none;">
                <i class="fas fa-spinner fa-spin fa-2x" style="color:var(--on-surface-low)"></i>
                <p style="color:var(--on-surface-med);margin-top:8px;">Checking router...</p>
            </div>
            <div id="trackEmpty" class="text-center py-4" style="color:var(--on-surface-med);">
                <i class="fas fa-search fa-3x mb-3" style="opacity:0.3;"></i>
                <p>Enter a voucher code above to track its usage and device status.</p>
            </div>
        </div>
    </div>
</div>

<!-- Print Layout (hidden, shown only when printing) -->
<div class="print-area" id="printArea"></div>

</div>

<script src="/assets/js/qrcode.min.js"></script>
<script>
let allPlans = [];
let allVouchers = [];
let allRouters = [];

async function loadPlans() {
    try {
        const res = await fetch('/api/plans.php');
        const data = await res.json();
        allPlans = data.plans || [];
        const sel = document.getElementById('genPlan');
        sel.innerHTML = '<option value="">Select plan...</option>' +
            allPlans.map(p => `<option value="${p.id}">${p.name} (${p.days}d ${p.hours}h ${p.minutes}m)</option>`).join('');
    } catch (e) { console.error('Failed to load plans:', e); }
}

async function loadRouters() {
    try {
        const res = await fetch('/api/mikrotik.php?action=list');
        const data = await res.json();
        allRouters = data.routers || [];
        const genSel = document.getElementById('genRouter');
        genSel.innerHTML = '<option value="">Select router...</option>' +
            allRouters.map(r => `<option value="${r.id}">${r.name}</option>`).join('');
        const filterSel = document.getElementById('filterRouter');
        filterSel.innerHTML = '<option value="">All Routers</option>' +
            allRouters.map(r => `<option value="${r.id}">${r.name}</option>`).join('');
        const printRouterSel = document.getElementById('printRouter');
        printRouterSel.innerHTML = '<option value="">All Routers</option>' +
            allRouters.map(r => `<option value="${r.id}">${r.name}</option>`).join('');
    } catch (e) { console.error('Failed to load routers:', e); }
}

async function loadStats() {
    try {
        const res = await fetch('/api/vouchers.php?action=stats');
        const data = await res.json();
        const s = data.stats;
        document.getElementById('statTotal').textContent = s.total || 0;
        document.getElementById('statActive').textContent = s.active || 0;
        document.getElementById('statUsed').textContent = s.used || 0;
        document.getElementById('statExpired').textContent = s.expired || 0;
    } catch (e) { console.error('Failed to load stats:', e); }
}

async function loadVouchers() {
    const status = document.getElementById('filterStatus').value;
    const routerId = document.getElementById('filterRouter').value;
    const params = new URLSearchParams();
    if (status) params.set('status', status);
    if (routerId) params.set('router_id', routerId);
    params.set('limit', '100');

    try {
        const res = await fetch('/api/vouchers.php?' + params);
        const data = await res.json();
        allVouchers = data.vouchers || [];
        renderVoucherTable();
    } catch (e) {
        document.getElementById('voucherTableBody').innerHTML = '<tr><td colspan="10" style="text-align:center;color:var(--red);padding:24px;">Failed to load vouchers</td></tr>';
    }
}

function renderVoucherTable() {
    const tbody = document.getElementById('voucherTableBody');
    if (allVouchers.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;color:var(--on-surface-med);padding:24px;">No vouchers found</td></tr>';
        return;
    }
    tbody.innerHTML = allVouchers.map(v => `
        <tr>
            <td><input type="checkbox" class="online-cb" data-id="${v.id}" data-status="${v.status}" onchange="updateBatchBar()" style="cursor:pointer;"></td>
            <td><span class="voucher-code">${escapeHtml(v.code)}</span></td>
            <td>${escapeHtml(v.router_name || '—')}</td>
            <td>${escapeHtml(v.plan_name || '—')}</td>
            <td>${escapeHtml(v.customer_name || '—')}</td>
            <td>${v.phone ? escapeHtml(v.phone) : '—'}</td>
            <td>${window.APP_CURRENCY} ${parseInt(v.price || 0).toLocaleString()}</td>
            <td>${v.is_capped ? '<span style="color:#e67e22;font-weight:600;font-size:12px;">1M/500K</span>' : '<span style="color:var(--on-surface-med);font-size:12px;">Uncapped</span>'}</td>
            <td><span class="status-pill ${v.status}">${v.status}</span></td>
            <td>${v.expires_at ? new Date(v.expires_at).toLocaleDateString('en-GB', {day:'numeric',month:'short',year:'numeric'}) : '—'}</td>
            <td>
                <div class="td-actions">
                    <button class="btn btn-outline btn-sm" onclick="deleteVoucher(${v.id}, '${v.status}')"><i class="fas fa-trash"></i></button>
                </div>
            </td>
        </tr>
    `).join('');
}

let printVouchers = [];

async function loadPrintVouchers() {
    const filter = document.getElementById('printFilter').value;
    const routerId = document.getElementById('printRouter').value;
    const params = new URLSearchParams();
    if (filter) params.set('status', filter);
    if (routerId) params.set('router_id', routerId);
    params.set('limit', '200');

    try {
        const res = await fetch('/api/vouchers.php?' + params);
        const data = await res.json();
        printVouchers = data.vouchers || [];
        if (filter) {
            printVouchers = printVouchers.filter(v => v.status === filter);
        }

        document.getElementById('printCheckboxes').innerHTML = printVouchers.map(v => `
            <label style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:var(--radius-sm);cursor:pointer;margin:0;font-size:13px;">
                <input type="checkbox" class="print-cb" id="pv_${v.id}" data-id="${v.id}" onchange="updatePrintPreview()">
                <span style="flex:1;">
                    <span class="voucher-code" style="font-size:0.85rem;">${v.code}</span>
                    <small style="color:var(--on-surface-med);"> - ${escapeHtml(v.plan_name || '')} - ${window.APP_CURRENCY} ${parseInt(v.price || 0).toLocaleString()}</small>
                    ${v.is_capped ? '<small style="color:#e67e22;font-weight:600;"> (Capped)</small>' : ''}
                </span>
            </label>
        `).join('') || '<p style="color:var(--on-surface-med)">No vouchers found</p>';

        document.getElementById('printPreview').innerHTML = '<p style="color:var(--on-surface-med)">Select vouchers above to preview</p>';
        document.getElementById('printArea').innerHTML = '';
    } catch (e) {
        document.getElementById('printCheckboxes').innerHTML = '<p style="color:var(--red)">Failed to load</p>';
    }
}

function toggleSelectAll() {
    const checked = document.getElementById('selectAllPrint').checked;
    document.querySelectorAll('.print-cb').forEach(cb => cb.checked = checked);
    updatePrintPreview();
}

function updatePrintPreview() {
    const checked = document.querySelectorAll('.print-cb:checked');
    const ids = Array.from(checked).map(cb => cb.dataset.id);
    const selected = printVouchers.filter(v => ids.includes(String(v.id)));

    // Update count badge
    const countBadge = document.getElementById('printCount');
    if (selected.length > 0) {
        countBadge.textContent = selected.length;
        countBadge.style.display = 'inline';
    } else {
        countBadge.style.display = 'none';
    }

    // Sync select-all checkbox
    const allCbs = document.querySelectorAll('.print-cb');
    document.getElementById('selectAllPrint').checked = allCbs.length > 0 && checked.length === allCbs.length;

    const printArea = document.getElementById('printArea');
    const preview = document.getElementById('printPreview');

    if (selected.length === 0) {
        preview.innerHTML = '<p style="color:var(--on-surface-med)">Select vouchers above to preview</p>';
        printArea.innerHTML = '';
        return;
    }

    // Screen preview
    preview.innerHTML = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;">' + selected.map(v => `
        <div>
            <div style="border:2px dashed var(--surface-4);padding:16px;text-align:center;border-radius:var(--radius-md);background:var(--surface);">
                <strong style="color:var(--blue-500);">${escapeHtml((v.router_ssid || 'OneLink').toUpperCase())}</strong><br>
                <div class="voucher-code" style="font-size:1.4rem;letter-spacing:3px;margin:8px 0;">${v.code}</div>
                <small style="font-weight:600;">${escapeHtml(v.plan_name || '')}</small><br>
                <small>${window.APP_CURRENCY} ${parseInt(v.price || 0).toLocaleString()}</small><br>
                <small style="color:var(--on-surface-med);">Exp: ${v.expires_at ? new Date(v.expires_at).toLocaleDateString('en-GB', {day:'numeric',month:'short',year:'numeric'}) : '—'}</small><br>
                ${v.is_capped ? '<small style="color:#e67e22;font-weight:600;">Speed: 1 Mbps ↓ / 500 Kbps ↑</small>' : ''}
            </div>
        </div>
    `).join('') + '</div>';

    // Print layout (hidden, shown only on print)
    printArea.innerHTML = selected.map((v, i) => buildPrintVoucherHtml(v, i)).join('');

    setTimeout(function() {
        renderPrintQrCodes(selected);
    }, 50);
}

function buildPrintVoucherHtml(v, i) {
    return `
        <div class="print-voucher">
            <h4>${escapeHtml(v.router_ssid || 'OneLink')}</h4>
            <div class="code">${v.code}</div>
            <div class="qr-code" id="qr-${i}"></div>
            <div class="detail"><strong>Package:</strong> ${escapeHtml(v.plan_name || '—')}</div>
            <div class="detail"><strong>Price:</strong> ${window.APP_CURRENCY} ${parseInt(v.price || 0).toLocaleString()}</div>
            <div class="detail"><strong>Expires:</strong> ${v.expires_at ? new Date(v.expires_at).toLocaleDateString('en-GB', {day:'numeric',month:'short',year:'numeric'}) : '—'}</div>
        </div>
    `;
}

function renderPrintQrCodes(list) {
    list.forEach((v, i) => {
        var el = document.getElementById('qr-' + i);
        if (el && typeof QRCode !== 'undefined') {
            el.innerHTML = '';
            new QRCode(el, { text: v.code, width: 36, height: 36, correctLevel: QRCode.CorrectLevel.L, render: 'image' });
        }
    });
}

let lastGenerated = [];

function printGenerated() {
    if (!lastGenerated.length) return;
    const printArea = document.getElementById('printArea');
    printArea.innerHTML = lastGenerated.map((v, i) => buildPrintVoucherHtml(v, i)).join('');
    setTimeout(function() { renderPrintQrCodes(lastGenerated); }, 50);
    setTimeout(function() { window.print(); }, 150);
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function showTab(tab) {
    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.seg-tabs .seg-tab').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + tab).style.display = 'block';
    document.querySelector(`.seg-tabs .seg-tab[data-tab="${tab}"]`).classList.add('active');

    if (tab === 'online') loadVouchers();
    if (tab === 'print') loadPrintVouchers();
    if (tab === 'track') { document.getElementById('trackCode').focus(); }
}

document.getElementById('generateForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('generateBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';

    try {
        const res = await fetch('/api/vouchers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'generate',
                plan_id: document.getElementById('genPlan').value,
                router_id: document.getElementById('genRouter').value,
                quantity: document.getElementById('genQuantity').value,
                price: document.getElementById('genPrice').value,
                customer_name: document.getElementById('genCustomer').value,
                is_capped: document.getElementById('genCapped').checked ? 1 : 0
            })
        });
        const data = await res.json();

        if (!res.ok) throw new Error(data.error || 'Generation failed');

        const listDiv = document.getElementById('generatedCodes');
        const listEl = document.getElementById('generatedList');
        listDiv.style.display = 'block';
        listEl.innerHTML = data.vouchers.map(v =>
            `<span class="voucher-code" style="font-size:1.2rem;margin-right:1rem;">${v.code}</span>`
        ).join('');

        const routerIdVal = document.getElementById('genRouter').value;
        const router = allRouters.find(r => String(r.id) === String(routerIdVal));
        lastGenerated = (data.vouchers || []).map(v => ({
            ...v,
            plan_name: v.plan || '',
            router_ssid: router ? router.name : 'OneLink',
            is_capped: v.is_capped || 0
        }));

        document.getElementById('generatedPreview').innerHTML =
            '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-top:12px;">' +
            lastGenerated.map(v => `
                <div style="border:2px dashed var(--surface-4);padding:16px;text-align:center;border-radius:var(--radius-md);background:var(--surface);">
                    <strong style="color:var(--blue-500);">${escapeHtml(v.router_ssid || 'OneLink')}</strong><br>
                    <div class="voucher-code" style="font-size:1.4rem;letter-spacing:3px;margin:8px 0;">${v.code}</div>
                    <small style="font-weight:600;">${escapeHtml(v.plan_name || '')}</small><br>
                    <small>${window.APP_CURRENCY} ${parseInt(v.price || 0).toLocaleString()}</small><br>
                    ${v.is_capped ? '<small style="color:#e67e22;font-weight:600;">Speed: 1 Mbps ↓ / 500 Kbps ↑</small>' : ''}
                </div>
            `).join('') + '</div>';

        const printBtn = document.getElementById('printGeneratedBtn');
        printBtn.style.display = 'inline-flex';

        loadStats();
    } catch (err) {
        alert('Error: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-cogs"></i> Generate Vouchers';
    }
});

async function deleteVoucher(id, status) {
    const msg = status === 'used'
        ? 'Delete this used voucher? This will also remove the hotspot user from the router.'
        : 'Delete this voucher?';
    if (!confirm(msg)) return;
    try {
        await fetch('/api/vouchers.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        loadVouchers();
        loadStats();
    } catch (e) {
        alert('Delete failed');
    }
}

function toggleSelectAllOnline() {
    const checked = document.getElementById('selectAllOnline').checked;
    document.querySelectorAll('.online-cb').forEach(cb => cb.checked = checked);
    updateBatchBar();
}

function updateBatchBar() {
    const checked = document.querySelectorAll('.online-cb:checked');
    const bar = document.getElementById('batchActions');
    const count = checked.length;
    if (count > 0) {
        document.getElementById('batchCount').textContent = count + ' selected';
        bar.style.display = 'flex';
    } else {
        bar.style.display = 'none';
    }
    const allCbs = document.querySelectorAll('.online-cb');
    document.getElementById('selectAllOnline').checked = allCbs.length > 0 && checked.length === allCbs.length;
}

function clearSelection() {
    document.querySelectorAll('.online-cb').forEach(cb => cb.checked = false);
    document.getElementById('selectAllOnline').checked = false;
    updateBatchBar();
}

async function batchDelete() {
    const checked = document.querySelectorAll('.online-cb:checked');
    if (!checked.length) return;
    const ids = Array.from(checked).map(cb => parseInt(cb.dataset.id));
    const hasUsed = Array.from(checked).some(cb => cb.dataset.status === 'used');
    const msg = 'Delete ' + ids.length + ' voucher(s)?' + (hasUsed ? '\nUsed vouchers will also be removed from the router.' : '');
    if (!confirm(msg)) return;

    try {
        const res = await fetch('/api/vouchers.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Delete failed');
        clearSelection();
        loadVouchers();
        loadStats();
    } catch (e) {
        alert('Batch delete failed: ' + e.message);
    }
}

function formatUptime(s) {
    if (!s) return '—';
    return s;
}

function formatBytes(b) {
    b = parseInt(b || 0);
    if (b >= 1073741824) return (b / 1073741824).toFixed(1) + ' GB';
    if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
    if (b >= 1024) return (b / 1024).toFixed(1) + ' KB';
    return b + ' B';
}

async function trackVoucher() {
    const code = document.getElementById('trackCode').value.replace(/\s+/g, '');
    if (!code) { alert('Please enter a voucher code'); return; }

    document.getElementById('trackEmpty').style.display = 'none';
    document.getElementById('trackResult').style.display = 'none';
    document.getElementById('trackLoading').style.display = 'block';

    try {
        const res = await fetch('/api/vouchers.php?action=track&code=' + encodeURIComponent(code));
        const data = await res.json();

        document.getElementById('trackLoading').style.display = 'none';

        if (!res.ok) {
            document.getElementById('trackResult').style.display = 'block';
            document.getElementById('trackResult').innerHTML = '<div class="alert alert-danger">' + escapeHtml(data.error || 'Voucher not found') + '</div>';
            return;
        }

        const v = data.voucher;
        const online = data.is_online;
        const device = data.device;
        const onlineBadge = online ? '<span class="chip active"><span class="chip-dot"></span> Online</span>' : '<span class="chip inactive"><span class="chip-dot"></span> Offline</span>';

        document.getElementById('trackResult').style.display = 'block';
        document.getElementById('trackResult').innerHTML = `
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Voucher: <span class="voucher-code">${escapeHtml(v.code)}</span></span>
                    <span class="status-pill ${v.status}">${v.status.toUpperCase()}</span>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div>
                            <div class="table-wrapper">
                                <table>
                                    <tbody>
                                        <tr><td class="td-label">Plan</td><td><strong>${escapeHtml(v.plan_name || '—')}</strong></td></tr>
                                        <tr><td class="td-label">Router</td><td>${escapeHtml(v.router_name || '—')}</td></tr>
                                        <tr><td class="td-label">Customer Phone</td><td><strong>${escapeHtml(v.phone || '—')}</strong></td></tr>
                                        <tr><td class="td-label">Customer Name</td><td>${escapeHtml(v.customer_name || '—')}</td></tr>
                                        <tr><td class="td-label">Price</td><td>${window.APP_CURRENCY} ${parseInt(v.price || 0).toLocaleString()}</td></tr>
                                        <tr><td class="td-label">Used At</td><td>${v.used_at ? new Date(v.used_at).toLocaleString('en-GB') : '—'}</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div>
                            <h6 style="color:var(--on-surface-med);font-weight:600;margin-bottom:12px;">Device Connection Status</h6>
                            <div style="padding:14px;border-radius:var(--radius-md);border:2px solid ${online ? 'var(--green)' : 'var(--surface-4)'};background:${online ? '#F0FFF4' : 'var(--surface-2)'};">
                                <div class="flex-row" style="margin-bottom:10px;">
                                    <span style="font-size:13px;">Status:</span> ${onlineBadge}
                                </div>
                                ${device ? `
                                <div class="table-wrapper">
                                <table>
                                    <tbody>
                                        <tr><td class="td-label">MAC Address</td><td><code>${escapeHtml(device.mac || '—')}</code></td></tr>
                                        <tr><td class="td-label">IP Address</td><td>${escapeHtml(device.address || '—')}</td></tr>
                                        <tr><td class="td-label">Uptime</td><td>${formatUptime(device.uptime)}</td></tr>
                                        <tr><td class="td-label">Traffic In</td><td>${formatBytes(device.bytes_in)}</td></tr>
                                        <tr><td class="td-label">Traffic Out</td><td>${formatBytes(device.bytes_out)}</td></tr>
                                    </tbody>
                                </table>
                                </div>
                                ` : '<p style="color:var(--on-surface-med);margin:0;font-size:12px;">No active session found on router</p>'}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    } catch (e) {
        document.getElementById('trackLoading').style.display = 'none';
        document.getElementById('trackResult').style.display = 'block';
        document.getElementById('trackResult').innerHTML = '<div class="alert alert-danger">Failed to track voucher: ' + e.message + '</div>';
    }
}

loadPlans();
loadRouters();
loadStats();
</script>

<?php
include __DIR__ . '/../components/footer.php';
ob_end_flush();
