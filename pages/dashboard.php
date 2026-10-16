<?php
$pageTitle = 'Dashboard';
$activePage = 'dashboard';
include __DIR__ . '/../components/header.php';
$adminName = $_SESSION['username'] ?? 'Admin';
?>
<style>
.blink { display:inline-block; width:8px; height:8px; border-radius:50%; background:var(--green); animation:blink 2s infinite; }
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }
.device-row { cursor: pointer; }
</style>
<?php if (!empty($_SESSION['just_registered'])): ?>
<div id="onboardingModal" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;">
  <div style="background:white;padding:24px;border-radius:8px;max-width:400px;width:90%;box-shadow:0 10px 40px rgba(0,0,0,0.3);">
    <h3 style="margin:0 0 12px 0;">Welcome to <?= htmlspecialchars(appName()) ?>!</h3>
    <p style="margin:0 0 16px 0;color:#666;">Let's get you set up in 3 quick steps:</p>
    <ol style="margin:0 0 16px 24px;padding:0;line-height:1.8;">
      <li>Complete your profile (Settings → Profile)</li>
      <li>Set your business details</li>
      <li>Add your first router to get started</li>
    </ol>
    <div style="display:flex;gap:8px;justify-content:flex-end;">
      <button onclick="dismissOnboarding()" style="padding:8px 16px;border:1px solid #ddd;background:white;border-radius:4px;cursor:pointer;">Skip</button>
      <a href="/settings" style="padding:8px 16px;background:#1a73e8;color:white;text-decoration:none;border-radius:4px;">Set up Profile</a>
    </div>
  </div>
</div>
<script>
function dismissOnboarding() {
  document.getElementById('onboardingModal').style.display='none';
  fetch('/api/v1/auth.php?action=clear_onboarding', {method:'POST'}).catch(()=>{});
}
</script>
<?php unset($_SESSION['just_registered']); endif; ?>


<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">Welcome back, <?= htmlspecialchars($adminName) ?></h1>
        <p class="page-subtitle">
            <span class="blink"></span>&nbsp;
            Here's what's happening with your WISP business &middot; <span id="lastUpdated">Last updated: —</span>
        </p>
    </div>
</div>

<!-- Stats Row -->
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px">
    <div class="stat-card">
        <div class="stat-card-header">
            <span class="stat-card-label">Total Revenue</span>
            <span class="stat-icon blue"><svg viewBox="0 0 24 24"><path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/></svg></span>
        </div>
        <div class="stat-value" id="totalRevenue">—</div>
        <div class="stat-trend"><span class="stat-trend-label" id="monthRevenueSub">This month: —</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-header">
            <span class="stat-card-label">Online Devices</span>
            <span class="stat-icon green"><svg viewBox="0 0 24 24"><path d="M1 9l2 2c4.97-4.97 13.03-4.97 18 0l2-2C16.93 2.93 7.08 2.93 1 9zm8 8l3 3 3-3c-1.65-1.66-4.34-1.66-6 0zm-4-4l2 2c2.76-2.76 7.24-2.76 10 0l2-2C15.14 9.14 8.87 9.14 5 13z"/></svg></span>
        </div>
        <div class="stat-value" id="onlineDevices">—</div>
        <div class="stat-trend"><span class="stat-trend-label" id="onlineDevicesSub">0% online</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-header">
            <span class="stat-card-label">Vouchers Sold</span>
            <span class="stat-icon yellow"><svg viewBox="0 0 24 24"><path d="M20 6h-2.18c.07-.44.18-.88.18-1.35C18 2.07 15.93 0 13.35 0c-1.49 0-2.81.7-3.7 1.79L9 3l-.65-.21C7.48.7 6.16 0 4.65 0 2.07 0 0 2.07 0 4.65c0 .47.11.91.18 1.35H0v14h20V6zm-7-4.35c.55-.68 1.38-1.08 2.28-1.08 1.56 0 2.82 1.25 2.82 2.8 0 .48-.13.91-.31 1.31L11.38 4.5V2.73l1.62-.08zM1.85 4.65c0-1.55 1.26-2.8 2.82-2.8.9 0 1.73.4 2.28 1.08v1.77l-1.62.08-1.17.19c-.18-.4-.31-.83-.31-1.32zM18 18H2V8h16v10z"/></svg></span>
        </div>
        <div class="stat-value" id="totalVouchers">—</div>
        <div class="stat-trend"><span class="stat-trend-label" id="monthVouchersSub">This month: —</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-header">
            <span class="stat-card-label">Today's Revenue</span>
            <span class="stat-icon teal"><svg viewBox="0 0 24 24"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg></span>
        </div>
        <div class="stat-value" id="todayRevenue">—</div>
        <div class="stat-trend"><span class="stat-trend-label" id="activeVouchersSub">Active vouchers: —</span></div>
    </div>
</div>

<div class="two-col">
    <div class="stack">

        <!-- Revenue Trend -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">Revenue Trend</div>
                <span class="card-subtitle">Last 6 months</span>
            </div>
            <div class="card-body" style="height:280px;position:relative">
                <canvas id="revenueChart" style="width:100%;height:100%;"></canvas>
                <div id="revenueChartEmpty" class="empty-state" style="display:none">
                    <div class="empty-state-icon"><svg viewBox="0 0 24 24"><path d="M5 9.2h3V19H5V9.2zM10.6 5h2.8v14h-2.8V5zm5.6 8H19v6h-2.8v-6z"/></svg></div>
                    <h3>No revenue data yet</h3>
                    <p>Revenue will appear here once payments are recorded.</p>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card">
            <div class="card-header"><div class="card-title">Quick Actions</div></div>
            <div class="card-body">
                <div class="qa">
                    <a href="connect_mikrotik">
                        <svg viewBox="0 0 24 24"><path d="M13 7h-2v4H7v2h4v4h2v-4h4v-2h-4V7zm-1-5C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/></svg>
                        Add New Device
                    </a>
                    <a href="vouchers">
                        <svg viewBox="0 0 24 24"><path d="M20 6h-2.18c.07-.44.18-.88.18-1.35C18 2.07 15.93 0 13.35 0c-1.49 0-2.81.7-3.7 1.79L9 3l-.65-.21C7.48.7 6.16 0 4.65 0 2.07 0 0 2.07 0 4.65c0 .47.11.91.18 1.35H0v14h20V6zm-7-4.35c.55-.68 1.38-1.08 2.28-1.08 1.56 0 2.82 1.25 2.82 2.8 0 .48-.13.91-.31 1.31L11.38 4.5V2.73l1.62-.08zM1.85 4.65c0-1.55 1.26-2.8 2.82-2.8.9 0 1.73.4 2.28 1.08v1.77l-1.62.08-1.17.19c-.18-.4-.31-.83-.31-1.32zM18 18H2V8h16v10z"/></svg>
                        Generate Vouchers
                    </a>
                    <a href="revenue">
                        <svg viewBox="0 0 24 24"><path d="M5 9.2h3V19H5V9.2zM10.6 5h2.8v14h-2.8V5zm5.6 8H19v6h-2.8v-6z"/></svg>
                        View Reports
                    </a>
                    <a href="users">
                        <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                        Manage Users
                    </a>
                    <a href="billing">
                        <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
                        Billing
                    </a>
                </div>
            </div>
        </div>

    </div>

    <div class="stack">
        <!-- Devices List -->
        <div class="card">
            <div class="card-header"><div class="card-title">Devices</div></div>
            <div class="card-body" style="padding-top:8px">
                <div id="devicesList"></div>
                <div id="devicesEmpty" class="empty-state" style="display:none">
                    <div class="empty-state-icon"><svg viewBox="0 0 24 24"><path d="M20 6h-2.18c.07-.44.18-.88.18-1.35C18 2.07 15.93 0 13.35 0c-1.49 0-2.81.7-3.7 1.79L9 3l-.65-.21C7.48.7 6.16 0 4.65 0 2.07 0 0 2.07 0 4.65c0 .47.11.91.18 1.35H0v14h20V6zm-7-4.35c.55-.68 1.38-1.08 2.28-1.08 1.56 0 2.82 1.25 2.82 2.8 0 .48-.13.91-.31 1.31L11.38 4.5V2.73l1.62-.08zM1.85 4.65c0-1.55 1.26-2.8 2.82-2.8.9 0 1.73.4 2.28 1.08v1.77l-1.62.08-1.17.19c-.18-.4-.31-.83-.31-1.32zM18 18H2V8h16v10z"/></svg></div>
                    <h3>No devices yet</h3>
                    <p>Connect a MikroTik device to get started.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
let revenueChart = null;

async function loadDashboard() {
    try {
        const res = await fetch('/api/mikrotik.php?action=dashboard_stats');
        const d = await res.json();
        if (d.error) throw new Error(d.error);

        document.getElementById('totalRevenue').textContent = window.APP_CURRENCY + ' ' + formatNum(d.total_revenue);
        document.getElementById('monthRevenueSub').textContent = 'This month: ' + window.APP_CURRENCY + ' ' + formatNum(d.month_revenue);
        document.getElementById('todayRevenue').textContent = window.APP_CURRENCY + ' ' + formatNum(d.today_revenue);
        document.getElementById('activeVouchersSub').textContent = 'Active vouchers: ' + d.active_vouchers;

        document.getElementById('totalVouchers').textContent = d.total_vouchers;
        document.getElementById('monthVouchersSub').textContent = 'This month: ' + d.month_vouchers;

        document.getElementById('onlineDevices').textContent = d.online_devices + ' / ' + d.total_devices;
        const pct = d.total_devices > 0 ? Math.round((d.online_devices / d.total_devices) * 100) : 0;
        document.getElementById('onlineDevicesSub').textContent = pct + '% online';

        renderDevices(d.devices);
        renderChart(d.revenue_by_month);

        const el = document.getElementById('lastUpdated');
        if (el) el.textContent = 'Last updated: ' + new Date().toLocaleTimeString();
    } catch (e) {
        console.error('Dashboard load error:', e);
    }
}

function formatNum(n) {
    if (n === 0 || n === '0') return '0';
    return Number(n).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

function renderDevices(devices) {
    const el = document.getElementById('devicesList');
    const empty = document.getElementById('devicesEmpty');
    if (!devices || devices.length === 0) {
        el.innerHTML = '';
        empty.style.display = 'block';
        return;
    }
    empty.style.display = 'none';
    el.innerHTML = devices.map(d => `
        <div class="device-row" onclick="location.href='mikrotik_devices?id=${d.id}'">
            <div style="display:flex;align-items:center;">
                <div class="device-dot ${d.online ? 'on' : 'off'}"></div>
                <div>
                    <div style="font-weight:600;font-size:13px;">${escHtml(d.name)}</div>
                    <div style="font-size:12px;color:var(--on-surface-med);">${escHtml(d.location || 'No location')}</div>
                </div>
            </div>
            <span class="chip ${d.online ? 'active' : 'expired'}"><span class="chip-dot"></span>${d.online ? 'Online' : 'Offline'}</span>
        </div>
    `).join('');
}

function renderChart(data) {
    const canvas = document.getElementById('revenueChart');
    const empty = document.getElementById('revenueChartEmpty');
    if (!data || data.length === 0) {
        canvas.style.display = 'none';
        empty.style.display = 'flex';
        return;
    }
    canvas.style.display = 'block';
    empty.style.display = 'none';

    const sorted = data.sort((a, b) => a.month.localeCompare(b.month));
    const labels = sorted.map(d => {
        const [y, m] = d.month.split('-');
        return new Date(y, m - 1).toLocaleDateString('en', { month: 'short', year: '2-digit' });
    });
    const values = sorted.map(d => d.revenue);

    if (revenueChart) revenueChart.destroy();

    revenueChart = new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: 'Revenue (' + window.APP_CURRENCY + ')',
                data: values,
                borderColor: '#1A73E8',
                backgroundColor: 'rgba(26,115,232,0.08)',
                fill: true,
                tension: 0.4,
                borderWidth: 2.5,
                pointRadius: 4,
                pointBackgroundColor: '#1A73E8',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: v => window.APP_CURRENCY + ' ' + formatNum(v) },
                    grid: { color: '#f0f0f0' }
                },
                x: { grid: { display: false } }
            }
        }
    });
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

loadDashboard();
setInterval(loadDashboard, 30000);
document.addEventListener('visibilitychange', function () {
    if (!document.hidden) loadDashboard();
});
window.addEventListener('focus', loadDashboard);
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
