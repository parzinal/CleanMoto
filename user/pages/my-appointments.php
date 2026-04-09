<?php
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in and has user role
if (!isLoggedIn()) {
    redirect('login.php');
}

if (!isUser()) {
    // Redirect to appropriate dashboard based on role
    if (isAdmin()) {
        redirect('admin/pages/dashboard.php');
    } elseif (isStaff()) {
        redirect('staff/pages/dashboard.php');
    }
}

// Get user info from session
$userName = $_SESSION['user_name'] ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$userId = $_SESSION['user_id'] ?? 0;

// Fetch user's appointments
$userAppointments = [];
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT a.*, s.name as service_name, s.label as service_label, s.price as service_price FROM appointments a LEFT JOIN services s ON a.service_id = s.id WHERE a.user_id = ? ORDER BY a.appointment_date DESC, a.appointment_time DESC");
    $stmt->execute([$userId]);
    $userAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Appointments table might not exist yet
}
?>
<!DOCTYPE html>
<html lang="en" class="allow-page-x-scroll">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Appointments - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Page Container */
        .appointments-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 1rem;
        }
        
        /* Page Header */
        .page-header {
            margin-bottom: 2rem;
        }
        
        .page-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--cream);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .page-title svg {
            color: var(--primary-red);
        }
        
        .page-subtitle {
            color: var(--gray-text);
            font-size: 0.95rem;
        }
        
        /* Appointment List */
        .appointment-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .appointment-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem;
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            transition: all 0.3s;
        }
        
        .appointment-item:hover {
            border-color: var(--primary-red);
            box-shadow: 0 4px 20px rgba(230, 57, 70, 0.1);
        }
        
        .appointment-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            min-width: 0;
        }

        .appointment-details {
            min-width: 0;
        }
        
        .appointment-date-box {
            width: 55px;
            height: 55px;
            background: var(--gradient-red);
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
        }
        
        .appointment-date-box .day {
            font-size: 1.35rem;
            font-weight: 700;
            line-height: 1;
        }
        
        .appointment-date-box .month {
            font-size: 0.7rem;
            text-transform: uppercase;
            font-weight: 600;
        }
        
        .appointment-details h5 {
            font-size: 1rem;
            color: var(--cream);
            margin: 0 0 6px 0;
            font-weight: 600;
            overflow-wrap: anywhere;
        }
        
        .appointment-details p {
            font-size: 0.85rem;
            color: var(--gray-text);
            margin: 0;
            overflow-wrap: anywhere;
        }
        
        .appointment-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-shrink: 0;
        }
        
        .view-qr-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 10px 16px;
            background: rgba(230, 57, 70, 0.15);
            border: 1px solid rgba(230, 57, 70, 0.3);
            color: var(--primary-red);
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .view-qr-btn:hover {
            background: var(--primary-red);
            color: white;
            border-color: var(--primary-red);
        }
        
        .appointment-status {
            padding: 8px 14px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-pending {
            background: rgba(255, 209, 102, 0.15);
            color: var(--warning);
        }
        
        .status-confirmed {
            background: rgba(6, 214, 160, 0.15);
            color: var(--success);
        }
        
        .status-completed {
            background: rgba(168, 168, 168, 0.15);
            color: var(--gray-text);
        }
        
        .status-cancelled {
            background: rgba(239, 71, 111, 0.15);
            color: var(--error);
        }
        
        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            width: 100%;
            max-width: 100%;
        }

        .filter-tab {
            padding: 10px 20px;
            border-radius: 25px;
            border: 1px solid var(--border-color);
            background: var(--dark-card);
            color: var(--gray-text);
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .filter-tab:hover {
            border-color: var(--primary-red);
            color: var(--cream);
        }
        
        .filter-tab.active {
            background: var(--gradient-red);
            border-color: var(--primary-red);
            color: white;
        }
        
        .filter-tab .count {
            background: rgba(255, 255, 255, 0.2);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
        }
        
        .filter-tab.active .count {
            background: rgba(255, 255, 255, 0.3);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--dark-card);
            border: 1px dashed var(--border-color);
            border-radius: 16px;
        }
        
        .empty-state svg {
            color: var(--gray-text);
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            color: var(--cream);
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }
        
        .empty-state p {
            color: var(--gray-text);
            margin-bottom: 1.5rem;
        }
        
        .btn-book {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 12px 24px;
            background: var(--gradient-red);
            border: none;
            color: white;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .btn-book:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(230, 57, 70, 0.4);
        }
        
        /* QR Code Modal Styles */
        .qr-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            padding: 1rem;
        }
        
        .qr-modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .qr-modal {
            background: linear-gradient(145deg, var(--dark-card), rgba(30, 30, 35, 0.98));
            border: 2px solid var(--primary-red);
            border-radius: 20px;
            width: 100%;
            max-width: 380px;
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.9) translateY(30px);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.6), 0 0 60px rgba(230, 57, 70, 0.15);
        }
        
        .qr-modal-overlay.active .qr-modal {
            transform: scale(1) translateY(0);
        }
        
        .qr-modal-header {
            background: var(--gradient-red);
            padding: 1rem 1.25rem;
            text-align: center;
        }
        
        .qr-modal-header h2 {
            color: white;
            font-size: 1.2rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .qr-modal-header h2 svg {
            width: 22px;
            height: 22px;
        }
        
        .qr-modal-header p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.8rem;
            margin: 0.25rem 0 0 0;
        }
        
        .qr-modal-body {
            padding: 1.25rem;
            text-align: center;
        }
        
        .qr-code-container {
            background: white;
            padding: 1rem;
            border-radius: 12px;
            display: inline-block;
            margin-bottom: 1rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
        }
        
        #qrcode {
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        #qrcode canvas {
            border-radius: 6px;
            max-width: 160px !important;
            max-height: 160px !important;
        }
        
        .appointment-reference {
            background: linear-gradient(145deg, rgba(230, 57, 70, 0.15), rgba(230, 57, 70, 0.08));
            border: 1px solid rgba(230, 57, 70, 0.3);
            border-radius: 10px;
            padding: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .appointment-reference h3 {
            font-size: 0.75rem;
            color: var(--gray-text);
            margin: 0 0 0.15rem 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .appointment-reference .ref-number {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--primary-red);
            font-family: 'Courier New', monospace;
        }
        
        .qr-details {
            text-align: left;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 0.75rem;
            margin-bottom: 0;
        }
        
        .qr-detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.35rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .qr-detail-row:last-child {
            border-bottom: none;
        }
        
        .qr-detail-row .label {
            color: var(--gray-text);
            font-size: 0.8rem;
        }
        
        .qr-detail-row .value {
            color: var(--cream);
            font-weight: 600;
            font-size: 0.8rem;
        }
        
        .qr-modal-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 0.75rem;
        }
        
        .qr-btn {
            flex: 1;
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
        }
        
        .qr-btn-primary {
            background: var(--gradient-red);
            border: none;
            color: white;
        }
        
        .qr-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(230, 57, 70, 0.4);
        }
        
        .qr-btn-secondary {
            background: var(--dark-hover);
            border: 1px solid var(--border-color);
            color: var(--cream);
        }
        
        .qr-btn-secondary:hover {
            border-color: var(--primary-red);
            background: rgba(230, 57, 70, 0.1);
        }

        @media (hover: none) and (pointer: coarse) {
            .filter-tabs {
                flex-wrap: nowrap;
                overflow-x: auto;
                overflow-y: hidden;
                -webkit-overflow-scrolling: touch;
                overscroll-behavior-x: contain;
                scroll-padding-inline: 0.35rem;
                scrollbar-width: none;
                padding-bottom: 0.25rem;
            }

            .filter-tabs::-webkit-scrollbar {
                display: none;
            }

            .filter-tab {
                flex: 0 0 auto;
                white-space: nowrap;
            }
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .filter-tabs {
                flex-wrap: nowrap;
                overflow-x: auto;
                overflow-y: hidden;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                margin-bottom: 0.35rem;
                padding-bottom: 0.25rem;
                overscroll-behavior-x: contain;
                scroll-padding-inline: 0.35rem;
            }

            .filter-tabs::-webkit-scrollbar {
                display: none;
            }

            .filter-tab {
                flex: 0 0 auto;
                white-space: nowrap;
            }

            .appointment-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
                overflow: hidden;
            }

            .appointment-info {
                width: 100%;
            }
            
            .appointment-actions {
                width: 100%;
                justify-content: flex-start;
                flex-wrap: wrap;
                gap: 0.6rem;
            }

            .view-qr-btn {
                flex: 1 1 170px;
                justify-content: center;
            }

            .appointment-status {
                white-space: nowrap;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
        }

        @media (max-width: 480px) {
            .page-title { font-size: 1.25rem; }
            .page-title svg { width: 22px; height: 22px; }

            .appointment-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.85rem;
            }

            .appointment-info { width: 100%; }

            .appointment-actions {
                width: 100%;
                justify-content: space-between;
            }

            .view-qr-btn {
                flex: 1;
                justify-content: center;
                padding: 9px 12px;
                font-size: 0.8rem;
            }

            .qr-modal-footer { flex-direction: column; }
            .qr-btn { width: 100%; }

            .qr-modal {
                border-radius: 16px 16px 0 0;
                max-width: 100%;
            }

            .qr-modal-overlay {
                align-items: flex-end;
                padding: 0;
            }

            .appointment-date-box { width: 48px; height: 48px; }
            .appointment-date-box .day { font-size: 1.15rem; }

            .appointment-details h5 { font-size: 0.9rem; }
            .appointment-details p  { font-size: 0.8rem; }
        }
        
        /* Sidebar toggle for mobile */
        .sidebar-toggle-btn {
            display: none;
            background: var(--dark-hover);
            border: 1px solid var(--border-color);
            color: var(--cream);
            width: 44px;
            height: 44px;
            border-radius: 10px;
            cursor: pointer;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }
        
        .sidebar-toggle-btn:hover {
            background: var(--primary-red);
            border-color: var(--primary-red);
        }
        
        @media (max-width: 768px) {
            .sidebar-toggle-btn {
                display: flex !important;
            }
        }
        
        @media (min-width: 769px) {
            .sidebar-toggle-btn {
                display: none !important;
            }
        }
    </style>
</head>
<body class="allow-page-x-scroll">
    <div class="app-layout">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <!-- Sidebar Overlay for Mobile -->
        <div class="sidebar-overlay" onclick="toggleSidebar()"></div>
        
        <main class="main-content">
            <div class="appointments-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                        My Appointments
                    </h1>
                    <p class="page-subtitle">View and manage all your scheduled appointments</p>
                </div>
                
                <!-- Filter Tabs -->
                <?php
                // Count appointments by status
                $statusCounts = ['all' => count($userAppointments), 'pending' => 0, 'confirmed' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0];
                foreach ($userAppointments as $apt) {
                    $status = $apt['status'] ?? 'pending';
                    if (isset($statusCounts[$status])) {
                        $statusCounts[$status]++;
                    }
                }
                ?>
                <div class="filter-tabs drag-scroll">
                    <button class="filter-tab active" data-filter="all" onclick="filterAppointments('all')">
                        All <span class="count"><?php echo $statusCounts['all']; ?></span>
                    </button>
                    <button class="filter-tab" data-filter="pending" onclick="filterAppointments('pending')">
                        Pending <span class="count"><?php echo $statusCounts['pending']; ?></span>
                    </button>
                    <button class="filter-tab" data-filter="confirmed" onclick="filterAppointments('confirmed')">
                        Confirmed <span class="count"><?php echo $statusCounts['confirmed']; ?></span>
                    </button>
                    <button class="filter-tab" data-filter="in_progress" onclick="filterAppointments('in_progress')">
                        In Progress <span class="count"><?php echo $statusCounts['in_progress']; ?></span>
                    </button>
                    <button class="filter-tab" data-filter="completed" onclick="filterAppointments('completed')">
                        Completed <span class="count"><?php echo $statusCounts['completed']; ?></span>
                    </button>
                    <button class="filter-tab" data-filter="cancelled" onclick="filterAppointments('cancelled')">
                        Cancelled <span class="count"><?php echo $statusCounts['cancelled']; ?></span>
                    </button>
                </div>
                <p class="swipe-hint">Swipe for more</p>
                
                <!-- Appointments List -->
                <?php if (!empty($userAppointments)): ?>
                <div class="appointment-list" id="appointmentList">
                    <?php foreach ($userAppointments as $appointment): 
                        $date = new DateTime($appointment['appointment_date']);
                        // Prepare appointment data for QR code
                        $aptDataForQr = [
                            'id' => $appointment['id'],
                            'reference' => 'APT-' . str_pad($appointment['id'], 6, '0', STR_PAD_LEFT),
                            'customer' => $appointment['full_name'],
                            'contact' => $appointment['contact'],
                            'date' => $appointment['appointment_date'],
                            'time' => $appointment['appointment_time'],
                            'service' => ($appointment['service_label'] ?? '') . ': ' . ($appointment['service_name'] ?? 'Service'),
                            'price' => $appointment['service_price'] ?? 0,
                            'helmet_type' => $appointment['helmet_type'],
                            'quantity' => $appointment['quantity'],
                            'status' => $appointment['status']
                        ];
                    ?>
                    <div class="appointment-item" data-status="<?php echo htmlspecialchars($appointment['status']); ?>">
                        <div class="appointment-info">
                            <div class="appointment-date-box">
                                <span class="day"><?php echo $date->format('d'); ?></span>
                                <span class="month"><?php echo $date->format('M'); ?></span>
                            </div>
                            <div class="appointment-details">
                                <h5><?php echo htmlspecialchars($appointment['service_name'] ?? 'Service'); ?></h5>
                                <p><?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?> • <?php echo $date->format('l, F j, Y'); ?></p>
                            </div>
                        </div>
                        <div class="appointment-actions">
                            <button type="button" class="view-qr-btn" onclick='viewAppointmentQR(<?php echo json_encode($aptDataForQr); ?>)' title="View QR Code">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="7" height="7"></rect>
                                    <rect x="14" y="3" width="7" height="7"></rect>
                                    <rect x="3" y="14" width="7" height="7"></rect>
                                    <rect x="14" y="14" width="7" height="7"></rect>
                                </svg>
                                QR Code
                            </button>
                            <span class="appointment-status status-<?php echo $appointment['status']; ?>">
                                <?php echo ucfirst($appointment['status']); ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                    <h3>No Appointments Yet</h3>
                    <p>You haven't booked any appointments. Start by scheduling your first helmet cleaning service!</p>
                    <a href="appointment.php" class="btn-book">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        Book an Appointment
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- QR Code Modal -->
    <div class="qr-modal-overlay" id="qrModal">
        <div class="qr-modal">
            <div class="qr-modal-header">
                <h2>
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="7" height="7"></rect>
                        <rect x="14" y="3" width="7" height="7"></rect>
                        <rect x="3" y="14" width="7" height="7"></rect>
                        <rect x="14" y="14" width="7" height="7"></rect>
                    </svg>
                    Appointment QR Code
                </h2>
                <p>Show this QR code at the shop for quick check-in</p>
            </div>
            
            <div class="qr-modal-body">
                <div class="appointment-reference">
                    <h3>Reference Number</h3>
                    <span class="ref-number" id="qrRefNumber">APT-000000</span>
                </div>
                
                <div class="qr-code-container">
                    <div id="qrcode"></div>
                </div>
                
                <div class="qr-details" id="qrDetails">
                    <!-- Details will be populated by JavaScript -->
                </div>
            </div>
            
            <div class="qr-modal-footer">
                <button class="qr-btn qr-btn-secondary" onclick="downloadQRCode()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                    Download QR
                </button>
                <button class="qr-btn qr-btn-primary" onclick="closeQrModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    Done
                </button>
            </div>
        </div>
    </div>
    
    <!-- QR Code Library -->
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    
    <script>
        // Filter appointments by status
        function filterAppointments(status) {
            // Update active tab
            document.querySelectorAll('.filter-tab').forEach(tab => {
                tab.classList.remove('active');
                if (tab.dataset.filter === status) {
                    tab.classList.add('active');
                }
            });
            
            // Filter appointment items
            const items = document.querySelectorAll('.appointment-item');
            items.forEach(item => {
                if (status === 'all' || item.dataset.status === status) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }
        
        // View QR code for appointment
        function viewAppointmentQR(appointmentData) {
            // Update reference number
            document.getElementById('qrRefNumber').textContent = appointmentData.reference;
            
            // Format time for display
            const timeStr = formatTime12Hour(appointmentData.time);
            
            // Populate details
            const detailsHtml = `
                <div class="qr-detail-row">
                    <span class="label">Customer</span>
                    <span class="value">${escapeHtml(appointmentData.customer)}</span>
                </div>
                <div class="qr-detail-row">
                    <span class="label">Date</span>
                    <span class="value">${formatDateDisplay(appointmentData.date)}</span>
                </div>
                <div class="qr-detail-row">
                    <span class="label">Time</span>
                    <span class="value">${timeStr}</span>
                </div>
                <div class="qr-detail-row">
                    <span class="label">Service</span>
                    <span class="value">${escapeHtml(appointmentData.service)}</span>
                </div>
                <div class="qr-detail-row">
                    <span class="label">Helmet Type</span>
                    <span class="value">${escapeHtml(appointmentData.helmet_type)}</span>
                </div>
                <div class="qr-detail-row">
                    <span class="label">Quantity</span>
                    <span class="value">${appointmentData.quantity} helmet(s)</span>
                </div>
                <div class="qr-detail-row">
                    <span class="label">Price</span>
                    <span class="value" style="color: var(--primary-red);">₱${Number(appointmentData.price).toLocaleString()}</span>
                </div>
                <div class="qr-detail-row">
                    <span class="label">Status</span>
                    <span class="value" style="color: var(--warning); text-transform: uppercase;">${appointmentData.status}</span>
                </div>
            `;
            document.getElementById('qrDetails').innerHTML = detailsHtml;
            
            // Create QR code data string (JSON format for easy parsing)
            const qrDataString = JSON.stringify({
                ref: appointmentData.reference,
                id: appointmentData.id,
                name: appointmentData.customer,
                phone: appointmentData.contact,
                date: appointmentData.date,
                time: appointmentData.time,
                service: appointmentData.service,
                helmet: appointmentData.helmet_type,
                qty: appointmentData.quantity,
                price: appointmentData.price,
                status: appointmentData.status
            });
            
            // Clear previous QR code
            const qrcodeContainer = document.getElementById('qrcode');
            qrcodeContainer.innerHTML = '';
            
            // Generate QR code
            try {
                new QRCode(qrcodeContainer, {
                    text: qrDataString,
                    width: 160,
                    height: 160,
                    colorDark: '#1a1a1f',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            } catch (error) {
                console.error('QR Code generation error:', error);
                qrcodeContainer.innerHTML = '<p style="color: #ef476f;">Failed to generate QR code</p>';
            }
            
            // Show modal
            document.getElementById('qrModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        // Close QR modal
        function closeQrModal() {
            document.getElementById('qrModal').classList.remove('active');
            document.body.style.overflow = '';
        }
        
        // Download QR code as image
        function downloadQRCode() {
            let sourceElement = document.querySelector('#qrcode canvas') || document.querySelector('#qrcode img');
            
            if (!sourceElement) {
                alert('QR code not available for download');
                return;
            }
            
            // Create a new canvas with appointment info
            const downloadCanvas = document.createElement('canvas');
            const ctx = downloadCanvas.getContext('2d');
            const padding = 30;
            const headerHeight = 60;
            const footerHeight = 80;
            const qrSize = 200;
            
            downloadCanvas.width = qrSize + (padding * 2);
            downloadCanvas.height = qrSize + (padding * 2) + headerHeight + footerHeight;
            
            // Fill background
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, downloadCanvas.width, downloadCanvas.height);
            
            // Add header text
            ctx.fillStyle = '#1a1a1f';
            ctx.font = 'bold 18px Inter, Arial, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('Appointment QR Code', downloadCanvas.width / 2, 35);
            
            // Draw QR code
            ctx.drawImage(sourceElement, padding, headerHeight, qrSize, qrSize);
            
            // Add reference number below QR
            const refNumber = document.getElementById('qrRefNumber').textContent;
            ctx.fillStyle = '#e63946';
            ctx.font = 'bold 16px Courier New, monospace';
            ctx.fillText(refNumber, downloadCanvas.width / 2, qrSize + headerHeight + 30);
            
            // Add instruction text
            ctx.fillStyle = '#666666';
            ctx.font = '12px Inter, Arial, sans-serif';
            ctx.fillText('Scan at shop for quick check-in', downloadCanvas.width / 2, qrSize + headerHeight + 55);
            
            // Download
            const link = document.createElement('a');
            link.download = `appointment-${refNumber}.png`;
            link.href = downloadCanvas.toDataURL('image/png');
            link.click();
        }
        
        // Format time to 12-hour format
        function formatTime12Hour(time) {
            const [hours, minutes] = time.split(':');
            const hour = parseInt(hours);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            const hour12 = hour % 12 || 12;
            return `${hour12}:${minutes} ${ampm}`;
        }
        
        // Format date for display
        function formatDateDisplay(dateStr) {
            const date = new Date(dateStr);
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            return date.toLocaleDateString('en-US', options);
        }
        
        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Close QR modal on overlay click
        document.getElementById('qrModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeQrModal();
            }
        });
        
        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeQrModal();
            }
        });
    </script>
</body>
</html>
