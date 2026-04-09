<?php
require_once __DIR__ . '/../../config/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

if (!isUser()) {
    if (isAdmin()) {
        redirect('admin/pages/dashboard.php');
    } elseif (isStaff()) {
        redirect('staff/pages/dashboard.php');
    }
}

$userName  = $_SESSION['user_name']  ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$userId    = $_SESSION['user_id']    ?? null;

$db = null;
try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) { /* ignore */ }

$upcomingCount      = 0;
$completedCount     = 0;
$totalSpent         = 0.00;
$servicesUsed       = 0;
$recentAppointments = [];
$monthlyAppointments = [];

if ($db && $userId) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM appointments WHERE user_id = ? AND status IN ('pending','confirmed') AND appointment_date >= CURDATE()");
        $stmt->execute([$userId]);
        $upcomingCount = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0;
    } catch (Exception $e) {}

    try {
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM appointments WHERE user_id = ? AND status = 'completed'");
        $stmt->execute([$userId]);
        $completedCount = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0;
    } catch (Exception $e) {}

    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(s.price * a.quantity),0) as total FROM appointments a LEFT JOIN services s ON a.service_id = s.id WHERE a.user_id = ? AND a.status = 'completed'");
        $stmt->execute([$userId]);
        $totalSpent = (float)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    } catch (Exception $e) {}

    try {
        $stmt = $db->prepare("SELECT COUNT(DISTINCT service_id) as cnt FROM appointments WHERE user_id = ?");
        $stmt->execute([$userId]);
        $servicesUsed = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0;
    } catch (Exception $e) {}

    try {
        $stmt = $db->prepare("SELECT a.id, a.appointment_date, a.appointment_time, a.helmet_type, a.quantity, a.status, a.created_at, s.name as service_name, s.label as service_label, s.price as service_price FROM appointments a LEFT JOIN services s ON a.service_id = s.id WHERE a.user_id = ? ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT 5");
        $stmt->execute([$userId]);
        $recentAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    try {
        $stmt = $db->prepare("SELECT DATE_FORMAT(appointment_date,'%Y-%m') as month, COUNT(*) as count FROM appointments WHERE user_id = ? AND appointment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY month ORDER BY month ASC");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $monthlyAppointments[$row['month']] = (int)$row['count'];
        }
    } catch (Exception $e) {}
}

$now = new DateTime();
for ($i = 5; $i >= 0; $i--) {
    $mk = (clone $now)->modify("-{$i} months")->format('Y-m');
    if (!isset($monthlyAppointments[$mk])) $monthlyAppointments[$mk] = 0;
}
ksort($monthlyAppointments);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
<div class="dashboard-layout">

    <?php include '../includes/sidebar.php'; ?>
    <?php include '../includes/header.php'; ?>
    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <main class="main-content">

        <!-- ── Page Header ── -->
        <div class="dashboard-header">
            <div class="header-text">
                <h1>My Dashboard</h1>
                <p>Welcome back, <strong><?php echo htmlspecialchars($userName); ?></strong>! Here's your activity overview.</p>
            </div>
            <a href="appointment.php" class="btn btn-primary btn-header">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/>
                    <line x1="8"  y1="2" x2="8"  y2="6"/>
                    <line x1="3"  y1="10" x2="21" y2="10"/>
                    <line x1="12" y1="15" x2="12" y2="19"/>
                    <line x1="10" y1="17" x2="14" y2="17"/>
                </svg>
                Book Appointment
            </a>
        </div>

        <!-- ── Stat Cards ── -->
        <div class="stats-cards">

            <div class="stat-card stat-upcoming">
                <div class="stat-card-top">
                    <span class="stat-label">Upcoming Appointments</span>
                    <div class="stat-icon-wrap icon-orange">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8"  y1="2" x2="8"  y2="6"/>
                            <line x1="3"  y1="10" x2="21" y2="10"/>
                            <polyline points="9 16 11 18 15 14"/>
                        </svg>
                    </div>
                </div>
                <div class="stat-value"><?php echo (int)$upcomingCount; ?></div>
                <div class="stat-footer">
                    <span class="stat-pill pill-orange">Active</span>
                    <a href="my-appointments.php" class="stat-link">View all →</a>
                </div>
            </div>

            <div class="stat-card stat-completed">
                <div class="stat-card-top">
                    <span class="stat-label">Completed</span>
                    <div class="stat-icon-wrap icon-green">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                            <polyline points="22 4 12 14.01 9 11.01"/>
                        </svg>
                    </div>
                </div>
                <div class="stat-value"><?php echo (int)$completedCount; ?></div>
                <div class="stat-footer">
                    <span class="stat-pill pill-green">All time</span>
                    <a href="my-appointments.php" class="stat-link">History →</a>
                </div>
            </div>

            <div class="stat-card stat-spent">
                <div class="stat-card-top">
                    <span class="stat-label">Total Spent</span>
                    <div class="stat-icon-wrap icon-purple">
                        <span class="peso-icon">₱</span>
                    </div>
                </div>
                <div class="stat-value stat-value-md">₱<?php echo number_format((float)$totalSpent, 2); ?></div>
                <div class="stat-footer">
                    <span class="stat-pill pill-purple">Lifetime</span>
                    <span class="stat-note">from completed jobs</span>
                </div>
            </div>

            <div class="stat-card stat-services">
                <div class="stat-card-top">
                    <span class="stat-label">Services Used</span>
                    <div class="stat-icon-wrap icon-blue">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                        </svg>
                    </div>
                </div>
                <div class="stat-value"><?php echo (int)$servicesUsed; ?></div>
                <div class="stat-footer">
                    <span class="stat-pill pill-blue">Unique</span>
                    <span class="stat-note">service types</span>
                </div>
            </div>

        </div>

        <!-- ── Chart + Quick Actions ── -->
        <div class="dashboard-grid">
            <div class="card card-chart">
                <div class="card-header">
                    <div>
                        <h3>Appointment Activity</h3>
                        <small class="text-muted-sm">Monthly bookings — last 6 months</small>
                    </div>
                </div>
                <div class="chart-wrap">
                    <canvas id="appointmentsChart"></canvas>
                </div>
            </div>

            <div class="card card-quick">
                <div class="card-header">
                    <h3>Quick Actions</h3>
                </div>
                <div class="card-body quick-actions-body">
                    <a href="appointment.php" class="quick-action-btn qa-primary">
                        <div class="qa-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                <line x1="16" y1="2" x2="16" y2="6"/>
                                <line x1="8"  y1="2" x2="8"  y2="6"/>
                                <line x1="3"  y1="10" x2="21" y2="10"/>
                                <line x1="12" y1="15" x2="12" y2="19"/>
                                <line x1="10" y1="17" x2="14" y2="17"/>
                            </svg>
                        </div>
                        <div class="qa-text">
                            <strong>Book Appointment</strong>
                            <span>Schedule a new service</span>
                        </div>
                        <svg class="qa-arrow" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </a>
                    <a href="my-appointments.php" class="quick-action-btn qa-secondary">
                        <div class="qa-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="8"  y1="6"  x2="21" y2="6"/>
                                <line x1="8"  y1="12" x2="21" y2="12"/>
                                <line x1="8"  y1="18" x2="21" y2="18"/>
                                <line x1="3"  y1="6"  x2="3.01" y2="6"/>
                                <line x1="3"  y1="12" x2="3.01" y2="12"/>
                                <line x1="3"  y1="18" x2="3.01" y2="18"/>
                            </svg>
                        </div>
                        <div class="qa-text">
                            <strong>My Appointments</strong>
                            <span>View booking history</span>
                        </div>
                        <svg class="qa-arrow" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </a>
                </div>
            </div>
        </div>

        <!-- ── Recent Appointments ── -->
        <div class="card card-history">
            <div class="card-header card-header-flex">
                <div>
                    <h3>Recent Appointments</h3>
                    <small class="text-muted-sm">Your 5 latest bookings</small>
                </div>
                <a href="my-appointments.php" class="btn-text-link">View all →</a>
            </div>
            <div class="card-body no-top-pad">
                <?php if (!empty($recentAppointments)): ?>
                <div class="table-responsive">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Service</th>
                            <th>Helmet Type</th>
                            <th>Qty</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAppointments as $apt):
                            $aptDate   = new DateTime($apt['appointment_date']);
                            $aptTime   = date('g:i A', strtotime($apt['appointment_time']));
                            $amount    = ($apt['service_price'] ?? 0) * ($apt['quantity'] ?? 1);
                            $statusCls = 'status-' . str_replace('_', '-', $apt['status']);
                        ?>
                        <tr>
                            <td>
                                <span class="date-main"><?php echo $aptDate->format('M d, Y'); ?></span>
                                <span class="date-time"><?php echo $aptTime; ?></span>
                            </td>
                            <td><?php echo htmlspecialchars(($apt['service_label'] ?? '') . ': ' . ($apt['service_name'] ?? 'N/A')); ?></td>
                            <td><?php echo htmlspecialchars($apt['helmet_type'] ?? '—'); ?></td>
                            <td><?php echo (int)$apt['quantity']; ?></td>
                            <td class="amount-cell">₱<?php echo number_format($amount, 2); ?></td>
                            <td><span class="status-badge <?php echo $statusCls; ?>"><?php echo ucfirst(str_replace('_', ' ', $apt['status'])); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                <div class="empty-state-dash">
                    <div class="empty-icon-dash">
                        <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8"  y1="2" x2="8"  y2="6"/>
                            <line x1="3"  y1="10" x2="21" y2="10"/>
                        </svg>
                    </div>
                    <p>No appointments yet.</p>
                    <a href="appointment.php" class="btn btn-primary btn-sm">Book your first appointment</a>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

<script>
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    const ctx = document.getElementById('appointmentsChart');
    if (!ctx) return;

    const rawLabels = <?php echo json_encode(array_keys($monthlyAppointments)); ?>;
    const data      = <?php echo json_encode(array_values($monthlyAppointments)); ?>;
    const isLight   = document.documentElement.getAttribute('data-theme') === 'light';

    const labels = rawLabels.map(ym => {
        const [y, m] = ym.split('-');
        return new Date(y, m - 1).toLocaleDateString('en-US', { month: 'short', year: '2-digit' });
    });

    const tickColor    = isLight ? '#94a3b8' : '#bfc6d2';
    const gridColor    = isLight ? 'rgba(15,23,42,0.05)' : 'rgba(255,255,255,0.03)';
    const tooltipBg    = isLight ? '#fff' : '#1e2533';
    const tooltipTitle = isLight ? '#0f172a' : '#e2e8f0';
    const tooltipBody  = isLight ? '#475569' : '#94a3b8';
    const tooltipBorder= isLight ? '#e2e8f0' : '#2d3748';

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Appointments',
                data,
                backgroundColor: (ctx) => {
                    const chart = ctx.chart;
                    const {ctx: c, chartArea} = chart;
                    if (!chartArea) return 'rgba(230,57,70,0.7)';
                    const gradient = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    gradient.addColorStop(0, 'rgba(230,57,70,0.85)');
                    gradient.addColorStop(1, 'rgba(230,57,70,0.2)');
                    return gradient;
                },
                borderColor: '#E63946',
                borderWidth: 0,
                borderRadius: 8,
                borderSkipped: false,
                barThickness: 32
            }]
        },
        options: {
            maintainAspectRatio: false,
            scales: {
                x: {
                    display: true,
                    grid: { display: false },
                    border: { display: false },
                    ticks: { color: tickColor, font: { family: "'Plus Jakarta Sans', sans-serif", size: 12 } }
                },
                y: {
                    display: true,
                    grid: { color: gridColor },
                    border: { display: false },
                    ticks: { color: tickColor, stepSize: 1, beginAtZero: true, font: { family: "'Plus Jakarta Sans', sans-serif", size: 11 } }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: tooltipBg,
                    titleColor: tooltipTitle,
                    bodyColor: tooltipBody,
                    borderColor: tooltipBorder,
                    borderWidth: 1,
                    padding: 10,
                    cornerRadius: 8,
                    titleFont: { family: "'Plus Jakarta Sans', sans-serif", weight: '600' },
                    bodyFont: { family: "'Plus Jakarta Sans', sans-serif" },
                    callbacks: {
                        label: ctx => ` ${ctx.raw} appointment${ctx.raw !== 1 ? 's' : ''}`
                    }
                }
            }
        }
    });
})();
</script>

<style>
/*
 * ─────────────────────────────────────────────────────────────
 *  USER DASHBOARD — scoped component styles
 *
 *  RULE: No bare :root overrides. All theme-sensitive values
 *  use CSS variables from the global style.css (dark mode
 *  defaults) and are overridden only under [data-theme="light"].
 *  This guarantees dark mode is never touched.
 * ─────────────────────────────────────────────────────────────
 */

/* Font scoped to this page's content area only */
.main-content, .main-content * {
    font-family: 'Plus Jakarta Sans', var(--font-family, sans-serif);
}

/* ── Layout ── */
.main-content {
    max-width: none;
    margin-left: 260px;
    padding: calc(var(--topbar-height, 64px) + 20px) 28px 28px;
    height: calc(100vh - var(--topbar-height, 64px));
    box-sizing: border-box;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    transition: margin-left 200ms ease;
}
.main-content.sidebar-collapsed { margin-left: 80px; }

/* ── Page header ── */
.dashboard-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.4rem;
    flex-wrap: wrap;
}
.header-text h1 {
    font-size: 1.6rem;
    font-weight: 800;
    margin: 0 0 4px;
    letter-spacing: -0.4px;
    color: var(--cream, #f1f5f9);
}
.header-text p          { margin: 0; font-size: 0.9rem; color: var(--gray-text, #94a3b8); }
.header-text p strong   { color: var(--cream, #e2e8f0); font-weight: 600; }

[data-theme="light"] .header-text h1        { color: #0f172a; }
[data-theme="light"] .header-text p         { color: #64748b; }
[data-theme="light"] .header-text p strong  { color: #334155; }

/* ── Stat cards grid ── */
.stats-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.2rem;
}

/* ── Stat card base — dark ── */
.stat-card {
    background: var(--dark-card, #1a2030);
    border: 1px solid var(--border-color, rgba(255,255,255,0.08));
    border-radius: 16px;
    padding: 1.2rem 1.3rem 1.1rem;
    box-shadow: 0 1px 4px rgba(0,0,0,0.25);
    transition: transform 0.22s, box-shadow 0.22s, border-color 0.22s;
    position: relative;
    overflow: hidden;
}
/* coloured top accent bar */
.stat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    border-radius: 16px 16px 0 0;
}
.stat-upcoming::before  { background: linear-gradient(90deg,#ff9800,#ffb74d); }
.stat-completed::before { background: linear-gradient(90deg,#10b981,#34d399); }
.stat-spent::before     { background: linear-gradient(90deg,#8b5cf6,#a78bfa); }
.stat-services::before  { background: linear-gradient(90deg,#3b82f6,#60a5fa); }

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(230,57,70,0.12);
    border-color: var(--primary-red, #E63946);
}

/* ── Stat card — light overrides ── */
[data-theme="light"] .stat-card {
    background: #ffffff;
    border-color: #e2e8f0;
    box-shadow: 0 1px 3px rgba(15,23,42,0.07), 0 1px 2px rgba(15,23,42,0.04);
}
[data-theme="light"] .stat-card:hover {
    border-color: #cbd5e1;
    box-shadow: 0 6px 18px rgba(15,23,42,0.1);
}

/* ── Stat card internals ── */
.stat-card-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.75rem;
}
.stat-label {
    font-size: 0.76rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--gray-text, #94a3b8);
}
.stat-icon-wrap {
    width: 38px; height: 38px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.icon-orange { background: rgba(255,152,0,0.12);  color: #f59e0b; }
.icon-green  { background: rgba(16,185,129,0.12); color: #10b981; }
.icon-purple { background: rgba(139,92,246,0.12); color: #8b5cf6; }
.icon-blue   { background: rgba(59,130,246,0.12); color: #3b82f6; }
.peso-icon   { font-size: 1.1rem; font-weight: 700; line-height: 1; }

.stat-value {
    font-size: 2rem;
    font-weight: 800;
    line-height: 1;
    margin-bottom: 0.85rem;
    letter-spacing: -1px;
    color: var(--cream, #f1f5f9);
}
.stat-value-md { font-size: 1.5rem; letter-spacing: -0.5px; }
[data-theme="light"] .stat-value { color: #0f172a; }

.stat-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
}
.stat-pill {
    display: inline-block;
    padding: 2px 9px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}
.pill-orange { background: rgba(255,152,0,0.12);  color: #f59e0b; }
.pill-green  { background: rgba(16,185,129,0.12); color: #10b981; }
.pill-purple { background: rgba(139,92,246,0.12); color: #8b5cf6; }
.pill-blue   { background: rgba(59,130,246,0.12); color: #3b82f6; }

.stat-link {
    font-size: 0.78rem; font-weight: 600;
    color: var(--primary-red, #E63946);
    text-decoration: none;
    transition: opacity .2s;
}
.stat-link:hover { opacity: .7; }
.stat-note { font-size: 0.74rem; color: var(--gray-text, #94a3b8); }

/* ── Dashboard grid ── */
.dashboard-grid {
    display: grid;
    grid-template-columns: 1fr 290px;
    gap: 1.1rem;
    margin-bottom: 1.2rem;
    align-items: start;
}

/* ── Card base — dark ── */
.main-content .card {
    background: var(--dark-card, #1a2030);
    border-radius: 16px;
    border: 1px solid var(--border-color, rgba(255,255,255,0.08));
    box-shadow: 0 1px 4px rgba(0,0,0,0.2);
    overflow: hidden;
}
[data-theme="light"] .main-content .card {
    background: #ffffff;
    border-color: #e2e8f0;
    box-shadow: 0 1px 3px rgba(15,23,42,0.07);
}

.main-content .card-header {
    padding: 1rem 1.3rem;
    border-bottom: 1px solid var(--border-color, rgba(255,255,255,0.06));
}
[data-theme="light"] .main-content .card-header { border-bottom-color: #e2e8f0; }

.main-content .card-header h3 {
    margin: 0 0 2px;
    font-size: 0.97rem;
    font-weight: 700;
    color: var(--cream, #f1f5f9);
}
[data-theme="light"] .main-content .card-header h3 { color: #0f172a; }

.card-header-flex {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.main-content .card-body { padding: 1.1rem 1.3rem; }
.no-top-pad { padding-top: 0 !important; }

.text-muted-sm { font-size: 0.78rem; color: var(--gray-text, #94a3b8); }

/* ── Chart ── */
.card-chart { padding: 0 !important; }
.chart-wrap {
    padding: 1rem 1.3rem 1.1rem;
    height: 185px;
    position: relative;
}
#appointmentsChart { width: 100% !important; height: 100% !important; }

/* ── Quick actions ── */
.quick-actions-body { display: flex; flex-direction: column; gap: 0.65rem; }

.quick-action-btn {
    display: flex;
    align-items: center;
    gap: 0.9rem;
    padding: 0.85rem 1rem;
    border-radius: 12px;
    text-decoration: none;
    border: 1px solid var(--border-color, rgba(255,255,255,0.08));
    background: rgba(255,255,255,0.03);
    transition: all .2s ease;
}
.quick-action-btn:hover {
    border-color: var(--primary-red, #E63946);
    background: rgba(230,57,70,0.07);
    transform: translateX(2px);
}
.qa-primary {
    border-color: var(--primary-red, #E63946);
    background: rgba(230,57,70,0.08);
}
.qa-primary:hover { box-shadow: 0 4px 14px rgba(230,57,70,0.2); }

[data-theme="light"] .quick-action-btn          { border-color: #e2e8f0; background: #f8fafc; }
[data-theme="light"] .quick-action-btn:hover    { border-color: #E63946; background: rgba(230,57,70,0.05); }
[data-theme="light"] .qa-primary                { border-color: #E63946; background: rgba(230,57,70,0.07); }

.qa-icon {
    width: 40px; height: 40px; flex-shrink: 0;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
}
.qa-primary  .qa-icon { background: var(--primary-red, #E63946); color: #fff; }
.qa-secondary .qa-icon { background: rgba(255,255,255,0.08); color: var(--cream, #e2e8f0); }
[data-theme="light"] .qa-secondary .qa-icon { background: #e2e8f0; color: #334155; }

.qa-text { flex: 1; }
.qa-text strong { display: block; font-size: .88rem; font-weight: 700; color: var(--cream, #f1f5f9); }
.qa-text span   { font-size: .76rem; color: var(--gray-text, #94a3b8); }
[data-theme="light"] .qa-text strong { color: #0f172a; }

.qa-arrow { color: var(--gray-text, #94a3b8); flex-shrink: 0; }
.quick-action-btn:hover .qa-arrow { color: var(--primary-red, #E63946); }

/* ── Button extras ── */
.btn-header { padding: 10px 18px; font-family: 'Plus Jakarta Sans', sans-serif; }
.btn-sm     { padding: 7px 12px; font-size: .82rem; }
.btn-text-link {
    font-size: .82rem; font-weight: 600;
    color: var(--primary-red, #E63946);
    text-decoration: none; white-space: nowrap;
    transition: opacity .2s;
}
.btn-text-link:hover { opacity: .7; }

/* ── History table ── */
.table-responsive { overflow-x: auto; }
.history-table { width: 100%; border-collapse: collapse; font-size: .855rem; }

.history-table th {
    text-align: left;
    padding: .85rem 1rem;
    font-size: .74rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .5px;
    white-space: nowrap;
    color: var(--gray-text, #94a3b8);
    background: rgba(255,255,255,0.03);
    border-bottom: 1px solid var(--border-color, rgba(255,255,255,0.06));
}
.history-table th:first-child { padding-left: 1.3rem; }
.history-table th:last-child  { padding-right: 1.3rem; }

[data-theme="light"] .history-table th {
    background: #f8fafc;
    border-bottom-color: #e2e8f0;
    color: #94a3b8;
}

.history-table td {
    padding: .9rem 1rem;
    border-bottom: 1px solid var(--border-color, rgba(255,255,255,0.04));
    color: var(--cream, #e2e8f0);
    vertical-align: middle;
}
.history-table td:first-child { padding-left: 1.3rem; }
.history-table td:last-child  { padding-right: 1.3rem; }
.history-table tbody tr:last-child td { border-bottom: none; }
.history-table tbody tr:hover { background: rgba(255,255,255,0.025); }

[data-theme="light"] .history-table td         { border-bottom-color: #f1f5f9; color: #334155; }
[data-theme="light"] .history-table tbody tr:hover { background: #fafbff; }

.date-main { display: block; font-weight: 600; color: var(--cream, #f1f5f9); font-size: .855rem; }
.date-time  { display: block; color: var(--gray-text, #94a3b8); font-size: .76rem; margin-top: 1px; }
[data-theme="light"] .date-main { color: #0f172a; }
.amount-cell { font-weight: 700; }

/* ── Status badges ── */
.status-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px;
    font-size: .72rem; font-weight: 700;
    text-transform: capitalize; letter-spacing: .2px; white-space: nowrap;
}
.status-badge::before {
    content: ''; width: 6px; height: 6px;
    border-radius: 50%; background: currentColor; flex-shrink: 0;
}
/* dark */
.status-pending     { background: rgba(245,158,11,0.15);  color: #f59e0b; }
.status-confirmed   { background: rgba(16,185,129,0.15);  color: #10b981; }
.status-in-progress { background: rgba(59,130,246,0.15);  color: #60a5fa; }
.status-completed   { background: rgba(100,116,139,0.15); color: #94a3b8; }
.status-cancelled   { background: rgba(239,68,68,0.15);   color: #f87171; }
/* light */
[data-theme="light"] .status-pending     { background: rgba(245,158,11,0.1);  color: #d97706; }
[data-theme="light"] .status-confirmed   { background: rgba(16,185,129,0.1);  color: #059669; }
[data-theme="light"] .status-in-progress { background: rgba(59,130,246,0.1);  color: #2563eb; }
[data-theme="light"] .status-completed   { background: rgba(100,116,139,0.1); color: #64748b; }
[data-theme="light"] .status-cancelled   { background: rgba(239,68,68,0.1);   color: #dc2626; }

/* ── Empty state ── */
.empty-state-dash {
    text-align: center; padding: 3rem 1rem;
    color: var(--gray-text, #94a3b8);
}
.empty-icon-dash {
    width: 72px; height: 72px;
    background: rgba(255,255,255,0.04);
    border-radius: 20px;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 1.1rem;
    color: rgba(255,255,255,0.18);
    border: 1px solid var(--border-color, rgba(255,255,255,0.08));
}
[data-theme="light"] .empty-icon-dash { background: #f1f5f9; border-color: #e2e8f0; color: #cbd5e1; }
.empty-state-dash p { margin: 0 0 1.25rem; font-size: .9rem; }

/* ── Responsive ── */
@media (max-width: 1280px) { .stats-cards { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 1100px) { .dashboard-grid { grid-template-columns: 1fr; } }
@media (max-width: 768px) {
    .main-content { margin-left: 0 !important; padding: calc(var(--topbar-height,64px) + 12px) 16px 20px; }
    .stats-cards  { grid-template-columns: 1fr 1fr; gap: .7rem; }
    .dashboard-header { flex-direction: column; }
    .btn-header { width: 100%; justify-content: center; }

    .table-responsive { overflow: visible; }
    .history-table thead { display: none; }

    .history-table,
    .history-table tbody,
    .history-table tr,
    .history-table td {
        display: block;
        width: 100%;
    }

    .history-table tr {
        background: var(--dark-bg);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 0.85rem;
        margin-bottom: 0.85rem;
    }

    [data-theme="light"] .history-table tr {
        background: #f8fafc;
        border-color: #e2e8f0;
    }

    .history-table td {
        border-bottom: none;
        padding: 0.35rem 0;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
    }

    .history-table td::before {
        color: var(--gray-text);
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 700;
        min-width: 84px;
        flex-shrink: 0;
    }

    .history-table td:nth-child(1)::before { content: 'Date'; }
    .history-table td:nth-child(2)::before { content: 'Service'; }
    .history-table td:nth-child(3)::before { content: 'Helmet'; }
    .history-table td:nth-child(4)::before { content: 'Qty'; }
    .history-table td:nth-child(5)::before { content: 'Amount'; }
    .history-table td:nth-child(6)::before { content: 'Status'; }
}
@media (max-width: 480px) {
    .stats-cards { grid-template-columns: 1fr; }

    .history-table td {
        flex-direction: column;
        gap: 0.2rem;
    }

    .history-table td::before {
        min-width: 0;
    }
}
</style>
</body>
</html>