<?php
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in and has admin role
if (!isLoggedIn()) {
    redirect('login.php');
}

if (!isAdmin()) {
    redirect('login.php');
}

$db = Database::getInstance()->getConnection();

// Handle walk-in appointment creation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $fullName = trim($_POST['full_name'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $appointmentDate = $_POST['appointment_date'] ?? '';
    $appointmentTime = $_POST['appointment_time'] ?? '';
    $helmetType = $_POST['helmet_type'] ?? '';
    $quantity = (int)($_POST['quantity'] ?? 1);
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $selectedAddons = $_POST['addons'] ?? [];
    
    if (empty($fullName) || empty($contact) || empty($appointmentDate) || empty($appointmentTime) || empty($serviceId)) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        // Check if time slot is already booked
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND status NOT IN ('cancelled', 'completed')");
        $checkStmt->execute([$appointmentDate, $appointmentTime]);
        if ($checkStmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'Selected time slot is already booked.']);
            exit;
        }
        
        // Prepare addons data
        $addonsJson = null;
        if (!empty($selectedAddons) && is_array($selectedAddons)) {
            // Convert addon IDs to proper format
            $addonIds = array_map('intval', $selectedAddons);
            $addonsJson = json_encode($addonIds);
        }
        
        // Insert walk-in appointment (user_id = NULL for walk-ins)
        $insertStmt = $db->prepare("
            INSERT INTO appointments (user_id, full_name, contact, appointment_date, appointment_time, helmet_type, quantity, service_id, notes, addons, status, created_at, updated_at)
            VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
        ");
        
        $result = $insertStmt->execute([
            $fullName,
            $contact,
            $appointmentDate,
            $appointmentTime,
            $helmetType,
            $quantity,
            $serviceId,
            $notes,
            $addonsJson
        ]);
        
        if (!$result) {
            $errorInfo = $insertStmt->errorInfo();
            throw new Exception("Database error: " . $errorInfo[2]);
        }
        
        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Walk-in appointment created successfully!']);
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Walk-in appointment creation error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Walk-in appointment error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Fetch services
$services = [];
try {
    $stmt = $db->query("SELECT id, label, name, price, duration FROM services WHERE status = 'active' ORDER BY label");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Services table might not exist yet
}

// Fetch available add-ons
$addons = [];
try {
    $stmt = $db->query("SELECT id, name, price, description FROM addons WHERE status = 'active' ORDER BY name");
    $addons = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Addons table might not exist yet
}

// Fetch all appointments for calendar display
$calendarAppointments = [];
try {
    $stmt = $db->query("SELECT appointment_date, full_name, appointment_time, status, user_id FROM appointments WHERE appointment_date >= CURDATE() ORDER BY appointment_date, appointment_time");
    $allAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group appointments by date
    foreach ($allAppointments as $apt) {
        $date = $apt['appointment_date'];
        if (!isset($calendarAppointments[$date])) {
            $calendarAppointments[$date] = [];
        }
        $calendarAppointments[$date][] = [
            'name' => $apt['full_name'],
            'time' => $apt['appointment_time'],
            'status' => $apt['status'],
            'is_walkin' => ($apt['user_id'] === null || (int)$apt['user_id'] === 0)
        ];
    }
} catch (PDOException $e) {
    // Appointments table might not exist yet
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Walk-in Calendar - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        /* Calendar Section */
        .calendar-section {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            overflow: hidden;
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--cream);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .section-title svg {
            color: var(--primary-red);
        }
        
        /* Calendar Grid */
        .calendar {
            background: var(--dark-bg);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            width: 100%;
            overflow: hidden;
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .calendar-header h3 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--cream);
        }
        
        .calendar-nav {
            display: flex;
            gap: 0.5rem;
        }
        
        .calendar-nav button {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--gray-text);
            padding: 8px 12px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .calendar-nav button:hover {
            background: var(--dark-hover);
            border-color: var(--primary-red);
            color: var(--cream);
        }
        
        .calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            width: 100%;
        }
        
        .calendar-weekdays span {
            text-align: center;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--gray-text);
            padding: 0.5rem;
        }
        
        .calendar-days {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 0.5rem;
            width: 100%;
        }
        
        .calendar-day {
            position: relative;
            aspect-ratio: 1;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
            background: var(--dark-card);
            display: flex;
            flex-direction: column;
            min-width: 0;
            overflow: hidden;
            box-sizing: border-box;
        }
        
        .calendar-day:hover:not(.disabled):not(.empty) {
            background: var(--dark-hover);
            border-color: var(--primary-red);
            transform: translateY(-2px);
        }
        
        .calendar-day.today {
            border-color: var(--primary-red);
            border-width: 2px;
        }
        
        .calendar-day.selected {
            background: var(--primary-red);
            border-color: var(--primary-red);
        }
        
        .calendar-day.disabled {
            opacity: 0.3;
            cursor: not-allowed;
            pointer-events: none;
        }
        
        .calendar-day.empty {
            border: none;
            cursor: default;
            pointer-events: none;
        }
        
        .calendar-day .day-number {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--cream);
            margin-bottom: 0.25rem;
        }
        
        .calendar-day .day-appointments {
            flex: 1;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }
        
        .calendar-day .apt-name {
            font-size: 0.7rem;
            padding: 2px 4px;
            background: rgba(230, 57, 70, 0.15);
            border-radius: 4px;
            color: var(--primary-red);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: block;
            min-width: 0;
        }
        
        .calendar-day .apt-name.walkin {
            background: rgba(156, 39, 176, 0.18);
            color: #d29bfd;
        }
        
        .calendar-day .apt-more {
            font-size: 0.65rem;
            color: var(--gray-text);
            text-align: center;
            padding: 2px;
        }
        
        .calendar-day .apt-count {
            position: absolute;
            top: 4px;
            right: 4px;
            background: var(--primary-red);
            color: white;
            font-size: 0.65rem;
            font-weight: 700;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            padding: 1rem;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--cream);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .modal-close {
            background: transparent;
            border: none;
            color: var(--gray-text);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            transition: all 0.2s;
        }
        
        .modal-close:hover {
            background: var(--dark-hover);
            color: var(--cream);
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .selected-date-display {
            background: var(--dark-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .selected-date-display .date-icon {
            background: var(--primary-red);
            color: white;
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .selected-date-display .date-info h4 {
            color: var(--cream);
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .selected-date-display .date-info p {
            color: var(--gray-text);
            font-size: 0.9rem;
        }
        
        .form-section {
            margin-bottom: 1.5rem;
        }
        
        .form-section-title {
            color: var(--cream);
            font-weight: 600;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .time-slots {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.5rem;
        }
        
        .time-slot {
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            background: var(--dark-bg);
            color: var(--cream);
        }
        
        .time-slot:hover {
            border-color: var(--primary-red);
            background: var(--dark-hover);
        }
        
        .time-slot.selected {
            background: var(--primary-red);
            border-color: var(--primary-red);
            color: white;
        }
        
        .time-slot.disabled {
            opacity: 0.5;
            cursor: pointer;
            background: rgba(230, 57, 70, 0.1);
            border-color: rgba(230, 57, 70, 0.3);
        }
        
        .time-slot.disabled:hover {
            opacity: 0.7;
            background: rgba(230, 57, 70, 0.2);
            border-color: var(--primary-red);
        }
        
        .service-options {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .service-option {
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.2s;
            background: var(--dark-bg);
        }
        
        .service-option:hover {
            border-color: var(--primary-red);
        }
        
        .service-option.selected {
            border-color: var(--primary-red);
            background: rgba(230, 57, 70, 0.1);
        }
        
        .service-option input {
            display: none;
        }
        
        .service-info h5 {
            color: var(--cream);
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .service-info span {
            color: var(--gray-text);
            font-size: 0.85rem;
        }
        
        .service-price {
            color: var(--primary-red);
            font-weight: 700;
            font-size: 1.1rem;
        }
        
        .form-input,
        .form-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--dark-bg);
            color: var(--cream);
            font-size: 0.95rem;
        }
        
        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-red);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            color: var(--gray-text);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .notes-input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--dark-bg);
            color: var(--cream);
            font-size: 0.95rem;
            resize: vertical;
            min-height: 80px;
        }
        
        .notes-input:focus {
            outline: none;
            border-color: var(--primary-red);
        }
        
        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }
        
        .submit-btn {
            background: var(--primary-red);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .submit-btn:hover {
            background: #c9303e;
        }
        
        .submit-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .cancel-btn {
            background: transparent;
            color: var(--gray-text);
            border: 1px solid var(--border-color);
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .cancel-btn:hover {
            border-color: var(--primary-red);
            color: var(--cream);
        }
        
        /* Add-ons Styles */
        .addons-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .addon-item {
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.2s;
            background: var(--dark-bg);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .addon-item:hover {
            border-color: var(--primary-red);
            background: var(--dark-hover);
        }
        
        .addon-item:has(input:checked) {
            border-color: var(--primary-red);
            background: rgba(230, 57, 70, 0.1);
        }
        
        .addon-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: var(--primary-red);
        }
        
        .addon-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .addon-name {
            color: var(--cream);
            font-weight: 600;
            font-size: 0.95rem;
        }
        
        .addon-description {
            color: var(--gray-text);
            font-size: 0.85rem;
        }
        
        .addon-price {
            color: var(--primary-red);
            font-weight: 700;
            font-size: 1rem;
        }
        
        /* Price Summary Styles */
        .price-breakdown {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .price-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            color: var(--gray-text);
            border-bottom: 1px solid var(--border-color);
        }
        
        .price-row.total {
            border-bottom: none;
            padding-top: 1rem;
            margin-top: 0.5rem;
            border-top: 2px solid var(--border-color);
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--cream);
        }
        
        .price-row.total span:last-child {
            color: var(--primary-red);
            font-size: 1.25rem;
        }
        
        @media (max-width: 768px) {
            .calendar-section {
                padding: 1rem;
            }

            .calendar {
                padding: 1rem;
            }

            .calendar-header {
                flex-wrap: wrap;
                gap: 0.75rem;
                align-items: flex-start;
            }

            .calendar-header h3 {
                min-width: 0;
                font-size: 1.05rem;
            }

            .calendar-weekdays,
            .calendar-days {
                gap: 0.35rem;
            }

            .calendar-weekdays span {
                font-size: 0.72rem;
                padding: 0.35rem 0.1rem;
            }

            .calendar-day {
                padding: 0.3rem;
                min-height: 72px;
            }

            .calendar-day .day-number {
                font-size: 0.75rem;
            }
            
            .calendar-day .apt-name {
                font-size: 0.6rem;
            }

            .calendar-day .apt-count {
                width: 16px;
                height: 16px;
                font-size: 0.6rem;
            }

            .modal-header,
            .modal-body,
            .modal-footer {
                padding: 1rem;
            }

            #viewDateModal .modal,
            #timeSlotBookedModal .modal {
                max-width: 100% !important;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .time-slots {
                grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            }
        }

        @media (max-width: 480px) {
            .calendar-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .calendar-nav {
                width: 100%;
                justify-content: flex-end;
            }

            .calendar-nav button {
                padding: 6px 10px;
            }

            .calendar {
                padding: 0.75rem;
            }

            .calendar-weekdays span {
                font-size: 0.64rem;
            }

            .calendar-day {
                min-height: 60px;
                padding: 0.22rem;
            }

            .calendar-day .apt-name {
                display: none;
            }

            .calendar-day .apt-more {
                font-size: 0.58rem;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include '../includes/sidebar.php'; ?>
        <?php include '../includes/header.php'; ?>
        <div class="sidebar-overlay" onclick="toggleSidebar()"></div>

        <main class="main-content">
            <div class="dashboard-header">
                <h1>Walk-in Calendar</h1>
                <p>Book walk-in appointments manually</p>
            </div>

            <div class="calendar-section">
                <div class="section-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                    Select a Date
                </div>
                
                <div class="calendar">
                    <div class="calendar-header">
                        <h3 id="currentMonth"></h3>
                        <div class="calendar-nav">
                            <button onclick="changeMonth(-1)">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="15 18 9 12 15 6"></polyline>
                                </svg>
                            </button>
                            <button onclick="changeMonth(1)">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="9 18 15 12 9 6"></polyline>
                                </svg>
                            </button>
                        </div>
                    </div>
                    
                    <div class="calendar-weekdays">
                        <span>Sun</span>
                        <span>Mon</span>
                        <span>Tue</span>
                        <span>Wed</span>
                        <span>Thu</span>
                        <span>Fri</span>
                        <span>Sat</span>
                    </div>
                    
                    <div class="calendar-days" id="calendarDays"></div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- View Date Appointments Modal -->
    <div class="modal-overlay" id="viewDateModal">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <h2>
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                    Appointments
                </h2>
                <button class="modal-close" onclick="closeViewDateModal()">&times;</button>
            </div>
            <div class="modal-body">
                <h3 id="viewDateTitle" style="color: var(--cream); margin-bottom: 1rem;"></h3>
                <div id="viewDateAppointmentsList"></div>
            </div>
        </div>
    </div>

    <!-- Time Slot Already Booked Modal -->
    <div class="modal-overlay" id="timeSlotBookedModal" style="z-index: 10000;">
        <div class="modal" style="max-width: 450px;">
            <div class="modal-header">
                <h2>
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    Time Slot Booked
                </h2>
                <button class="modal-close" onclick="closeTimeSlotBookedModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="color: var(--gray-text); margin-bottom: 1rem;">This time slot is already booked:</p>
                <div style="background: var(--dark-bg); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color);">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                        <span style="color: var(--gray-text);">Time:</span>
                        <span id="bookedTimeDisplay" style="color: var(--cream); font-weight: 600;"></span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: var(--gray-text);">Booked by:</span>
                        <span id="bookedByDisplay" style="color: var(--primary-red); font-weight: 600;"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="cancel-btn" onclick="closeTimeSlotBookedModal()">Close</button>
            </div>
        </div>
    </div>
    
    <!-- Booking Modal -->
    <div class="modal-overlay" id="bookingModal">
        <div class="modal">
            <div class="modal-header">
                <h2>
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="8.5" cy="7" r="4"></circle>
                        <line x1="20" y1="8" x2="20" y2="14"></line>
                        <line x1="23" y1="11" x2="17" y2="11"></line>
                    </svg>
                    Book Walk-in
                </h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            
            <form id="bookingForm">
                <div class="modal-body">
                    <div class="selected-date-display">
                        <div class="date-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                        </div>
                        <div class="date-info">
                            <h4 id="selectedDateText"></h4>
                            <p id="selectedDayText"></p>
                        </div>
                    </div>
                    
                    <input type="hidden" name="appointment_date" id="appointmentDate">
                    
                    <div class="form-section">
                        <div class="form-section-title">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                            Select Time
                        </div>
                        <div class="time-slots" id="timeSlots"></div>
                        <input type="hidden" name="appointment_time" id="appointmentTime">
                    </div>
                    
                    <div class="form-section">
                        <div class="form-section-title">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                            </svg>
                            Select Service
                        </div>
                        <div class="service-options">
                            <?php foreach ($services as $service): ?>
                            <label class="service-option">
                                <input type="radio" name="service_id" value="<?php echo $service['id']; ?>" required>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div class="service-info">
                                        <h5><?php echo htmlspecialchars($service['label'] . ': ' . $service['name']); ?></h5>
                                        <span><?php echo $service['duration']; ?> minutes</span>
                                    </div>
                                    <div class="service-price">₱<?php echo number_format($service['price'], 2); ?></div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="form-section-title">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                            Customer Information
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Full Name *</label>
                                <input type="text" name="full_name" class="form-input" placeholder="e.g., Juan Dela Cruz" required>
                            </div>
                            <div class="form-group">
                                <label>Contact Number *</label>
                                <input type="tel" name="contact" class="form-input" placeholder="e.g., 09171234567" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="form-section-title">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 3v18m0-18C6.477 3 2 7.477 2 13v6a2 2 0 002 2h16a2 2 0 002-2v-6c0-5.523-4.477-10-10-10z"></path>
                            </svg>
                            Service Details
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Helmet Type *</label>
                                <select name="helmet_type" class="form-select" required>
                                    <option value="Full Face">Full Face</option>
                                    <option value="Half Face">Half Face</option>
                                    <option value="Open Face">Open Face</option>
                                    <option value="Modular">Modular</option>
                                    <option value="Off-Road">Off-Road</option>
                                    <option value="Dual Sport">Dual Sport</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Quantity *</label>
                                <input type="number" name="quantity" class="form-input" min="1" max="10" value="1" required>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Add-ons Section -->
                    <?php if (!empty($addons)): ?>
                    <div class="form-section">
                        <div class="form-section-title">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="16"></line>
                                <line x1="8" y1="12" x2="16" y2="12"></line>
                            </svg>
                            Add-ons (Optional)
                        </div>
                        <p style="font-size: 0.85rem; color: var(--gray-text); margin-bottom: 0.75rem;">Enhance your service with these extras</p>
                        <div class="addons-list">
                            <?php foreach ($addons as $addon): ?>
                            <label class="addon-item" data-addon-id="<?php echo $addon['id']; ?>" data-addon-price="<?php echo $addon['price']; ?>">
                                <input type="checkbox" name="addons[]" value="<?php echo $addon['id']; ?>" onchange="updateTotalPrice()">
                                <div class="addon-info">
                                    <span class="addon-name"><?php echo htmlspecialchars($addon['name']); ?></span>
                                    <?php if (!empty($addon['description'])): ?>
                                    <span class="addon-description"><?php echo htmlspecialchars($addon['description']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="addon-price">+₱<?php echo number_format($addon['price'], 0); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Price Summary -->
                    <div class="form-section" id="priceSummary" style="display: none;">
                        <div class="form-section-title">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="1" x2="12" y2="23"></line>
                                <line x1="17" y1="5" x2="9" y2="5"></line>
                                <line x1="17" y1="13" x2="9" y2="13"></line>
                                <line x1="17" y1="21" x2="9" y2="21"></line>
                            </svg>
                            Price Summary
                        </div>
                        <div class="price-breakdown">
                            <div class="price-row">
                                <span>Service</span>
                                <span id="servicePriceDisplay">₱0</span>
                            </div>
                            <div id="addonsPriceRows"></div>
                            <div class="price-row">
                                <span>Quantity</span>
                                <span id="quantityDisplay">1</span>
                            </div>
                            <div class="price-row total">
                                <span>Total</span>
                                <span id="totalPriceDisplay">₱0</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="form-section-title">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                            Additional Notes (Optional)
                        </div>
                        <div class="form-group">
                            <textarea name="notes" class="notes-input" placeholder="Any special requests or additional information..."></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="cancel-btn" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="submit-btn">Create Appointment</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Calendar variables
        let currentDate = new Date();
        let selectedDate = null;
        
        // Calendar appointments data
        const calendarAppointments = <?php echo json_encode($calendarAppointments); ?>;
        
        // Services data
        const servicesData = <?php echo json_encode($services); ?>;
        
        // Month and day names
        const monthNames = ["January", "February", "March", "April", "May", "June",
            "July", "August", "September", "October", "November", "December"];
        const dayNames = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
        
        // Initialize
        function initCalendar() {
            renderCalendar();
            setupFormListeners();
        }
        
        // Setup form listeners
        function setupFormListeners() {
            const form = document.getElementById('bookingForm');
            if (form) {
                form.addEventListener('submit', handleFormSubmit);
            }
            
            // Service selection
            document.querySelectorAll('.service-option').forEach(option => {
                option.addEventListener('click', function() {
                    document.querySelectorAll('.service-option').forEach(opt => opt.classList.remove('selected'));
                    this.classList.add('selected');
                    this.querySelector('input').checked = true;
                    updateTotalPrice();
                });
            });
            
            // Quantity change
            const quantityInput = document.querySelector('input[name="quantity"]');
            if (quantityInput) {
                quantityInput.addEventListener('change', updateTotalPrice);
            }
        }
        
        // Update total price calculation
        function updateTotalPrice() {
            const selectedService = document.querySelector('.service-option input:checked');
            const quantity = parseInt(document.querySelector('input[name="quantity"]').value) || 1;
            const checkedAddons = document.querySelectorAll('.addon-item input[type="checkbox"]:checked');
            
            let servicePrice = 0;
            if (selectedService) {
                const serviceId = parseInt(selectedService.value);
                const service = servicesData.find(s => s.id === serviceId);
                if (service) {
                    servicePrice = parseFloat(service.price) || 0;
                }
            }
            
            let addonsTotal = 0;
            let addonsHtml = '';
            checkedAddons.forEach(addon => {
                const addonItem = addon.closest('.addon-item');
                const addonPrice = parseFloat(addonItem.dataset.addonPrice) || 0;
                const addonName = addonItem.querySelector('.addon-name').textContent;
                addonsTotal += addonPrice;
                addonsHtml += `<div class="price-row">
                    <span>${addonName}</span>
                    <span>₱${addonPrice.toLocaleString()}</span>
                </div>`;
            });
            
            const subtotal = servicePrice + addonsTotal;
            const total = subtotal * quantity;
            
            // Update display
            document.getElementById('servicePriceDisplay').textContent = '₱' + servicePrice.toLocaleString();
            document.getElementById('addonsPriceRows').innerHTML = addonsHtml;
            document.getElementById('quantityDisplay').textContent = quantity;
            document.getElementById('totalPriceDisplay').textContent = '₱' + total.toLocaleString();
            
            // Show/hide price summary
            const priceSummary = document.getElementById('priceSummary');
            if (selectedService || checkedAddons.length > 0) {
                priceSummary.style.display = 'block';
            } else {
                priceSummary.style.display = 'none';
            }
        }
        
        // Handle form submission
        function handleFormSubmit(e) {
            e.preventDefault();
            
            const submitBtn = e.target.querySelector('.submit-btn');
            const originalText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Creating...';
            
            const formData = new FormData(e.target);
            
            fetch('walkin-calendar.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Walk-in appointment created successfully!');
                    closeModal();
                    location.reload();
                } else {
                    alert(data.message || 'Failed to create appointment');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to create appointment. Please try again.');
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            });
        }
        
        // Render calendar
        function renderCalendar() {
            const year = currentDate.getFullYear();
            const month = currentDate.getMonth();
            
            document.getElementById('currentMonth').textContent = `${monthNames[month]} ${year}`;
            
            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const today = new Date();
            
            let html = '';
            
            // Empty cells before first day
            for (let i = 0; i < firstDay; i++) {
                html += '<div class="calendar-day empty"></div>';
            }
            
            // Days of month
            for (let day = 1; day <= daysInMonth; day++) {
                const date = new Date(year, month, day);
                const dateStr = formatDate(date);
                const isPast = date < new Date(today.getFullYear(), today.getMonth(), today.getDate());
                const isToday = dateStr === formatDate(today);
                
                const appointments = calendarAppointments[dateStr] || [];
                const appointmentCount = appointments.length;
                
                let classes = 'calendar-day';
                if (isPast) classes += ' disabled';
                if (isToday) classes += ' today';
                
                html += `<div class="${classes}" onclick="selectDate('${dateStr}', ${day})">`;
                html += `<div class="day-number">${day}</div>`;
                
                if (appointmentCount > 0) {
                    html += '<div class="day-appointments">';
                    appointments.slice(0, 2).forEach(apt => {
                        const walkinClass = apt.is_walkin ? 'walkin' : '';
                        html += `<div class="apt-name ${walkinClass}" title="${apt.name} - ${apt.time}">${apt.name}</div>`;
                    });
                    if (appointmentCount > 2) {
                        html += `<div class="apt-more">+${appointmentCount - 2} more</div>`;
                    }
                    html += '</div>';
                    html += `<span class="apt-count">${appointmentCount}</span>`;
                }
                
                html += '</div>';
            }
            
            document.getElementById('calendarDays').innerHTML = html;
        }
        
        // Change month
        function changeMonth(direction) {
            currentDate.setMonth(currentDate.getMonth() + direction);
            renderCalendar();
        }
        
        // Format date as YYYY-MM-DD
        function formatDate(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }
        
        // View all appointments for a specific date
        function viewDateAppointments(dateStr, day) {
            const appointments = calendarAppointments[dateStr] || [];
            const date = new Date(dateStr);
            const monthName = monthNames[date.getMonth()];
            
            document.getElementById('viewDateTitle').textContent = `${monthName} ${day}, ${date.getFullYear()}`;
            
            let html = '';
            if (appointments.length > 0) {
                appointments.forEach(apt => {
                    const walkinBadge = apt.is_walkin ? '<span style="background: rgba(156, 39, 176, 0.2); color: #d29bfd; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; margin-left: 8px;">Walk-in</span>' : '';
                    html += `
                        <div style="background: var(--dark-bg); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 0.75rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                <span style="color: var(--cream); font-weight: 600;">${apt.name}${walkinBadge}</span>
                                <span style="color: var(--primary-red); font-weight: 600;">${formatTime12Hour(apt.time)}</span>
                            </div>
                            <div style="color: var(--gray-text); font-size: 0.85rem;">Status: ${apt.status}</div>
                        </div>
                    `;
                });
            } else {
                html = '<p style="color: var(--gray-text); text-align: center; padding: 2rem;">No appointments for this date</p>';
            }
            
            document.getElementById('viewDateAppointmentsList').innerHTML = html;
            document.getElementById('viewDateModal').classList.add('active');
        }
        
        // Close view date appointments modal
        function closeViewDateModal() {
            document.getElementById('viewDateModal').classList.remove('active');
        }
        
        // Show time slot booked modal with who booked it
        function showTimeSlotBooked(time, dateStr) {
            const appointments = calendarAppointments[dateStr] || [];
            const bookedApt = appointments.find(apt => apt.time === time);
            
            if (bookedApt) {
                const [hours, minutes] = time.split(':');
                const hour = parseInt(hours);
                const displayTime = `${hour % 12 || 12}:${minutes} ${hour >= 12 ? 'PM' : 'AM'}`;
                
                document.getElementById('bookedTimeDisplay').textContent = displayTime;
                document.getElementById('bookedByDisplay').textContent = bookedApt.name + (bookedApt.is_walkin ? ' (Walk-in)' : '');
                document.getElementById('timeSlotBookedModal').classList.add('active');
            }
        }
        
        // Close time slot booked modal
        function closeTimeSlotBookedModal() {
            document.getElementById('timeSlotBookedModal').classList.remove('active');
        }
        
        // Format time to 12-hour format
        function formatTime12Hour(time) {
            const [hours, minutes] = time.split(':');
            const hour = parseInt(hours);
            return `${hour % 12 || 12}:${minutes} ${hour >= 12 ? 'PM' : 'AM'}`;
        }
        
        // Select date
        function selectDate(dateStr, day) {
            selectedDate = dateStr;
            document.getElementById('appointmentDate').value = dateStr;
            
            const date = new Date(dateStr);
            const dayName = dayNames[date.getDay()];
            const monthName = monthNames[date.getMonth()];
            
            document.getElementById('selectedDateText').textContent = `${monthName} ${day}, ${date.getFullYear()}`;
            document.getElementById('selectedDayText').textContent = dayName;
            
            populateTimeSlots(dateStr);
            openModal();
        }
        
        // Populate time slots
        function populateTimeSlots(dateStr) {
            const timeSlots = [
                '10:00', '10:30', '11:00', '11:30',
                '12:00', '12:30', '13:00', '13:30',
                '14:00', '14:30', '15:00', '15:30',
                '16:00', '16:30', '17:00', '17:30',
                '18:00', '18:30', '19:00', '19:30',
                '20:00'
            ];
            const bookedTimes = (calendarAppointments[dateStr] || []).map(apt => apt.time);
            
            let html = '';
            timeSlots.forEach(time => {
                const [hours, minutes] = time.split(':');
                const hour = parseInt(hours);
                const displayTime = `${hour % 12 || 12}:${minutes} ${hour >= 12 ? 'PM' : 'AM'}`;
                
                // Check both with and without seconds for booked times
                const isBooked = bookedTimes.includes(time) || bookedTimes.includes(time + ':00');
                const classes = isBooked ? 'time-slot disabled' : 'time-slot';
                // If booked, show modal with who booked it; otherwise allow selection
                const onclick = isBooked ? `onclick="showTimeSlotBooked('${time}:00', '${dateStr}')"` : `onclick="selectTime('${time}:00')"`;
                const title = isBooked ? 'Click to see who booked this time' : 'Available';
                
                html += `<div class="${classes}" ${onclick} title="${title}">${displayTime}</div>`;
            });
            
            document.getElementById('timeSlots').innerHTML = html;
        }
        
        // Select time
        function selectTime(time) {
            document.querySelectorAll('.time-slot').forEach(slot => slot.classList.remove('selected'));
            event.target.classList.add('selected');
            document.getElementById('appointmentTime').value = time;
        }
        
        // Open modal
        function openModal() {
            document.getElementById('bookingModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        // Close modal
        function closeModal() {
            document.getElementById('bookingModal').classList.remove('active');
            document.body.style.overflow = '';
            document.getElementById('bookingForm').reset();
            document.querySelectorAll('.service-option').forEach(opt => opt.classList.remove('selected'));
            document.querySelectorAll('.time-slot').forEach(slot => slot.classList.remove('selected'));
        }
        
        // Close on overlay click
        document.getElementById('bookingModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
        
        document.getElementById('viewDateModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeViewDateModal();
        });
        
        document.getElementById('timeSlotBookedModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeTimeSlotBookedModal();
        });
        
        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeViewDateModal();
                closeTimeSlotBookedModal();
            }
        });
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', initCalendar);
    </script>
</body>
</html>
