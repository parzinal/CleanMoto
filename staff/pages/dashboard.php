<?php
require_once __DIR__ . '/../../config/config.php';

$action = $_GET['action'] ?? null;
if (!isLoggedIn() || getUserRole() !== 'staff') {
    if ($action === 'get_filtered_data') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'unauthenticated']);
        exit;
    }
    redirect('login.php');
}

$db = Database::getInstance()->getConnection();

if (isset($_GET['action']) && $_GET['action'] === 'get_filtered_data') {
    header('Content-Type: application/json');

    $period   = $_GET['period']    ?? 'today';
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo   = $_GET['date_to']   ?? null;

    if ($dateFrom && $dateTo) {
        $startDate = $dateFrom;
        $endDate   = $dateTo;
    } else {
        switch ($period) {
            case 'today': $startDate = date('Y-m-d');       $endDate = date('Y-m-d');       break;
            case 'week':  $startDate = date('Y-m-d', strtotime('monday this week')); $endDate = date('Y-m-d', strtotime('sunday this week')); break;
            case 'month': $startDate = date('Y-m-01');      $endDate = date('Y-m-t');       break;
            case 'year':  $startDate = date('Y-01-01');     $endDate = date('Y-12-31');     break;
            default:      $startDate = date('Y-m-d');       $endDate = date('Y-m-d');
        }
    }

    try {
        $tablesExist = true;
        try { $db->query("SELECT 1 FROM appointments LIMIT 1"); $db->query("SELECT 1 FROM services LIMIT 1"); }
        catch (PDOException $e) { $tablesExist = false; }

        if (!$tablesExist) {
            echo json_encode(['success'=>true,'statistics'=>['pending'=>0,'in_progress'=>0,'completed'=>0,'revenue'=>0],'appointments'=>[],'chart_data'=>[],'period_label'=>ucfirst($period),'date_range'=>['from'=>$startDate,'to'=>$endDate]]);
            exit;
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date BETWEEN ? AND ? AND status IN ('pending','confirmed')");
        $stmt->execute([$startDate,$endDate]); $pendingCount = $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date BETWEEN ? AND ? AND status='in_progress'");
        $stmt->execute([$startDate,$endDate]); $inProgressCount = $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE DATE(updated_at) BETWEEN ? AND ? AND status='completed'");
        $stmt->execute([$startDate,$endDate]); $completedCount = $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COALESCE(SUM(s.price*a.quantity),0) FROM appointments a LEFT JOIN services s ON a.service_id=s.id WHERE DATE(a.updated_at) BETWEEN ? AND ? AND a.status='completed'");
        $stmt->execute([$startDate,$endDate]); $revenueAmount = $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT a.*, s.label AS service_label, s.name AS service_name, s.price AS service_price FROM appointments a LEFT JOIN services s ON a.service_id=s.id WHERE a.appointment_date BETWEEN ? AND ? AND a.status IN ('pending','confirmed','in_progress') ORDER BY a.appointment_date ASC, a.appointment_time ASC LIMIT 10");
        $stmt->execute([$startDate,$endDate]); $appointmentsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT DATE(a.updated_at) AS date, COUNT(*) AS total_appointments, SUM(CASE WHEN a.status='completed' THEN 1 ELSE 0 END) AS completed_appointments, COALESCE(SUM(CASE WHEN a.status='completed' THEN s.price*a.quantity ELSE 0 END),0) AS revenue FROM appointments a LEFT JOIN services s ON a.service_id=s.id WHERE DATE(a.updated_at) BETWEEN ? AND ? GROUP BY DATE(a.updated_at) ORDER BY DATE(a.updated_at) ASC");
        $stmt->execute([$startDate,$endDate]); $chartDataResult = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success'=>true,'statistics'=>['pending'=>(int)$pendingCount,'in_progress'=>(int)$inProgressCount,'completed'=>(int)$completedCount,'revenue'=>(float)$revenueAmount],'appointments'=>$appointmentsList,'chart_data'=>$chartDataResult,'period_label'=>ucfirst($period),'date_range'=>['from'=>$startDate,'to'=>$endDate]]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

$todayPending=0; $todayInProgress=0; $todayCompleted=0; $todayRevenue=0; $totalRevenue=0; $upcomingAppointments=[];

try {
    $tableCheck = $db->query("SHOW TABLES LIKE 'appointments'")->fetch();
    if ($tableCheck) {
        $todayPending    = $db->query("SELECT COUNT(*) FROM appointments WHERE DATE(appointment_date)=CURDATE() AND status IN ('pending','confirmed')")->fetchColumn();
        $todayInProgress = $db->query("SELECT COUNT(*) FROM appointments WHERE DATE(appointment_date)=CURDATE() AND status='in_progress'")->fetchColumn();
        $todayCompleted  = $db->query("SELECT COUNT(*) FROM appointments WHERE DATE(updated_at)=CURDATE() AND status='completed'")->fetchColumn();
        $todayRevenue    = $db->query("SELECT COALESCE(SUM(s.price*a.quantity),0) FROM appointments a LEFT JOIN services s ON a.service_id=s.id WHERE DATE(a.updated_at)=CURDATE() AND a.status='completed'")->fetchColumn();
        $totalRevenue    = $db->query("SELECT COALESCE(SUM(s.price*a.quantity),0) FROM appointments a LEFT JOIN services s ON a.service_id=s.id WHERE a.status='completed'")->fetchColumn();
        $upcomingAppointments = $db->query("SELECT a.*, s.label AS service_label, s.name AS service_name, s.price AS service_price FROM appointments a LEFT JOIN services s ON a.service_id=s.id WHERE a.appointment_date>=CURDATE() AND a.status IN ('pending','confirmed','in_progress') ORDER BY a.appointment_date ASC, a.appointment_time ASC LIMIT 10")->fetchAll();
    }
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="dashboard-layout">
    <?php include '../includes/sidebar.php'; ?>
    <?php include '../includes/header.php'; ?>
    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <main class="main-content">

        <!-- Page header -->
        <div class="sd-page-header">
            <div class="sd-header-text">
                <h1>Dashboard</h1>
                <p>Welcome back, <strong><?php echo htmlspecialchars($_SESSION['user_name']); ?></strong>!</p>
            </div>
            <a href="scanner.php" class="sd-qr-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/>
                    <path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/>
                    <rect x="7" y="7" width="3" height="3"/><rect x="14" y="7" width="3" height="3"/>
                    <rect x="7" y="14" width="3" height="3"/><rect x="14" y="14" width="3" height="3"/>
                </svg>
                Scan QR
            </a>
        </div>

        <!-- Filter controls -->
        <div class="sd-filter-bar">
            <div class="sd-filter-scroll">
                <div class="sd-filter-btns">
                    <button class="sd-fbtn active" data-period="today">Today</button>
                    <button class="sd-fbtn" data-period="week">Week</button>
                    <button class="sd-fbtn" data-period="month">Month</button>
                    <button class="sd-fbtn" data-period="year">Year</button>
                </div>
                <div class="swipe-hint">Swipe for more →</div>
            </div>
            <div class="sd-date-group">
                <label>From</label>
                <input type="date" id="dateFrom" class="sd-date-input">
                <label>To</label>
                <input type="date" id="dateTo" class="sd-date-input">
                <button class="sd-fbtn sd-apply" onclick="applyDateFilter()">Apply</button>
                <button class="sd-fbtn sd-clear"  onclick="clearDateFilter()">Clear</button>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="sd-stats-grid">

            <div class="sd-stat-card sd-stat-orange">
                <div class="sd-stat-top">
                    <span class="sd-stat-label">Pending</span>
                    <div class="sd-stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                </div>
                <div class="sd-stat-val" id="stat-pending"><?php echo number_format($todayPending); ?></div>
                <div class="sd-stat-note">Waiting to be serviced</div>
            </div>

            <div class="sd-stat-card sd-stat-blue">
                <div class="sd-stat-top">
                    <span class="sd-stat-label">In Progress</span>
                    <div class="sd-stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                    </div>
                </div>
                <div class="sd-stat-val" id="stat-inprogress"><?php echo number_format($todayInProgress); ?></div>
                <div class="sd-stat-note">Currently being serviced</div>
            </div>

            <div class="sd-stat-card sd-stat-green">
                <div class="sd-stat-top">
                    <span class="sd-stat-label">Completed</span>
                    <div class="sd-stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    </div>
                </div>
                <div class="sd-stat-val" id="stat-completed"><?php echo number_format($todayCompleted); ?></div>
                <div class="sd-stat-note">Finished in period</div>
            </div>

            <div class="sd-stat-card sd-stat-purple">
                <div class="sd-stat-top">
                    <span class="sd-stat-label">Revenue</span>
                    <div class="sd-stat-icon">
                        <span style="font-size:1rem;font-weight:800;line-height:1;">₱</span>
                    </div>
                </div>
                <div class="sd-stat-val sd-stat-val-sm" id="stat-revenue">₱<?php echo number_format($todayRevenue, 2); ?></div>
                <div class="sd-stat-note">Total: ₱<?php echo number_format($totalRevenue, 2); ?></div>
            </div>

        </div>

        <!-- Chart -->
        <div class="sd-card">
            <div class="sd-card-header">
                <h3>Revenue Trend</h3>
                <small class="sd-muted">Bookings &amp; revenue over selected period</small>
            </div>
            <div class="sd-chart-wrap">
                <canvas id="salesTrendChart"></canvas>
            </div>
        </div>

        <!-- Appointment queue -->
        <div class="sd-card">
            <div class="sd-card-header sd-card-header-flex">
                <div>
                    <h3>
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--primary-red,#E63946)" stroke-width="2" style="vertical-align:middle;margin-right:6px;">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                        </svg>
                        Appointment Queue
                    </h3>
                    <small class="sd-muted">Active &amp; upcoming appointments</small>
                </div>
                <a href="appointments.php" class="sd-view-all">View all →</a>
            </div>
            <div class="sd-table-wrap">
                <table class="sd-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Customer</th>
                            <th>Service</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="appointments-tbody">
                        <?php if (empty($upcomingAppointments)): ?>
                        <tr><td colspan="7" class="sd-empty-cell">No upcoming appointments. New bookings will appear here.</td></tr>
                        <?php else: ?>
                        <?php foreach ($upcomingAppointments as $apt):
                            $statusCls   = $apt['status'];
                            $statusLabel = ucfirst(str_replace('_',' ',$apt['status']));
                            if ($apt['status']==='confirmed') $statusLabel='Checked-in';
                        ?>
                        <tr>
                            <td><span class="sd-ref">APT-<?php echo str_pad($apt['id'],6,'0',STR_PAD_LEFT); ?></span></td>
                            <td><?php echo htmlspecialchars($apt['full_name']); ?></td>
                            <td><?php echo htmlspecialchars(($apt['service_label']??'').': '.($apt['service_name']??'N/A')); ?></td>
                            <td><?php echo date('M d, Y',strtotime($apt['appointment_date'])); ?></td>
                            <td><?php echo date('h:i A',strtotime($apt['appointment_time'])); ?></td>
                            <td><span class="sd-badge sd-badge-<?php echo $statusCls; ?>"><?php echo $statusLabel; ?></span></td>
                            <td><a href="appointments.php?scanned=<?php echo $apt['id']; ?>" class="sd-update-btn">Update</a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<script>
let currentPeriod = 'today';
let salesChart    = null;

document.querySelectorAll('.sd-fbtn[data-period]').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.sd-fbtn[data-period]').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        currentPeriod = this.dataset.period;
        loadFilteredData(currentPeriod);
    });
});

function applyDateFilter() {
    const df = document.getElementById('dateFrom').value;
    const dt = document.getElementById('dateTo').value;
    if (!df || !dt) { alert('Please select both dates'); return; }
    if (df > dt)    { alert('Start date must be before end date'); return; }
    document.querySelectorAll('.sd-fbtn[data-period]').forEach(b => b.classList.remove('active'));
    loadFilteredData(null, df, dt);
}

function clearDateFilter() {
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value   = '';
    document.querySelector('.sd-fbtn[data-period="today"]').click();
}

function loadFilteredData(period='today', dateFrom=null, dateTo=null) {
    ['stat-pending','stat-inprogress','stat-completed','stat-revenue'].forEach(id => {
        const el = document.getElementById(id); if (el) el.textContent = '…';
    });

    let url = 'dashboard.php?action=get_filtered_data';
    if (dateFrom && dateTo) url += `&date_from=${dateFrom}&date_to=${dateTo}`;
    else                    url += `&period=${period||'today'}`;

    fetch(url, { credentials:'same-origin' })
        .then(r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
        .then(data => {
            if (!data.success && data.message==='unauthenticated') {
                alert('Session expired. Redirecting to login.');
                window.location.href='../../login.php'; return;
            }
            if (data.success) {
                updateStats(data.statistics);
                updateQueue(data.appointments);
                updateChart(data.chart_data);
            }
        })
        .catch(err => alert('Failed to load data: ' + err.message));
}

function updateStats(stats) {
    document.getElementById('stat-pending').textContent   = Number(stats.pending).toLocaleString();
    document.getElementById('stat-inprogress').textContent = Number(stats.in_progress).toLocaleString();
    document.getElementById('stat-completed').textContent  = Number(stats.completed).toLocaleString();
    document.getElementById('stat-revenue').textContent    = '₱' + Number(stats.revenue).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
}

function updateQueue(appointments) {
    const tbody = document.getElementById('appointments-tbody');
    if (!appointments || !appointments.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="sd-empty-cell">No appointments found for this period</td></tr>';
        return;
    }
    const statusMap = {
        pending:     {cls:'pending',     label:'Pending'},
        confirmed:   {cls:'confirmed',   label:'Checked-in'},
        in_progress: {cls:'in_progress', label:'In Progress'},
        completed:   {cls:'completed',   label:'Completed'},
        cancelled:   {cls:'cancelled',   label:'Cancelled'},
    };
    tbody.innerHTML = appointments.map(apt => {
        const ref  = 'APT-' + String(apt.id).padStart(6,'0');
        const dStr = new Date(apt.appointment_date+'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
        const [h,m]= apt.appointment_time.substring(0,5).split(':');
        const tStr = (h%12||12)+':'+m+(h>=12?' PM':' AM');
        const s    = statusMap[apt.status]||{cls:apt.status,label:apt.status};
        const svc  = esc((apt.service_label||'')+': '+(apt.service_name||'N/A'));
        return `<tr>
            <td><span class="sd-ref">${ref}</span></td>
            <td>${esc(apt.full_name)}</td>
            <td>${svc}</td>
            <td>${dStr}</td>
            <td>${tStr}</td>
            <td><span class="sd-badge sd-badge-${s.cls}">${s.label}</span></td>
            <td><a href="appointments.php?scanned=${apt.id}" class="sd-update-btn">Update</a></td>
        </tr>`;
    }).join('');
}

function updateChart(chartData) {
    const canvas = document.getElementById('salesTrendChart');
    if (!canvas) return;
    if (!chartData || !chartData.length) {
        if (salesChart) { salesChart.destroy(); salesChart=null; }
        canvas.getContext('2d').clearRect(0,0,canvas.width,canvas.height);
        return;
    }

    const isLight = document.documentElement.getAttribute('data-theme')==='light';
    const tickClr  = isLight ? '#94a3b8' : '#a0a8b8';
    const gridClr  = isLight ? 'rgba(15,23,42,0.06)' : 'rgba(255,255,255,0.05)';
    const tooltipBg= isLight ? '#fff' : '#1e2533';
    const tooltipTx= isLight ? '#0f172a' : '#e2e8f0';
    const tooltipBd= isLight ? '#e2e8f0' : '#2d3748';

    const labels        = chartData.map(d => new Date(d.date+'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric'}));
    const revenueData   = chartData.map(d => parseFloat(d.revenue));
    const completedData = chartData.map(d => parseInt(d.completed_appointments));

    if (salesChart) salesChart.destroy();

    const ctx  = canvas.getContext('2d');
    const grad = ctx.createLinearGradient(0,0,0,canvas.height);
    grad.addColorStop(0,'rgba(230,57,70,0.9)');
    grad.addColorStop(1,'rgba(230,57,70,0.35)');

    salesChart = new Chart(ctx, {
        data: {
            labels,
            datasets: [
                { type:'bar',  label:'Revenue (₱)',  data:revenueData,   backgroundColor:grad,           borderColor:'rgba(230,57,70,0.95)', borderWidth:0,   yAxisID:'y',  order:2, borderRadius:8, barPercentage:0.6, categoryPercentage:0.7, maxBarThickness:48 },
                { type:'line', label:'Completed',     data:completedData, borderColor:'#FFA07A',          backgroundColor:'rgba(255,160,122,0.06)', tension:0.3, fill:false, pointRadius:5, pointHoverRadius:7, borderWidth:2, yAxisID:'y1', order:1 }
            ]
        },
        options: {
            responsive:true, maintainAspectRatio:false,
            interaction:{ mode:'index', intersect:false },
            plugins: {
                legend:{ display:true, position:'top', labels:{ color:tickClr, usePointStyle:true, padding:12 }},
                tooltip:{ backgroundColor:tooltipBg, titleColor:tooltipTx, bodyColor:tickClr, borderColor:tooltipBd, borderWidth:1, padding:10, cornerRadius:8,
                    callbacks:{ label(c){ return c.dataset.type==='bar'?c.dataset.label+': ₱'+Number(c.parsed.y).toLocaleString('en-US',{minimumFractionDigits:2}):c.dataset.label+': '+Number(c.parsed.y).toLocaleString(); }}
                }
            },
            scales: {
                y:  { type:'linear', display:true, position:'left',  beginAtZero:true, grid:{color:gridClr}, border:{display:false}, ticks:{color:tickClr, callback:v=>'₱'+Number(v).toLocaleString()} },
                y1: { type:'linear', display:true, position:'right', beginAtZero:true, grid:{drawOnChartArea:false}, border:{display:false}, ticks:{color:tickClr, precision:0} },
                x:  { grid:{display:false}, border:{display:false}, ticks:{color:tickClr, maxRotation:45, minRotation:0} }
            }
        }
    });
}

function esc(t){ const d=document.createElement('div'); d.textContent=t||''; return d.innerHTML; }

// Auto-load month on start
document.addEventListener('DOMContentLoaded', () => {
    currentPeriod = 'month';
    document.querySelectorAll('.sd-fbtn[data-period]').forEach(b=>b.classList.remove('active'));
    const mb = document.querySelector('.sd-fbtn[data-period="month"]');
    if (mb) mb.classList.add('active');
    loadFilteredData('month');
});
</script>

<style>
/*
 * ─────────────────────────────────────────────────────────────
 *  STAFF DASHBOARD — scoped styles
 *  Dark mode = default (global CSS vars)
 *  Light mode = [data-theme="light"] overrides only
 *  NO :root overrides
 * ─────────────────────────────────────────────────────────────
 */

/* ── Scoped tokens (dark defaults) ── */
.main-content {
    --sd-card-bg:      var(--dark-card, #1a2030);
    --sd-card-border:  var(--border-color, rgba(255,255,255,0.08));
    --sd-input-bg:     var(--dark-bg, #111827);
    --sd-text:         var(--cream, #f1f5f9);
    --sd-muted:        var(--gray-text, #94a3b8);
    --sd-row-hover:    rgba(230,57,70,0.05);
    --sd-th-bg:        rgba(230,57,70,0.1);
    --sd-td-border:    rgba(255,255,255,0.05);
    --sd-filter-bg:    var(--dark-card, #1a2030);
    --sd-fbtn-bg:      transparent;
    --sd-fbtn-color:   var(--gray-text, #94a3b8);
    font-family: 'Plus Jakarta Sans', sans-serif;
}

/* ── Light mode token overrides ── */
[data-theme="light"] .main-content {
    --sd-card-bg:      #ffffff;
    --sd-card-border:  #e2e8f0;
    --sd-input-bg:     #f8fafc;
    --sd-text:         #0f172a;
    --sd-muted:        #94a3b8;
    --sd-row-hover:    #fafbff;
    --sd-th-bg:        rgba(230,57,70,0.06);
    --sd-td-border:    #f1f5f9;
    --sd-filter-bg:    #ffffff;
    --sd-fbtn-bg:      transparent;
    --sd-fbtn-color:   #64748b;
}

/* ── Layout ── */
.main-content {
    margin-left: 260px;
    padding: calc(var(--topbar-height,64px) + 20px) 28px 28px;
    height: calc(100vh - var(--topbar-height,64px));
    box-sizing: border-box;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    transition: margin-left 200ms ease;
}
.main-content.sidebar-collapsed { margin-left: 80px; }

/* ── Page header ── */
.sd-page-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    gap: 1rem; margin-bottom: 1.4rem; flex-wrap: wrap;
}
.sd-header-text h1 {
    font-size: 1.6rem; font-weight: 800; margin: 0 0 4px;
    letter-spacing: -.4px; color: var(--sd-text);
}
.sd-header-text p { margin: 0; font-size: .9rem; color: var(--sd-muted); }
.sd-header-text p strong { color: var(--sd-text); font-weight: 600; }

.sd-qr-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 18px;
    background: linear-gradient(135deg,#e63946,#c1121f);
    color: #fff; border: none; border-radius: 10px;
    font-size: .9rem; font-weight: 700; text-decoration: none;
    cursor: pointer; transition: all .2s;
    box-shadow: 0 4px 15px rgba(230,57,70,.3); margin-top: 30px;
    font-family: 'Plus Jakarta Sans', sans-serif;
}
.sd-qr-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(230,57,70,.45); }

/* ── Filter bar ── */
.sd-filter-bar {
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 1rem; margin-bottom: 1.4rem;
    padding: .9rem 1.2rem;
    background: var(--sd-filter-bg);
    border: 1px solid var(--sd-card-border);
    border-radius: 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,.08);
}
[data-theme="light"] .sd-filter-bar { box-shadow: 0 1px 3px rgba(15,23,42,.06); }

.sd-filter-scroll {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.sd-filter-btns { display: flex; gap: .45rem; }

.sd-fbtn {
    padding: .45rem .95rem;
    border: 1px solid var(--sd-card-border);
    background: var(--sd-fbtn-bg);
    color: var(--sd-fbtn-color);
    border-radius: 8px; font-size: .84rem; font-weight: 600;
    cursor: pointer; transition: all .2s;
    font-family: 'Plus Jakarta Sans', sans-serif;
}
.sd-fbtn:hover  { border-color: #e63946; color: #e63946; }
.sd-fbtn.active { background: #e63946; border-color: #e63946; color: #fff; }

.sd-date-group { display: flex; align-items: center; gap: .65rem; flex-wrap: wrap; }
.sd-date-group label { font-size: .82rem; color: var(--sd-muted); font-weight: 500; }
.sd-date-input {
    padding: .45rem .75rem;
    border: 1px solid var(--sd-card-border);
    background: var(--sd-input-bg);
    color: var(--sd-text);
    border-radius: 8px; font-size: .84rem;
    font-family: 'Plus Jakarta Sans', sans-serif;
}
.sd-date-input:focus { outline: none; border-color: #e63946; }

.sd-apply { background: #e63946 !important; border-color: #e63946 !important; color: #fff !important; }
.sd-apply:hover { background: #c5303b !important; }
.sd-clear { background: var(--sd-input-bg) !important; color: var(--sd-muted) !important; }
.sd-clear:hover { border-color: #e63946 !important; color: #e63946 !important; }

/* ── Stat cards ── */
.sd-stats-grid {
    display: grid; grid-template-columns: repeat(4,1fr);
    gap: 1rem; margin-bottom: 1.2rem;
}
@media (max-width:1280px){ .sd-stats-grid{ grid-template-columns:repeat(2,1fr); } }
@media (max-width:480px) { .sd-stats-grid{ grid-template-columns:1fr; } }

.sd-stat-card {
    background: var(--sd-card-bg);
    border: 1px solid var(--sd-card-border);
    border-radius: 16px;
    padding: 1.2rem 1.3rem 1.1rem;
    box-shadow: 0 1px 4px rgba(0,0,0,.18);
    transition: transform .22s, box-shadow .22s, border-color .22s;
    position: relative; overflow: hidden;
}
[data-theme="light"] .sd-stat-card { box-shadow: 0 1px 3px rgba(15,23,42,.07); }

.sd-stat-card::before {
    content: ''; position: absolute; top:0; left:0; right:0; height:3px;
    border-radius: 16px 16px 0 0;
}
.sd-stat-orange::before { background: linear-gradient(90deg,#f59e0b,#fbbf24); }
.sd-stat-blue::before   { background: linear-gradient(90deg,#3b82f6,#60a5fa); }
.sd-stat-green::before  { background: linear-gradient(90deg,#10b981,#34d399); }
.sd-stat-purple::before { background: linear-gradient(90deg,#8b5cf6,#a78bfa); }

.sd-stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,.18); }
[data-theme="light"] .sd-stat-card:hover { box-shadow: 0 6px 18px rgba(15,23,42,.1); }

.sd-stat-top {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: .7rem;
}
.sd-stat-label {
    font-size: .75rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .05em; color: var(--sd-muted);
}
.sd-stat-icon {
    width: 36px; height: 36px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.sd-stat-orange .sd-stat-icon { background: rgba(245,158,11,.12); color: #f59e0b; }
.sd-stat-blue   .sd-stat-icon { background: rgba(59,130,246,.12);  color: #3b82f6; }
.sd-stat-green  .sd-stat-icon { background: rgba(16,185,129,.12);  color: #10b981; }
.sd-stat-purple .sd-stat-icon { background: rgba(139,92,246,.12);  color: #8b5cf6; }

.sd-stat-val {
    font-size: 2rem; font-weight: 800; line-height: 1;
    margin-bottom: .5rem; letter-spacing: -1px;
    color: var(--sd-text);
}
.sd-stat-val-sm { font-size: 1.45rem; letter-spacing: -.5px; }
.sd-stat-note   { font-size: .74rem; color: var(--sd-muted); }

/* ── Generic card ── */
.sd-card {
    background: var(--sd-card-bg);
    border: 1px solid var(--sd-card-border);
    border-radius: 16px;
    box-shadow: 0 1px 4px rgba(0,0,0,.15);
    overflow: hidden;
    margin-bottom: 1.2rem;
}
[data-theme="light"] .sd-card { box-shadow: 0 1px 3px rgba(15,23,42,.07); }

.sd-card-header {
    padding: 1rem 1.3rem;
    border-bottom: 1px solid var(--sd-card-border);
}
.sd-card-header h3 {
    margin: 0 0 2px; font-size: .97rem; font-weight: 700;
    color: var(--sd-text);
}
.sd-card-header-flex {
    display: flex; align-items: center; justify-content: space-between;
}
.sd-muted { font-size: .78rem; color: var(--sd-muted); }

.sd-view-all {
    font-size: .82rem; font-weight: 600;
    color: #e63946; text-decoration: none;
    white-space: nowrap; transition: opacity .2s;
}
.sd-view-all:hover { opacity: .7; }

/* ── Chart ── */
.sd-chart-wrap { padding: 1rem 1.3rem 1.2rem; height: 270px; }
#salesTrendChart { width:100% !important; height:100% !important; }

/* ── Table ── */
.sd-table-wrap { overflow-x: auto; }
.sd-table { width: 100%; border-collapse: collapse; font-size: .855rem; }

.sd-table thead th {
    padding: .85rem 1rem; text-align: left;
    font-size: .74rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    color: var(--sd-muted); background: var(--sd-th-bg);
    border-bottom: 1px solid var(--sd-card-border); white-space: nowrap;
}
.sd-table thead th:first-child { padding-left: 1.3rem; }
.sd-table thead th:last-child  { padding-right: 1.3rem; }

.sd-table tbody td {
    padding: .9rem 1rem; color: var(--sd-muted);
    border-bottom: 1px solid var(--sd-td-border);
    vertical-align: middle;
}
.sd-table tbody td:first-child { padding-left: 1.3rem; }
.sd-table tbody td:last-child  { padding-right: 1.3rem; }
.sd-table tbody tr:last-child td { border-bottom: none; }
.sd-table tbody tr:hover { background: var(--sd-row-hover); }
[data-theme="light"] .sd-table tbody td { color: #334155; }

.sd-ref { font-family: monospace; color: #e63946; font-weight: 700; }

.sd-empty-cell { text-align:center; padding:2.5rem 1rem; color:var(--sd-muted); font-size:.88rem; }

/* ── Status badges ── */
.sd-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px;
    font-size: .72rem; font-weight: 700; text-transform: capitalize;
    letter-spacing: .2px; white-space: nowrap;
}
.sd-badge::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; flex-shrink:0; }

/* dark */
.sd-badge-pending     { background:rgba(245,158,11,.15);  color:#f59e0b; }
.sd-badge-confirmed   { background:rgba(59,130,246,.15);  color:#60a5fa; }
.sd-badge-in_progress { background:rgba(59,130,246,.15);  color:#60a5fa; }
.sd-badge-completed   { background:rgba(16,185,129,.15);  color:#34d399; }
.sd-badge-cancelled   { background:rgba(239,68,68,.15);   color:#f87171; }
/* light */
[data-theme="light"] .sd-badge-pending     { background:rgba(245,158,11,.1);  color:#d97706; }
[data-theme="light"] .sd-badge-confirmed   { background:rgba(59,130,246,.1);  color:#2563eb; }
[data-theme="light"] .sd-badge-in_progress { background:rgba(59,130,246,.1);  color:#2563eb; }
[data-theme="light"] .sd-badge-completed   { background:rgba(16,185,129,.1);  color:#059669; }
[data-theme="light"] .sd-badge-cancelled   { background:rgba(239,68,68,.1);   color:#dc2626; }

/* ── Update button ── */
.sd-update-btn {
    display: inline-block; padding: 6px 14px;
    background: linear-gradient(135deg,#e63946,#c1121f);
    color: #fff; border-radius: 8px; font-size: .8rem;
    font-weight: 700; text-decoration: none;
    transition: all .2s; box-shadow: 0 2px 8px rgba(230,57,70,.25);
    font-family: 'Plus Jakarta Sans', sans-serif;
}
.sd-update-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(230,57,70,.4); }

/* ── Responsive ── */
@media (max-width:768px) {
    .main-content { margin-left:0 !important; padding: calc(var(--topbar-height,64px)+12px) 16px 20px; }
    .sd-filter-bar { flex-direction:column; align-items:stretch; }
    .sd-filter-scroll { width: 100%; }
    .sd-filter-btns {
        justify-content:flex-start;
        flex-wrap: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .sd-fbtn { flex: 0 0 auto; white-space: nowrap; }
    .sd-date-group  { justify-content:center; }
    .sd-page-header { flex-direction:column; }
    .sd-qr-btn      { width:100%; justify-content:center; margin-top:0; }

    .sd-table-wrap { overflow: visible; }
    .sd-table thead { display: none; }

    .sd-table,
    .sd-table tbody,
    .sd-table tr,
    .sd-table td {
        display: block;
        width: 100%;
    }

    .sd-table tr {
        background: var(--sd-input-bg);
        border: 1px solid var(--sd-card-border);
        border-radius: 12px;
        padding: 0.85rem;
        margin-bottom: 0.85rem;
    }

    .sd-table td {
        border-bottom: none;
        padding: 0.35rem 0;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.75rem;
    }

    .sd-table td::before {
        color: var(--sd-muted);
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 700;
        min-width: 84px;
        flex-shrink: 0;
    }

    .sd-table td:nth-child(1) {
        display: block;
        padding-top: 0;
        margin-bottom: 0.25rem;
    }

    .sd-table td:nth-child(1)::before { content: none; }
    .sd-table td:nth-child(2)::before { content: 'Customer'; }
    .sd-table td:nth-child(3)::before { content: 'Service'; }
    .sd-table td:nth-child(4)::before { content: 'Date'; }
    .sd-table td:nth-child(5)::before { content: 'Time'; }
    .sd-table td:nth-child(6)::before { content: 'Status'; }
    .sd-table td:nth-child(7)::before { content: 'Action'; }

    .sd-table td:nth-child(7) {
        display: block;
        padding-bottom: 0;
    }

    .sd-table td[colspan] {
        display: block;
        text-align: left;
        padding: 1rem 0;
    }

    .sd-table td[colspan]::before { content: none; }
}

@media (max-width:480px) {
    .sd-table td {
        flex-direction: column;
        gap: 0.2rem;
    }

    .sd-table td::before {
        min-width: 0;
    }
}
</style>

</body>
</html>