<?php
require_once __DIR__ . '/../../config/config.php';

// Check authentication
if (!isLoggedIn() || getUserRole() !== 'admin') {
    redirect('login.php');
}

$db = Database::getInstance()->getConnection();

// Initialize variables
$todayBookings = 0;
$weeklyBookings = 0;
$monthlyBookings = 0;
$totalRevenue = 0;
$totalCompletedCount = 0;
$topServices = [];
$recentCompleted = [];
$monthlyRevenue = [];

// Get filter period from request
$filterPeriod = $_GET['period'] ?? 'today';
$dateFrom = $_GET['from'] ?? null;
$dateTo = $_GET['to'] ?? null;

// Check if appointments table exists and get real data
try {
    $tableCheck = $db->query("SHOW TABLES LIKE 'appointments'")->fetch();
    if ($tableCheck) {
        
        // ========================================
        // STATS CARDS DATA (Only completed appointments count for revenue)
        // ========================================
        
        // Today's completed bookings
        $todayBookings = $db->query("
            SELECT COUNT(*) FROM appointments 
            WHERE DATE(updated_at) = CURDATE() AND status = 'completed'
        ")->fetchColumn();
        
        // This week's completed bookings
        $weeklyBookings = $db->query("
            SELECT COUNT(*) FROM appointments 
            WHERE updated_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND status = 'completed'
        ")->fetchColumn();
        
        // This month's completed bookings
        $monthlyBookings = $db->query("
            SELECT COUNT(*) FROM appointments 
            WHERE MONTH(updated_at) = MONTH(CURDATE()) AND YEAR(updated_at) = YEAR(CURDATE()) AND status = 'completed'
        ")->fetchColumn();
        
        // Total completed bookings (all time)
        $totalCompletedCount = $db->query("
            SELECT COUNT(*) FROM appointments WHERE status = 'completed'
        ")->fetchColumn();
        
        // Total revenue from completed appointments
        $totalRevenue = $db->query("
            SELECT COALESCE(SUM(s.price * a.quantity), 0) 
            FROM appointments a
            JOIN services s ON a.service_id = s.id
            WHERE a.status = 'completed'
        ")->fetchColumn();
        
        // ========================================
        // FILTERED REVENUE (based on period)
        // ========================================
        $filteredRevenue = 0;
        $filteredCount = 0;
        
        if ($dateFrom && $dateTo) {
            // Custom date range
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(s.price * a.quantity), 0) as revenue, COUNT(*) as count
                FROM appointments a
                JOIN services s ON a.service_id = s.id
                WHERE a.status = 'completed' 
                AND DATE(a.updated_at) BETWEEN ? AND ?
            ");
            $stmt->execute([$dateFrom, $dateTo]);
            $result = $stmt->fetch();
            $filteredRevenue = $result['revenue'];
            $filteredCount = $result['count'];
        } else {
            switch ($filterPeriod) {
                case 'today':
                    $stmt = $db->query("
                        SELECT COALESCE(SUM(s.price * a.quantity), 0) as revenue, COUNT(*) as count
                        FROM appointments a
                        JOIN services s ON a.service_id = s.id
                        WHERE a.status = 'completed' AND DATE(a.updated_at) = CURDATE()
                    ");
                    break;
                case 'week':
                    $stmt = $db->query("
                        SELECT COALESCE(SUM(s.price * a.quantity), 0) as revenue, COUNT(*) as count
                        FROM appointments a
                        JOIN services s ON a.service_id = s.id
                        WHERE a.status = 'completed' AND a.updated_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    ");
                    break;
                case 'month':
                    $stmt = $db->query("
                        SELECT COALESCE(SUM(s.price * a.quantity), 0) as revenue, COUNT(*) as count
                        FROM appointments a
                        JOIN services s ON a.service_id = s.id
                        WHERE a.status = 'completed' 
                        AND MONTH(a.updated_at) = MONTH(CURDATE()) 
                        AND YEAR(a.updated_at) = YEAR(CURDATE())
                    ");
                    break;
                case 'year':
                    $stmt = $db->query("
                        SELECT COALESCE(SUM(s.price * a.quantity), 0) as revenue, COUNT(*) as count
                        FROM appointments a
                        JOIN services s ON a.service_id = s.id
                        WHERE a.status = 'completed' AND YEAR(a.updated_at) = YEAR(CURDATE())
                    ");
                    break;
                default:
                    $stmt = $db->query("
                        SELECT COALESCE(SUM(s.price * a.quantity), 0) as revenue, COUNT(*) as count
                        FROM appointments a
                        JOIN services s ON a.service_id = s.id
                        WHERE a.status = 'completed' AND DATE(a.updated_at) = CURDATE()
                    ");
            }
            $result = $stmt->fetch();
            $filteredRevenue = $result['revenue'];
            $filteredCount = $result['count'];
        }
        
        // ========================================
        // TOP SELLING SERVICES (Only from completed)
        // ========================================
        $topServices = $db->query("
            SELECT s.label, s.name, COUNT(a.id) as count, SUM(s.price * a.quantity) as revenue
            FROM appointments a
            JOIN services s ON a.service_id = s.id
            WHERE a.status = 'completed'
            GROUP BY s.id, s.label, s.name
            ORDER BY count DESC
            LIMIT 5
        ")->fetchAll();
        
        // ========================================
        // RECENT COMPLETED APPOINTMENTS (Records)
        // ========================================
        $recentCompleted = $db->query("
            SELECT a.*, s.label as service_label, s.name as service_name, s.price as service_price,
                   u.name as customer_name
            FROM appointments a
            JOIN services s ON a.service_id = s.id
            JOIN users u ON a.user_id = u.id
            WHERE a.status = 'completed'
            ORDER BY a.updated_at DESC
            LIMIT 10
        ")->fetchAll();
        
        // ========================================
        // MONTHLY REVENUE DATA FOR CHART (Last 6 months, only completed)
        // ========================================
        $monthlyRevenue = $db->query("
            SELECT 
                DATE_FORMAT(a.updated_at, '%Y-%m') as month,
                DATE_FORMAT(a.updated_at, '%b') as month_name,
                COALESCE(SUM(s.price * a.quantity), 0) as revenue,
                COUNT(*) as count
            FROM appointments a
            JOIN services s ON a.service_id = s.id
            WHERE a.status = 'completed' 
            AND a.updated_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(a.updated_at, '%Y-%m'), DATE_FORMAT(a.updated_at, '%b')
            ORDER BY month ASC
        ")->fetchAll();
    }
} catch (PDOException $e) {
    // Table doesn't exist yet, use defaults
}

// Prepare chart data
$chartLabels = [];
$chartRevenue = [];
if (!empty($monthlyRevenue)) {
    foreach ($monthlyRevenue as $month) {
        $chartLabels[] = $month['month_name'];
        $chartRevenue[] = (float)$month['revenue'];
    }
} else {
    // Default empty chart
    $chartLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
    $chartRevenue = [0, 0, 0, 0, 0, 0];
}

$maxServiceCount = !empty($topServices) ? max(array_column($topServices, 'count')) : 1;

// Period labels for display
$periodLabels = [
    'today' => 'Today',
    'week' => 'This Week',
    'month' => 'This Month',
    'year' => 'This Year'
];
$currentPeriodLabel = $periodLabels[$filterPeriod] ?? 'Today';
if ($dateFrom && $dateTo) {
    $currentPeriodLabel = date('M d', strtotime($dateFrom)) . ' - ' . date('M d, Y', strtotime($dateTo));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="dashboard-layout">
        <!-- Sidebar -->
        <?php include '../includes/sidebar.php'; ?>
        
        <!-- Header -->
        <?php include '../includes/header.php'; ?>
        
        <!-- Mobile Overlay -->
        <div class="sidebar-overlay" onclick="toggleSidebar()"></div>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="dashboard-header">
                <h1>Dashboard</h1>
                <p>Welcome back, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</p>
            </div>
            
            <!-- Filter Controls -->
            <div class="filter-controls">
                <div class="filter-buttons-wrap">
                    <div class="filter-buttons">
                        <button class="filter-btn active" data-period="today">Today</button>
                        <button class="filter-btn" data-period="week">Week</button>
                        <button class="filter-btn" data-period="month">Month</button>
                        <button class="filter-btn" data-period="year">Year</button>
                    </div>
                    <div class="swipe-hint">Swipe for more →</div>
                </div>
                <div class="date-picker-group">
                    <label>From:</label>
                    <input type="date" id="dateFrom" class="date-input">
                    <label>To:</label>
                    <input type="date" id="dateTo" class="date-input">
                    <button class="filter-btn apply-btn" onclick="applyDateFilter()">Apply</button>
                    <button class="filter-btn clear-btn" onclick="clearDateFilter()">Clear</button>
                </div>
            </div>

            <!-- Main Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Left Section -->
                <div class="dashboard-left">
                    <!-- Stats Cards Row -->
                    <div class="stats-row">
                        <div class="stat-card-new">
                            <div class="stat-icon-circle red">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                    <line x1="16" y1="2" x2="16" y2="6"></line>
                                    <line x1="8" y1="2" x2="8" y2="6"></line>
                                    <line x1="3" y1="10" x2="21" y2="10"></line>
                                </svg>
                            </div>
                            <h4>Completed Today</h4>
                            <h2><?php echo number_format($todayBookings); ?></h2>
                            <span class="stat-period">Finished appointments today</span>
                        </div>
                        
                        <div class="stat-card-new">
                            <div class="stat-icon-circle blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                                </svg>
                            </div>
                            <h4>Completed This Week</h4>
                            <h2><?php echo number_format($weeklyBookings); ?></h2>
                            <span class="stat-period">Last 7 days</span>
                        </div>
                        
                        <div class="stat-card-new">
                            <div class="stat-icon-circle green">
                                <span class="peso-icon">₱</span>
                            </div>
                            <h4>Total Revenue</h4>
                            <h2>₱<?php echo number_format($totalRevenue, 2); ?></h2>
                            <span class="stat-period">All completed bookings</span>
                        </div>
                        
                        <div class="stat-card-new">
                            <div class="stat-icon-circle orange">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                </svg>
                            </div>
                            <h4>Total Completed</h4>
                            <h2><?php echo number_format($totalCompletedCount); ?></h2>
                            <span class="stat-period">All time records</span>
                        </div>
                    </div>
                    
                    <!-- Charts Row -->
                    <div class="charts-row">
                        <!-- Overall Sales Trend -->
                        <div class="chart-card-white">
                            <h3>Overall Sales Trend</h3>
                            <div class="chart-legend">
                                <span class="legend-item"><span class="legend-box filled"></span> Revenue</span>
                                <span class="legend-item"><span class="legend-box outline"></span> Profit</span>
                            </div>
                            <div class="line-chart-container">
                                <canvas id="salesTrendChart"></canvas>
                            </div>
                        </div>

                        <!-- Top Selling Services (aligned with 4th stat card) -->
                        <div class="top-services-card">
                            <h3>Top-Selling Services</h3>
                            <div class="services-bars">
                                <?php if (empty($topServices)): ?>
                                <div style="text-align:center;color:var(--gray-text);padding:2rem 0;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:0.5;margin-bottom:0.5rem;">
                                        <path d="M3 3v18h18"></path>
                                        <path d="M18 17l-5-5-2 2-4-4-3 3"></path>
                                    </svg>
                                    <p style="font-size:0.85rem;">No completed services yet</p>
                                </div>
                                <?php else: ?>
                                <?php foreach ($topServices as $service): ?>
                                <div class="service-bar-item">
                                    <div style="display:flex;justify-content:space-between;align-items:center;">
                                        <span class="service-name"><?php echo htmlspecialchars($service['label'] . ' ' . $service['name']); ?></span>
                                        <span style="font-size:0.75rem;color:var(--gray-text);"><?php echo $service['count']; ?> sales</span>
                                    </div>
                                    <div class="service-bar">
                                        <div class="service-bar-fill" style="width: <?php echo ($service['count'] / $maxServiceCount) * 100; ?>%"></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($topServices)): ?>
                            <div class="bar-scale">
                                <span>0</span>
                                <span><?php echo ceil($maxServiceCount / 4); ?></span>
                                <span><?php echo ceil($maxServiceCount / 2); ?></span>
                                <span><?php echo ceil($maxServiceCount * 3 / 4); ?></span>
                                <span><?php echo $maxServiceCount; ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Completed Appointments (Records) -->
                    <div class="recent-appointments">
                        <h3>
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:8px;vertical-align:middle;color:var(--primary-red);">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                <polyline points="22 4 12 14.01 9 11.01"></polyline>
                            </svg>
                            Completed Appointment Records
                        </h3>
                        <div class="appointments-table-wrapper">
                            <table class="appointments-table">
                                <thead>
                                    <tr>
                                        <th>Reference</th>
                                        <th>Customer</th>
                                        <th>Service</th>
                                        <th>Date Completed</th>
                                        <th>Qty</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentCompleted)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align:center;color:var(--gray-text);padding:2rem;">
                                            No completed appointments yet. Records will appear here once bookings are marked as completed.
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($recentCompleted as $apt): ?>
                                    <tr>
                                        <td><span style="font-family:monospace;color:var(--primary-red);">APT-<?php echo str_pad($apt['id'], 6, '0', STR_PAD_LEFT); ?></span></td>
                                        <td><?php echo htmlspecialchars($apt['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($apt['service_label'] . ' - ' . $apt['service_name']); ?></td>
                                        <td><?php echo date('M d, Y h:i A', strtotime($apt['updated_at'])); ?></td>
                                        <td><?php echo $apt['quantity']; ?></td>
                                        <td style="font-weight:600;color:#4caf50;">₱<?php echo number_format($apt['service_price'] * $apt['quantity'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (!empty($recentCompleted)): ?>
                        <div style="text-align:center;margin-top:1rem;">
                            <small style="color:var(--gray-text);">Showing last 10 completed appointments</small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        // Filter button functionality - reload page with period parameter
        document.querySelectorAll('.filter-btn[data-period]').forEach(btn => {
            btn.addEventListener('click', function() {
                const period = this.dataset.period;
                window.location.href = 'dashboard.php?period=' + period;
            });
        });
        
        // Set active filter button based on current period
        const currentPeriod = '<?php echo $filterPeriod; ?>';
        document.querySelectorAll('.filter-btn[data-period]').forEach(b => b.classList.remove('active'));
        const activeBtn = document.querySelector(`.filter-btn[data-period="${currentPeriod}"]`);
        if (activeBtn) activeBtn.classList.add('active');

        function applyDateFilter() {
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;
            if (dateFrom && dateTo) {
                window.location.href = 'dashboard.php?from=' + dateFrom + '&to=' + dateTo;
            } else {
                alert('Please select both From and To dates');
            }
        }

        function clearDateFilter() {
            document.getElementById('dateFrom').value = '';
            document.getElementById('dateTo').value = '';
            window.location.href = 'dashboard.php';
        }

        // Sales Trend Chart (Line with Area) - Real Data from Completed Appointments
        const salesCtx = document.getElementById('salesTrendChart').getContext('2d');
        const chartLabels = <?php echo json_encode($chartLabels); ?>;
        const chartRevenue = <?php echo json_encode($chartRevenue); ?>;
        
        // Calculate max for Y axis
        const maxRevenue = Math.max(...chartRevenue, 1000);
        const yAxisMax = Math.ceil(maxRevenue / 1000) * 1000 + 1000;
        
        new Chart(salesCtx, {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: 'Revenue',
                    data: chartRevenue,
                    borderColor: '#E63946',
                    backgroundColor: 'rgba(230, 57, 70, 0.2)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#E63946',
                    pointRadius: 5,
                    pointHoverRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return '₱' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: yAxisMax,
                        grid: {
                            color: 'rgba(255, 255, 255, 0.05)'
                        },
                        ticks: {
                            color: '#A8A8A8',
                            callback: function(value) {
                                return '₱' + value.toLocaleString();
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: '#A8A8A8'
                        }
                    }
                }
            }
        });
    </script>
    
    <style>
        .dashboard-grid {
            display: block;
        }
        
        .dashboard-left {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            min-width: 0;
            width: 100%;
        }
        
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
        }
        
        @media (max-width: 992px) {
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 576px) {
            .stats-row {
                grid-template-columns: 1fr;
            }
        }
        
        .stat-card-new {
            background: var(--dark-card);
            border-radius: 12px;
            padding: 1.25rem;
            border: 1px solid var(--border-color);
            transition: transform 0.3s, box-shadow 0.3s;
            min-width: 0;
        }
        
        .stat-card-new:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(230, 57, 70, 0.2);
        }
        
        .stat-icon-circle {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            flex-shrink: 0;
        }
        
        .stat-icon-circle.red {
            background: rgba(230, 57, 70, 0.15);
            color: #E63946;
        }
        
        .stat-icon-circle.blue {
            background: rgba(33, 150, 243, 0.15);
            color: #2196F3;
        }
        
        .stat-icon-circle.green {
            background: rgba(76, 175, 80, 0.15);
            color: #4CAF50;
        }
        
        .stat-icon-circle.orange {
            background: rgba(255, 152, 0, 0.15);
            color: #FF9800;
        }
        
        .stat-card-new h4 {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--gray-text);
            margin: 0 0 0.5rem 0;
        }
        
        .stat-card-new h2 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--cream);
            margin: 0 0 0.25rem 0;
            word-break: break-word;
        }
        
        .stat-period {
            font-size: 0.75rem;
            color: var(--gray-text);
        }
        
        .charts-row {
            display: grid;
            /* chart spans 3 columns (matching first 3 stat cards), services spans 1 (matching 4th stat card) */
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            align-items: start;
        }

        /* Peso icon styling */
        .peso-icon {
            font-size: 20px;
            font-weight: 800;
            line-height: 1;
            color: currentColor;
            display: inline-block;
            transform: translateY(-1px);
        }

        .chart-card-white {
            grid-column: span 3;
        }

        .top-services-card {
            grid-column: span 1;
        }

        @media (max-width: 992px) {
            .charts-row {
                grid-template-columns: 1fr;
            }
        }
        
        .chart-card-white {
            background: var(--dark-card);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
        }
        
        .chart-card-white h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--cream);
            margin: 0 0 1rem 0;
        }
        
        .donut-chart-container {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        .donut-chart-container canvas {
            width: 180px !important;
            height: 180px !important;
        }
        
        .expense-legend {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: var(--gray-text);
        }
        
        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 2px;
            flex-shrink: 0;
        }
        
        .chart-legend {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .chart-legend .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: var(--gray-text);
        }
        
        .legend-box {
            width: 24px;
            height: 12px;
            border-radius: 2px;
        }
        
        .legend-box.filled {
            background: #E63946;
        }
        
        .legend-box.outline {
            background: rgba(230, 57, 70, 0.4);
        }
        
        .line-chart-container {
            height: 170px;
            min-height: 140px;
        }
        
        .top-services-card {
            background: var(--dark-card);
            border-radius: 12px;
            padding: 1.25rem;
            border: 1px solid var(--border-color);
            height: fit-content;
            position: relative;
            z-index: 0;
            box-sizing: border-box;
        }
        
        .top-services-card h3 {
            font-size: 1rem;
            font-weight: 600;
            color: #E63946;
            margin: 0 0 1rem 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .services-bars {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .service-bar-item {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        
        .service-name {
            font-size: 0.85rem;
            color: var(--cream);
        }
        
        .service-bar {
            height: 20px;
            background: var(--dark-hover);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .service-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #E63946, #FF6B6B);
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        
        .bar-scale {
            display: flex;
            justify-content: space-between;
            margin-top: 0.75rem;
            font-size: 0.75rem;
            color: var(--gray-text);
        }

        /* Filter Controls */
        .filter-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem 1.25rem;
            background: var(--dark-card);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            width: 100%;
            min-width: 0;
        }

        .filter-buttons-wrap {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .filter-buttons {
            display: flex;
            gap: 0.5rem;
            min-width: 0;
        }

        .filter-btn {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-color);
            background: transparent;
            color: var(--gray-text);
            border-radius: 8px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-btn:hover {
            border-color: #E63946;
            color: #E63946;
        }

        .filter-btn.active {
            background: #E63946;
            border-color: #E63946;
            color: white;
        }

        .date-picker-group {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 0;
        }

        .date-picker-group label {
            font-size: 0.85rem;
            color: var(--gray-text);
        }

        .date-input {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border-color);
            background: var(--dark-bg);
            color: var(--cream);
            border-radius: 8px;
            font-size: 0.85rem;
            min-width: 0;
        }

        .date-input:focus {
            outline: none;
            border-color: #E63946;
        }

        .apply-btn {
            background: #E63946 !important;
            border-color: #E63946 !important;
            color: white !important;
        }

        .apply-btn:hover {
            background: #c5303b !important;
        }

        .clear-btn {
            background: var(--dark-hover) !important;
            border-color: var(--border-color) !important;
            color: var(--gray-text) !important;
        }

        .clear-btn:hover {
            background: #2a2a2a !important;
            color: var(--cream) !important;
        }

        @media (max-width: 768px) {
            .filter-controls {
                flex-direction: column;
                align-items: stretch;
                padding: 0.9rem;
            }

            .filter-buttons-wrap {
                width: 100%;
            }

            .filter-buttons {
                flex-wrap: nowrap;
                justify-content: flex-start;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .filter-buttons .filter-btn {
                flex: 0 0 auto;
                text-align: center;
                white-space: nowrap;
            }

            .date-picker-group {
                width: 100%;
                display: grid;
                grid-template-columns: auto 1fr auto 1fr;
                gap: 0.5rem;
                align-items: center;
            }

            .date-picker-group .date-input {
                width: 100%;
            }

            .date-picker-group .filter-btn {
                grid-column: span 2;
            }

            .charts-row,
            .chart-card-white,
            .top-services-card,
            .recent-appointments,
            .appointments-table-wrapper {
                width: 100%;
                min-width: 0;
            }

            .appointments-table-wrapper {
                overflow: visible;
            }

            .appointments-table thead {
                display: none;
            }

            .appointments-table,
            .appointments-table tbody,
            .appointments-table tr,
            .appointments-table td {
                display: block;
                width: 100%;
            }

            .appointments-table tr {
                background: var(--dark-bg);
                border: 1px solid var(--border-color);
                border-radius: 12px;
                padding: 0.85rem;
                margin-bottom: 0.85rem;
            }

            .appointments-table td {
                border-bottom: none;
                padding: 0.35rem 0;
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 0.75rem;
                text-align: left;
            }

            .appointments-table td::before {
                color: var(--gray-text);
                font-size: 0.72rem;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                font-weight: 700;
                min-width: 96px;
                flex-shrink: 0;
            }

            .appointments-table td:nth-child(1) {
                display: block;
                padding-top: 0;
                margin-bottom: 0.25rem;
            }

            .appointments-table td:nth-child(1)::before { content: none; }
            .appointments-table td:nth-child(2)::before { content: 'Customer'; }
            .appointments-table td:nth-child(3)::before { content: 'Service'; }
            .appointments-table td:nth-child(4)::before { content: 'Completed'; }
            .appointments-table td:nth-child(5)::before { content: 'Qty'; }
            .appointments-table td:nth-child(6)::before { content: 'Amount'; }

            .appointments-table td[colspan] {
                display: block;
                padding: 1rem 0;
            }

            .appointments-table td[colspan]::before { content: none; }
        }

        @media (max-width: 480px) {
            .filter-buttons { gap: 0.35rem; }

            .date-picker-group {
                grid-template-columns: 1fr;
                gap: 0.45rem;
            }

            .date-picker-group .filter-btn {
                grid-column: auto;
            }

            .appointments-table td {
                flex-direction: column;
                gap: 0.2rem;
            }

            .appointments-table td::before {
                min-width: 0;
            }
        }

        /* Recent Appointments */
        .recent-appointments {
            margin-top: 1.5rem;
        }

        .recent-appointments h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--cream);
            margin: 0 0 1rem 0;
        }

        .appointments-table-wrapper {
            background: var(--dark-card);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            overflow: hidden;
        }

        .appointments-table {
            width: 100%;
            border-collapse: collapse;
        }

        .appointments-table thead {
            background: rgba(230, 57, 70, 0.1);
        }

        .appointments-table th {
            padding: 1rem;
            text-align: left;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--cream);
            border-bottom: 1px solid var(--border-color);
        }

        .appointments-table td {
            padding: 1rem;
            font-size: 0.85rem;
            color: var(--gray-text);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .appointments-table tbody tr:hover {
            background: rgba(230, 57, 70, 0.05);
        }

        .appointments-table tbody tr:last-child td {
            border-bottom: none;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-badge.completed {
            background: rgba(76, 175, 80, 0.2);
            color: #4CAF50;
        }

        .status-badge.pending {
            background: rgba(255, 193, 7, 0.2);
            color: #FFC107;
        }

        .status-badge.cancelled {
            background: rgba(244, 67, 54, 0.2);
            color: #F44336;
        }

        
    </style>
</body>
</html>
