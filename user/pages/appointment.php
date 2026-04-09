<?php
require_once __DIR__ . '/../../config/config.php';

if (!isLoggedIn()) { redirect('login.php'); }
if (!isUser()) {
    if (isAdmin())  redirect('admin/pages/dashboard.php');
    elseif (isStaff()) redirect('staff/pages/dashboard.php');
}

$userName  = $_SESSION['user_name']  ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$userId    = $_SESSION['user_id']    ?? 0;

$message   = '';
$messageType = '';
$qrAppointmentData = null;
$showQrModal = false;

/* ══════════════ FORM SUBMISSION ══════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_appointment'])) {
    $fullName        = trim($_POST['full_name']       ?? '');
    $contact         = trim($_POST['contact']         ?? '');
    $appointmentDate = $_POST['appointment_date']     ?? '';
    $appointmentTime = $_POST['appointment_time']     ?? '';
    $helmetType      = $_POST['helmet_type']          ?? '';
    $quantity        = max(1, (int)($_POST['quantity'] ?? 1));
    $serviceId       = $_POST['service_id']           ?? '';
    $notes           = trim($_POST['notes']           ?? '');
    $selectedAddons  = $_POST['addons']               ?? [];

    if (!$fullName || !$contact || !$appointmentDate || !$appointmentTime || !$helmetType || !$serviceId) {
        $message     = 'Please fill in all required fields.';
        $messageType = 'error';
    } else {
        try {
            $db = Database::getInstance()->getConnection();
            $addonsJson = !empty($selectedAddons) ? json_encode(array_map('intval', $selectedAddons)) : null;
            $stmt = $db->prepare("INSERT INTO appointments
                (user_id, service_id, appointment_date, appointment_time,
                 full_name, contact, helmet_type, quantity, notes, addons, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
            $stmt->execute([$userId, $serviceId, $appointmentDate, $appointmentTime,
                            $fullName, $contact, $helmetType, $quantity, $notes, $addonsJson]);
            $appointmentId = $db->lastInsertId();

            $svcStmt = $db->prepare("SELECT name, label, price FROM services WHERE id = ?");
            $svcStmt->execute([$serviceId]);
            $serviceInfo  = $svcStmt->fetch(PDO::FETCH_ASSOC);
            $serviceName  = $serviceInfo ? ($serviceInfo['label'] . ': ' . $serviceInfo['name']) : 'Service';
            $servicePrice = (float)($serviceInfo['price'] ?? 0);

            $addonTotalUnit = 0.0;
            $addonNames     = [];
            if (!empty($selectedAddons)) {
                $ph = implode(',', array_fill(0, count($selectedAddons), '?'));
                $adStmt = $db->prepare("SELECT name, price FROM addons WHERE id IN ($ph)");
                $adStmt->execute(array_map('intval', $selectedAddons));
                foreach ($adStmt->fetchAll(PDO::FETCH_ASSOC) as $ad) {
                    $addonTotalUnit += (float)$ad['price'];
                    $addonNames[]    = $ad['name'];
                }
            }

            $serviceTotal = $servicePrice * $quantity;
            $addonsTotal  = $addonTotalUnit * $quantity;
            $grandTotal   = $serviceTotal + $addonsTotal;

            try {
                $db->exec("CREATE TABLE IF NOT EXISTS notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NULL, role VARCHAR(50) NULL,
                    type VARCHAR(100) NULL, title VARCHAR(255) NOT NULL,
                    body TEXT NULL, url VARCHAR(255) NULL,
                    is_read TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX (user_id), INDEX (role), INDEX (is_read)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

                $ref      = 'APT-' . str_pad($appointmentId, 6, '0', STR_PAD_LEFT);
                $notifMsg = sprintf('%s booked %s on %s at %s',
                    $fullName, $serviceName,
                    date('M d, Y', strtotime($appointmentDate)),
                    date('g:i A', strtotime($appointmentTime)));

                $ins = $db->prepare("INSERT INTO notifications
                    (user_id, role, type, title, body, url, is_read, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 0, NOW())");

                $staffUrl = rtrim(APP_URL, '/') . '/staff/pages/appointments.php?scanned=' . $appointmentId;
                $adminUrl = rtrim(APP_URL, '/') . '/admin/pages/appointments.php';
                $ins->execute([null, 'staff', 'appointment.created', 'New Appointment Booking', $notifMsg, $staffUrl]);
                $ins->execute([null, 'admin', 'appointment.created', 'New Appointment Booking', $notifMsg, $adminUrl]);

                $userMsg = sprintf('Your appointment (%s) for %s on %s at %s has been booked. Total: ₱%s',
                    $ref, $serviceName,
                    date('M d, Y', strtotime($appointmentDate)),
                    date('g:i A', strtotime($appointmentTime)),
                    number_format($grandTotal, 2));
                $ins->execute([$userId, null, 'appointment.created', 'Appointment Booked', $userMsg,
                    rtrim(APP_URL, '/') . '/user/pages/my-appointments.php']);
            } catch (Exception $e) {
                error_log('Notification error: ' . $e->getMessage());
            }

            $qrAppointmentData = [
                'id'           => $appointmentId,
                'reference'    => $ref,
                'customer'     => $fullName,
                'contact'      => $contact,
                'date'         => $appointmentDate,
                'time'         => $appointmentTime,
                'service'      => $serviceName,
                'service_price'=> $servicePrice,
                'addon_total'  => $addonTotalUnit,
                'qty'          => $quantity,
                'price'        => $grandTotal,
                'helmet_type'  => $helmetType,
                'quantity'     => $quantity,
                'status'       => 'pending',
                'created_at'   => date('Y-m-d H:i:s'),
            ];

            $showQrModal = true;
            $message     = 'Appointment booked! Your QR code is ready.';
            $messageType = 'success';

        } catch (PDOException $e) {
            $message     = 'Failed to book appointment. Please try again.';
            $messageType = 'error';
            error_log('Booking error: ' . $e->getMessage());
        }
    }
}

/* ══════════════ FETCH DATA ═══════════════════════════════════════════ */
$services = [];
try {
    $db   = Database::getInstance()->getConnection();
    $stmt = $db->query("SELECT id, label, name, price, duration, description FROM services WHERE status = 'active' ORDER BY label");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $services = [
        ['id'=>1,'label'=>'X-1','name'=>'Basic Helmet Cleaning',    'price'=>150,'duration'=>30,'description'=>''],
        ['id'=>2,'label'=>'X-2','name'=>'Premium Helmet Cleaning',  'price'=>300,'duration'=>45,'description'=>''],
        ['id'=>3,'label'=>'X-3','name'=>'Deep Clean & Sanitize',    'price'=>500,'duration'=>60,'description'=>''],
    ];
}

$addons = [];
try {
    $db   = Database::getInstance()->getConnection();
    $stmt = $db->query("SELECT id, name, price, description FROM addons WHERE status = 'active' ORDER BY name");
    $addons = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$calendarAppointments = [];
try {
    $db   = Database::getInstance()->getConnection();
    $stmt = $db->query("SELECT appointment_date, full_name, appointment_time, status
                        FROM appointments WHERE appointment_date >= CURDATE()
                        ORDER BY appointment_date, appointment_time");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $apt) {
        $calendarAppointments[$apt['appointment_date']][] = [
            'name'   => $apt['full_name'],
            'time'   => $apt['appointment_time'],
            'status' => $apt['status'],
        ];
    }
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="en" class="allow-page-x-scroll">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Appointment — <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">

<style>
/*
 * ─────────────────────────────────────────────────────────────
 *  APPOINTMENT PAGE — scoped styles
 *  Dark mode = default (uses global CSS vars from style.css)
 *  Light mode = [data-theme="light"] overrides only
 *  NO :root overrides — nothing bleeds into other pages
 * ─────────────────────────────────────────────────────────────
 */

/* ── Scoped CSS custom properties (dark defaults) ── */
.main-content, .moverlay, .qr-overlay {
    --apt-card-bg:        var(--dark-card, #1a2030);
    --apt-card-border:    var(--border-color, rgba(255,255,255,0.08));
    --apt-input-bg:       rgba(255,255,255,0.04);
    --apt-input-hover:    rgba(255,255,255,0.06);
    --apt-item-bg:        rgba(255,255,255,0.03);
    --apt-item-hover:     rgba(230,57,70,0.08);
    --apt-overlay-bg:     rgba(0,0,0,0.65);
    --apt-text:           var(--cream, #f1f5f9);
    --apt-muted:          var(--gray-text, #94a3b8);
    --apt-red:            var(--primary-red, #E63946);
    --apt-thead-bg:       rgba(255,255,255,0.03);
    --apt-row-hover:      rgba(255,255,255,0.025);
    --apt-dot-bg:         rgba(230,57,70,0.55);
    --apt-tslot-bg:       rgba(255,255,255,0.04);
    --apt-price-bg:       rgba(34,197,94,0.05);
    --apt-price-border:   rgba(34,197,94,0.2);
    --apt-modal-bg:       var(--dark-card, #1a2030);
    --apt-cal-day-bg:     rgba(255,255,255,0.03);
    --apt-cal-today-bg:   rgba(230,57,70,0.12);
}

/* ── Light mode variable overrides ── */
[data-theme="light"] .main-content,
[data-theme="light"] .moverlay,
[data-theme="light"] .qr-overlay {
    --apt-card-bg:        #ffffff;
    --apt-card-border:    #e2e8f0;
    --apt-input-bg:       #f8fafc;
    --apt-input-hover:    #f1f5f9;
    --apt-item-bg:        #f8fafc;
    --apt-item-hover:     rgba(230,57,70,0.05);
    --apt-overlay-bg:     rgba(15,23,42,0.55);
    --apt-text:           #0f172a;
    --apt-muted:          #94a3b8;
    --apt-thead-bg:       #f1f5f9;
    --apt-row-hover:      #fafbff;
    --apt-dot-bg:         rgba(230,57,70,0.65);
    --apt-tslot-bg:       #f8fafc;
    --apt-price-bg:       rgba(16,185,129,0.04);
    --apt-price-border:   rgba(16,185,129,0.2);
    --apt-modal-bg:       #ffffff;
    --apt-cal-day-bg:     #f8fafc;
    --apt-cal-today-bg:   rgba(230,57,70,0.08);
}

*, *::before, *::after { box-sizing: border-box; }
.main-content, .main-content * { font-family: 'Plus Jakarta Sans', sans-serif; }

/* ── Layout ── */
.main-content {
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
.bk-header { margin-bottom: 1.75rem; }
.bk-header h1 {
    font-size: 1.65rem; font-weight: 800;
    color: var(--apt-text);
    letter-spacing: -.6px; margin: 0 0 4px;
    display: flex; align-items: center; gap: 10px;
}
.bk-header p { font-size: .875rem; color: var(--apt-muted); margin: 0; }

/* ── Calendar card ── */
.cal-card {
    background: var(--apt-card-bg);
    border: 1px solid var(--apt-card-border);
    border-radius: 18px;
    overflow: hidden;
    position: relative;
    box-shadow: 0 1px 4px rgba(0,0,0,.15);
}
[data-theme="light"] .cal-card { box-shadow: 0 1px 3px rgba(15,23,42,.07); }

.cal-card::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0; height: 3px;
    background: var(--gradient-red, linear-gradient(135deg,#e63946,#c1121f));
}
.cal-inner { padding: 1.75rem; }

.cal-nav-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
.cal-month-label { font-size: 1.5rem; font-weight: 800; color: var(--apt-text); letter-spacing: -.5px; }
.cal-nav { display: flex; gap: 8px; }
.cal-nav-btn {
    width: 40px; height: 40px; border-radius: 10px;
    background: var(--apt-input-bg); border: 1px solid var(--apt-card-border);
    color: var(--apt-text); cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: all .2s;
}
.cal-nav-btn:hover { background: var(--apt-red); border-color: var(--apt-red); color: #fff; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(230,57,70,.35); }

.cal-grid-scroll { width: 100%; }

.cal-weekdays { display: grid; grid-template-columns: repeat(7,minmax(0,1fr)); gap: 6px; margin-bottom: 10px; }
.cal-weekdays span {
    text-align: center; font-size: .72rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: .08em; color: var(--apt-muted); padding: 8px 0;
}
.cal-weekdays span:first-child, .cal-weekdays span:last-child { color: rgba(230,57,70,.65); }

.cal-days { display: grid; grid-template-columns: repeat(7,minmax(0,1fr)); gap: 6px; }
.cal-day {
    aspect-ratio: 1;
    min-height: 0;
    min-width: 0;
    display: flex; flex-direction: column; align-items: center; justify-content: flex-start;
    padding: 6px 4px 4px;
    border-radius: 12px;
    background: var(--apt-cal-day-bg);
    border: 1.5px solid transparent;
    cursor: pointer; position: relative;
    transition: all .2s; user-select: none;
}
.cal-day:hover:not(.disabled):not(.empty) {
    background: rgba(230,57,70,.1); border-color: rgba(230,57,70,.4); transform: scale(1.05);
}
.cal-day.today  { background: var(--apt-cal-today-bg); border-color: var(--apt-red); }
.cal-day.today::after { content: ''; position: absolute; bottom: 5px; width: 5px; height: 5px; background: var(--apt-red); border-radius: 50%; }
.cal-day.selected { background: var(--apt-red) !important; border-color: var(--apt-red) !important; box-shadow: 0 6px 20px rgba(230,57,70,.4); transform: scale(1.06) !important; }
.cal-day.selected .cal-day-num { color: #fff !important; font-weight: 800; }
.cal-day.selected::after { display: none; }
.cal-day.disabled { opacity: .3; cursor: not-allowed; background: transparent; }
.cal-day.empty    { background: transparent; cursor: default; border-color: transparent; }
.cal-day.weekend:not(.disabled):not(.empty) .cal-day-num { color: rgba(230,57,70,.7); }

.cal-day-num { font-size: 1rem; font-weight: 600; color: var(--apt-text); line-height: 1; margin-bottom: 3px; }

.cal-day-apts { width: 100%; display: flex; flex-direction: column; gap: 1px; overflow: hidden; }
.cal-day-chip {
    font-size: .48rem; font-weight: 700; padding: 1px 4px;
    background: var(--apt-dot-bg); color: #fff; border-radius: 3px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-align: center;
    pointer-events: auto; cursor: pointer;
}
.cal-day-chip:hover { background: rgba(230,57,70,.9); }
.cal-day-more { font-size: .48rem; color: var(--apt-muted); text-align: center; cursor: pointer; }
.cal-day-more:hover { color: var(--apt-red); }
.cal-apt-badge {
    position: absolute; top: 3px; right: 3px;
    background: var(--apt-red); color: #fff;
    font-size: .55rem; font-weight: 800; min-width: 16px; height: 16px;
    border-radius: 10px; display: flex; align-items: center; justify-content: center;
    padding: 0 4px; pointer-events: none;
}

.cal-help {
    margin: 1.25rem 1.75rem 1.75rem;
    padding: 12px 16px; border-radius: 10px;
    background: rgba(230,57,70,.07);
    border: 1px dashed rgba(230,57,70,.3);
    font-size: .83rem; color: var(--apt-muted); text-align: center;
    display: flex; align-items: center; justify-content: center; gap: 8px;
}
[data-theme="light"] .cal-help { background: rgba(230,57,70,.04); }

/* ── Modal base ── */
.moverlay {
    position: fixed; inset: 0; z-index: 1000;
    background: var(--apt-overlay-bg);
    backdrop-filter: blur(8px);
    display: flex; align-items: center; justify-content: center;
    padding: 1rem; opacity: 0; visibility: hidden; transition: all .25s;
}
.moverlay.active { opacity: 1; visibility: visible; }

.mbox {
    background: var(--apt-modal-bg);
    border: 1px solid var(--apt-card-border);
    border-radius: 20px; width: 100%; max-width: 600px; max-height: 92vh;
    overflow-y: auto; transform: scale(.93) translateY(16px);
    display: flex; flex-direction: column;
    transition: all .28s cubic-bezier(.34,1.56,.64,1);
    box-shadow: 0 30px 60px rgba(0,0,0,.45);
}
[data-theme="light"] .mbox { box-shadow: 0 20px 50px rgba(15,23,42,.2); }
.moverlay.active .mbox { transform: scale(1) translateY(0); }

.mhead {
    display: flex; justify-content: space-between; align-items: center;
    padding: 18px 22px; border-bottom: 1px solid var(--apt-card-border);
    position: sticky; top: 0; background: var(--apt-modal-bg);
    border-radius: 20px 20px 0 0; z-index: 1;
}
.mhead h2 { font-size: 1.08rem; font-weight: 800; color: var(--apt-text); margin: 0; display: flex; align-items: center; gap: 9px; }
.mhead-icon {
    width: 34px; height: 34px; border-radius: 9px;
    background: rgba(230,57,70,.1); border: 1px solid rgba(230,57,70,.2);
    display: flex; align-items: center; justify-content: center; color: var(--apt-red); flex-shrink: 0;
}
.mclose {
    width: 32px; height: 32px; border-radius: 8px;
    background: var(--apt-input-bg); border: 1px solid var(--apt-card-border);
    color: var(--apt-muted); display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all .18s;
}
.mclose:hover { color: #f87171; border-color: rgba(239,68,68,.3); background: rgba(239,68,68,.08); }
.mbody { padding: 20px 22px; overflow-y: auto; flex: 1; }
form#bkForm { display: flex; flex-direction: column; min-height: 0; flex: 1; overflow: hidden; }
form#bkForm .mbody { overflow-y: auto; -webkit-overflow-scrolling: touch; flex: 1; min-height: 0; }

/* ── Date banner ── */
.date-banner {
    background: linear-gradient(135deg, #e63946, #c1121f);
    border-radius: 13px; padding: 14px 18px; margin-bottom: 20px;
    display: flex; align-items: center; gap: 14px;
}
.date-banner-icon { width: 48px; height: 48px; background: rgba(255,255,255,.2); border-radius: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.date-banner-text h4 { color: #fff; font-size: 1rem; font-weight: 800; margin: 0 0 3px; }
.date-banner-text p  { color: rgba(255,255,255,.85); font-size: .82rem; margin: 0; }

/* ── Form sections ── */
.fsec {
    background: var(--apt-item-bg);
    border: 1px solid var(--apt-card-border);
    border-radius: 13px; padding: 14px 16px; margin-bottom: 14px;
}
.fsec-title {
    display: flex; align-items: center; gap: 8px;
    font-size: .82rem; font-weight: 800; text-transform: uppercase;
    letter-spacing: .06em; color: var(--apt-muted); margin-bottom: 12px;
}
.fsec-title svg { color: var(--apt-red); flex-shrink: 0; }

.frow { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.frow.full { grid-template-columns: 1fr; }
.fgrp { margin-bottom: 11px; }
.fgrp:last-child { margin-bottom: 0; }
.fgrp label {
    display: block; font-size: .72rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
    color: var(--apt-muted); margin-bottom: 6px;
}

.finput, .fselect {
    width: 100%; padding: 10px 13px;
    background: var(--apt-input-bg);
    border: 1.5px solid var(--apt-card-border);
    border-radius: 9px; color: var(--apt-text);
    font-family: 'Plus Jakarta Sans', sans-serif; font-size: .9rem;
    transition: border-color .18s, background .18s;
}
.finput:focus, .fselect:focus { outline: none; border-color: rgba(230,57,70,.6); background: var(--apt-input-hover); }
.finput::placeholder { color: var(--apt-muted); opacity: .6; }
.finput:hover, .fselect:hover { border-color: rgba(230,57,70,.35); }
.finput[readonly] { cursor: not-allowed; opacity: .75; }
.fselect {
    cursor: pointer; appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 13px center;
    padding-right: 38px;
}
.fselect option { background: var(--apt-modal-bg); color: var(--apt-text); }

/* ── Time slot grid ── */
.time-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 7px; }
.tslot {
    padding: 10px 6px; text-align: center;
    background: var(--apt-tslot-bg);
    border: 1.5px solid var(--apt-card-border);
    border-radius: 9px; cursor: pointer;
    font-size: .8rem; font-weight: 600; color: var(--apt-text);
    transition: all .18s;
}
.tslot:hover:not(.ts-taken) { border-color: rgba(230,57,70,.5); background: rgba(230,57,70,.1); }
.tslot.ts-selected { background: var(--apt-red); border-color: var(--apt-red); color: #fff; }
.tslot.ts-taken { opacity: .4; cursor: pointer; }
.tslot.ts-taken:hover { opacity: .65; border-color: rgba(230,57,70,.4); }

/* ── Service options ── */
.svc-options { display: flex; flex-direction: column; gap: 8px; }
.svc-opt {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 14px; background: var(--apt-item-bg);
    border: 1.5px solid var(--apt-card-border); border-radius: 11px;
    cursor: pointer; transition: all .18s;
}
.svc-opt:hover   { border-color: rgba(230,57,70,.4); background: var(--apt-item-hover); }
.svc-opt.svc-sel { border-color: var(--apt-red); background: var(--apt-item-hover); }
.svc-opt input   { display: none; }
.svc-opt-info h5 { font-size: .9rem; font-weight: 700; color: var(--apt-text); margin: 0 0 3px; }
.svc-opt-info p  { font-size: .75rem; color: var(--apt-muted); margin: 0; }
.svc-opt-price   { font-family: 'IBM Plex Mono', monospace; font-size: 1rem; font-weight: 700; color: var(--apt-red); white-space: nowrap; }

/* ── Add-on list ── */
.addon-list { display: flex; flex-direction: column; gap: 8px; }
.addon-opt {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 14px; background: var(--apt-item-bg);
    border: 1.5px solid var(--apt-card-border); border-radius: 11px;
    cursor: pointer; transition: all .18s;
}
.addon-opt:hover { border-color: rgba(230,57,70,.35); background: var(--apt-item-hover); }
.addon-opt:has(input:checked) { border-color: var(--apt-red); background: var(--apt-item-hover); }
.addon-opt input[type=checkbox] { width: 18px; height: 18px; accent-color: var(--apt-red); cursor: pointer; flex-shrink: 0; }
.addon-opt-info { flex: 1; }
.addon-opt-name  { font-size: .88rem; font-weight: 700; color: var(--apt-text); }
.addon-opt-desc  { font-size: .75rem; color: var(--apt-muted); margin-top: 2px; }
.addon-opt-price { font-family: 'IBM Plex Mono', monospace; font-size: .88rem; font-weight: 700; color: var(--apt-red); white-space: nowrap; }

/* ── Price card ── */
.price-card {
    background: var(--apt-price-bg);
    border: 1px solid var(--apt-price-border);
    border-radius: 12px; overflow: hidden; margin-bottom: 14px;
}
.price-card-header { padding: 10px 14px; background: rgba(34,197,94,.08); border-bottom: 1px solid rgba(34,197,94,.15); }
.price-card-header span { font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .07em; color: rgba(74,222,128,.9); }
[data-theme="light"] .price-card-header span { color: #059669; }
.price-lines { padding: 10px 14px; display: flex; flex-direction: column; gap: 6px; }
.price-line { display: flex; justify-content: space-between; font-size: .85rem; }
.price-line .pl { color: var(--apt-muted); }
.price-line .pa { font-family: 'IBM Plex Mono', monospace; color: var(--apt-text); font-weight: 600; }
.price-total { display: flex; justify-content: space-between; align-items: center; padding: 11px 14px; border-top: 1px solid rgba(34,197,94,.15); }
.price-total .ptl { font-size: .88rem; font-weight: 700; color: var(--apt-text); }
.price-total .pta { font-family: 'IBM Plex Mono', monospace; font-size: 1.2rem; font-weight: 700; color: #4ade80; }
[data-theme="light"] .price-total .pta { color: #059669; }

/* ── Notes ── */
.fnotes {
    width: 100%; min-height: 76px; resize: vertical;
    padding: 10px 13px; background: var(--apt-input-bg);
    border: 1.5px solid var(--apt-card-border); border-radius: 9px;
    color: var(--apt-text); font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: .9rem; transition: border-color .18s;
}
.fnotes:focus { outline: none; border-color: rgba(230,57,70,.6); }
.fnotes::placeholder { color: var(--apt-muted); opacity: .5; }

/* ── Modal footer ── */
.mfoot {
    padding: 16px 22px; border-top: 1px solid var(--apt-card-border);
    position: sticky; bottom: 0; background: var(--apt-modal-bg);
    border-radius: 0 0 20px 20px;
}
.btn-book {
    width: 100%; padding: 14px; border: none; border-radius: 11px;
    background: var(--apt-red); color: #fff;
    font-family: 'Plus Jakarta Sans', sans-serif; font-size: .95rem; font-weight: 800;
    cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
    box-shadow: 0 4px 18px rgba(230,57,70,.35); transition: all .2s;
}
.btn-book:hover:not(:disabled) { background: #c32030; transform: translateY(-1px); box-shadow: 0 6px 24px rgba(230,57,70,.48); }
.btn-book:disabled { opacity: .45; cursor: not-allowed; transform: none !important; box-shadow: none; }

/* ── QR modal ── */
.qr-overlay {
    position: fixed; inset: 0; z-index: 2000;
    background: rgba(0,0,0,.82);
    backdrop-filter: blur(10px);
    display: flex; align-items: center; justify-content: center;
    padding: 1rem; opacity: 0; visibility: hidden; transition: all .3s;
}
[data-theme="light"] .qr-overlay { background: rgba(15,23,42,.72); }
.qr-overlay.active { opacity: 1; visibility: visible; }

.qr-box {
    background: var(--apt-modal-bg);
    border: 2px solid var(--apt-red);
    border-radius: 22px; width: 100%; max-width: 400px; max-height: 92vh;
    overflow-y: auto;
    transform: scale(.9) translateY(24px);
    transition: all .38s cubic-bezier(.175,.885,.32,1.275);
    box-shadow: 0 30px 80px rgba(0,0,0,.55), 0 0 60px rgba(230,57,70,.12);
}
[data-theme="light"] .qr-box { box-shadow: 0 20px 60px rgba(15,23,42,.22), 0 0 40px rgba(230,57,70,.08); }
.qr-overlay.active .qr-box { transform: scale(1) translateY(0); }

.qr-head {
    background: linear-gradient(135deg, #e63946, #c1121f);
    padding: 18px 22px; text-align: center; border-radius: 20px 20px 0 0;
}
.qr-head h2 { color: #fff; font-size: 1.15rem; font-weight: 800; margin: 0 0 4px; display: flex; align-items: center; justify-content: center; gap: 8px; }
.qr-head p  { color: rgba(255,255,255,.85); font-size: .82rem; margin: 0; }

.qr-body { padding: 20px 22px; text-align: center; }

.qr-success-ring {
    width: 58px; height: 58px; border-radius: 50%;
    background: linear-gradient(135deg, #e63946, #c1121f);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 16px;
    animation: pulse-ring 2s infinite;
}
@keyframes pulse-ring {
    0%,100% { box-shadow: 0 0 0 0 rgba(230,57,70,.4); }
    50%      { box-shadow: 0 0 0 14px rgba(230,57,70,0); }
}

.qr-ref-box {
    background: rgba(230,57,70,.1); border: 1px solid rgba(230,57,70,.3);
    border-radius: 11px; padding: 10px 14px; margin-bottom: 14px;
}
.qr-ref-box small { display: block; font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--apt-muted); margin-bottom: 4px; }
.qr-ref-num { font-family: 'IBM Plex Mono', monospace; font-size: 1.3rem; font-weight: 700; color: var(--apt-red); }

.qr-code-wrap { background: #fff; padding: 12px; border-radius: 13px; display: inline-block; margin-bottom: 14px; box-shadow: 0 8px 24px rgba(0,0,0,.25); }
#qrcode canvas { border-radius: 6px; max-width: 160px !important; max-height: 160px !important; }

.qr-dets {
    text-align: left;
    background: var(--apt-item-bg); border: 1px solid var(--apt-card-border);
    border-radius: 11px; overflow: hidden; margin-bottom: 4px;
}
.qr-det-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 13px; border-bottom: 1px solid rgba(255,255,255,.05); }
[data-theme="light"] .qr-det-row { border-bottom-color: #f1f5f9; }
.qr-det-row:last-child { border-bottom: none; }
.qr-det-row .ql { color: var(--apt-muted); font-size: .78rem; }
.qr-det-row .qv { color: var(--apt-text); font-size: .82rem; font-weight: 700; text-align: right; max-width: 60%; }
.qr-det-row .qv.green { font-family: 'IBM Plex Mono', monospace; color: #4ade80; }
[data-theme="light"] .qr-det-row .qv.green { color: #059669; }
.qr-det-row .qv.yellow { color: #fbbf24; text-transform: uppercase; }
[data-theme="light"] .qr-det-row .qv.yellow { color: #d97706; }

.qr-foot { padding: 14px 22px; border-top: 1px solid var(--apt-card-border); display: flex; gap: 10px; }
.qr-btn { flex: 1; padding: 11px 14px; border-radius: 10px; font-family: 'Plus Jakarta Sans', sans-serif; font-size: .85rem; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 7px; transition: all .2s; }
.qr-btn-primary   { background: linear-gradient(135deg,#e63946,#c1121f); border: none; color: #fff; }
.qr-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 22px rgba(230,57,70,.42); }
.qr-btn-secondary { background: var(--apt-input-bg); border: 1px solid var(--apt-card-border); color: var(--apt-text); }
.qr-btn-secondary:hover { border-color: var(--apt-red); background: rgba(230,57,70,.08); }

/* ── View-date modal list ── */
.vd-item {
    padding: 11px 13px; background: var(--apt-item-bg);
    border: 1px solid var(--apt-card-border); border-radius: 10px;
    display: flex; justify-content: space-between; align-items: center;
}
.vd-name { font-weight: 700; color: var(--apt-text); font-size: .88rem; }
.vd-time { font-size: .78rem; color: var(--apt-muted); margin-top: 2px; }
.vd-pill { padding: 3px 10px; border-radius: 100px; font-size: .72rem; font-weight: 700; }

/* ── Time-taken modal ── */
.tt-banner {
    background: rgba(230,57,70,.1); border: 1px solid rgba(230,57,70,.25);
    border-radius: 11px; padding: 14px; margin-bottom: 14px; text-align: center;
}
.tt-banner svg { color: #f87171; margin-bottom: 8px; }
.tt-banner p { color: var(--apt-text); font-size: .9rem; font-weight: 600; margin: 0; }
.tt-who { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--apt-item-bg); border: 1px solid var(--apt-card-border); border-radius: 9px; }
.tt-avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--apt-red); display: flex; align-items: center; justify-content: center; font-weight: 800; color: #fff; font-size: .9rem; flex-shrink: 0; }
.tt-person-name { font-weight: 700; color: var(--apt-text); font-size: .88rem; }
.tt-person-stat { font-size: .73rem; margin-top: 2px; }

#vdModal .mbox { max-width: 480px; }
#ttModal .mbox { max-width: 440px; }

/* ── Responsive ── */
@media (max-width: 768px) {
    .main-content { margin-left: 0 !important; padding: calc(var(--topbar-height,64px) + 12px) 16px 20px; }
    .cal-inner { padding: 1rem; }
    .cal-nav-row { flex-wrap: wrap; gap: 0.75rem; }
    .cal-weekdays, .cal-days { gap: 4px; }
    .cal-day { min-height: 0; padding: 5px 3px; }
    .mhead, .mfoot { padding: 14px 16px; }
    .mbody { padding: 16px; }
    .mbox { max-width: 100%; }
}

@media (hover: none) and (pointer: coarse) {
    .cal-grid-scroll {
        overflow-x: auto;
        overflow-y: hidden;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior-x: contain;
        scrollbar-width: none;
        padding-bottom: 3px;
    }

    .cal-grid-scroll::-webkit-scrollbar {
        display: none;
    }

    .cal-grid-scroll .cal-weekdays,
    .cal-grid-scroll .cal-days {
        min-width: 294px;
    }
}

@media (max-width: 600px) {
    .frow { grid-template-columns: 1fr; }
    .time-grid { grid-template-columns: repeat(3,1fr); }
    .qr-foot { flex-direction: column; }

    .cal-grid-scroll {
        overflow-x: auto;
        overflow-y: hidden;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior-x: contain;
        scrollbar-width: thin;
        padding-bottom: 3px;
    }

    .cal-grid-scroll::-webkit-scrollbar {
        height: 6px;
    }

    .cal-grid-scroll::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.25);
        border-radius: 999px;
    }

    .cal-grid-scroll .cal-weekdays,
    .cal-grid-scroll .cal-days {
        min-width: 294px;
    }
}

@media (max-width: 480px) {
    .mbody { padding: 14px 12px; }
    .mhead, .mfoot { padding: 14px 12px; }

    .time-grid { grid-template-columns: repeat(3, 1fr); gap: 5px; }

    .svc-opt { padding: 10px 10px; }
    .svc-opt-price { font-size: .9rem; }

    .qr-foot { flex-direction: column; }

    .date-banner { padding: 12px 12px; gap: 10px; }
    .date-banner-icon { width: 38px; height: 38px; }

    .cal-month-label { font-size: 1.15rem; }

    .price-total .pta { font-size: 1rem; }

    .addon-opt { padding: 9px 10px; }
}

@media (max-width: 420px) {
    .time-grid { grid-template-columns: repeat(3,1fr); }
    .cal-day-chip { display: none; }
    .cal-day-more { font-size: .56rem; }
    .cal-month-label { font-size: 1.15rem; }
}
</style>
</head>
<body class="allow-page-x-scroll">
<div class="dashboard-layout">
    <?php include '../includes/sidebar.php'; ?>
    <?php include '../includes/header.php'; ?>
    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <main class="main-content">
        <div class="bk-header">
            <h1>
                <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="var(--primary-red)" stroke-width="2">
                    <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                Book Appointment
            </h1>
            <p>Pick a date on the calendar to schedule your helmet cleaning service</p>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>" style="margin-bottom:1.25rem;">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>

        <div class="cal-card">
            <div class="cal-inner">
                <div class="cal-nav-row">
                    <span class="cal-month-label" id="calMonth">—</span>
                    <div class="cal-nav">
                        <button class="cal-nav-btn" onclick="changeMonth(-1)" title="Previous">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                        </button>
                        <button class="cal-nav-btn" onclick="changeMonth(1)" title="Next">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>
                    </div>
                </div>
                <div class="cal-grid-scroll drag-scroll">
                    <div class="cal-weekdays">
                        <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
                    </div>
                    <div class="cal-days" id="calDays"></div>
                </div>
            </div>
            <div class="cal-help">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Click any available date to open the booking form
            </div>
        </div>
    </main>
</div>

<!-- ═══ View-date Modal ═══════════════════════════════════════ -->
<div class="moverlay" id="vdModal">
    <div class="mbox" style="max-width:480px;">
        <div class="mhead">
            <h2>
                <div class="mhead-icon">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                Appointments — <span id="vdDateLabel"></span>
            </h2>
            <button class="mclose" onclick="closeVD()"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="mbody">
            <div id="vdList" style="display:flex;flex-direction:column;gap:9px;max-height:360px;overflow-y:auto;"></div>
        </div>
        <div class="mfoot">
            <button class="btn-book" style="background:var(--apt-input-bg);box-shadow:none;color:var(--apt-text);border:1px solid var(--apt-card-border);" onclick="closeVD()">Close</button>
        </div>
    </div>
</div>

<!-- ═══ Time-taken Modal ══════════════════════════════════════ -->
<div class="moverlay" id="ttModal" style="z-index:1100;">
    <div class="mbox" style="max-width:440px;">
        <div class="mhead">
            <h2>
                <div class="mhead-icon" style="background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.2);color:#f87171;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
                Time Slot Taken
            </h2>
            <button class="mclose" onclick="closeTT()"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="mbody">
            <div class="tt-banner">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <p>This time slot is already booked</p>
            </div>
            <p style="font-size:.78rem;color:var(--apt-muted);margin:0 0 10px;">
                Selected: <strong id="ttTime" style="color:var(--apt-red);"></strong>
            </p>
            <div id="ttWhoList" style="display:flex;flex-direction:column;gap:8px;"></div>
            <p style="font-size:.8rem;color:var(--apt-muted);text-align:center;margin:14px 0 0;">Please choose a different time slot.</p>
        </div>
        <div class="mfoot">
            <button class="btn-book" style="background:var(--apt-input-bg);box-shadow:none;color:var(--apt-text);border:1px solid var(--apt-card-border);" onclick="closeTT()">Choose Another Time</button>
        </div>
    </div>
</div>

<!-- ═══ Booking Modal ═════════════════════════════════════════ -->
<div class="moverlay" id="bookingModal">
    <div class="mbox">
        <div class="mhead">
            <h2>
                <div class="mhead-icon">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
                Book Appointment
            </h2>
            <button class="mclose" onclick="closeBk()"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>

        <form method="POST" id="bkForm">
            <input type="hidden" name="appointment_date" id="hdDate">
            <input type="hidden" name="appointment_time" id="hdTime">
            <input type="hidden" name="service_id"       id="hdSvcId">

            <div class="mbody">
                <div class="date-banner">
                    <div class="date-banner-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <div class="date-banner-text">
                        <h4 id="bkDateLabel">—</h4>
                        <p>Fill in the details below to confirm your booking</p>
                    </div>
                </div>

                <div class="fsec">
                    <div class="fsec-title">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Customer Info
                    </div>
                    <div class="frow">
                        <div class="fgrp">
                            <label>Full Name *</label>
                            <input type="text" class="finput" name="full_name" id="bkName"
                                   value="<?php echo htmlspecialchars($userName); ?>" readonly required>
                        </div>
                        <div class="fgrp">
                            <label>Contact Number *</label>
                            <input type="tel" class="finput" name="contact" id="bkContact" placeholder="09XXXXXXXXX" required>
                        </div>
                    </div>
                </div>

                <div class="fsec">
                    <div class="fsec-title">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Select Time *
                    </div>
                    <div class="time-grid" id="timeGrid"></div>
                </div>

                <div class="fsec">
                    <div class="fsec-title">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a9 9 0 0 1 9 9v1H3v-1a9 9 0 0 1 9-9z"/><path d="M3 12v4a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-4"/></svg>
                        Helmet Info
                    </div>
                    <div class="frow">
                        <div class="fgrp">
                            <label>Helmet Type *</label>
                            <select class="fselect" name="helmet_type" id="bkHelmet" required>
                                <option value="">Select type…</option>
                                <option value="Full Face">Full Face</option>
                                <option value="Half Face">Half Face</option>
                                <option value="Open Face">Open Face</option>
                                <option value="Modular">Modular / Flip-up</option>
                                <option value="Off-Road">Off-Road / Motocross</option>
                                <option value="Dual Sport">Dual Sport</option>
                            </select>
                        </div>
                        <div class="fgrp">
                            <label>Quantity *</label>
                            <input type="number" class="finput" name="quantity" id="bkQty" min="1" max="10" value="1" required>
                        </div>
                    </div>
                </div>

                <div class="fsec">
                    <div class="fsec-title">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                        Service *
                    </div>
                    <div class="svc-options" id="svcOptions">
                        <?php foreach ($services as $svc): ?>
                        <label class="svc-opt" data-price="<?php echo $svc['price']; ?>" data-duration="<?php echo $svc['duration']; ?>">
                            <input type="radio" name="service_id_radio" value="<?php echo $svc['id']; ?>" onchange="pickService(this)">
                            <div class="svc-opt-info">
                                <h5><?php echo htmlspecialchars($svc['label'] . ': ' . $svc['name']); ?></h5>
                                <p><?php echo (int)$svc['duration']; ?> min<?php if (!empty($svc['description'])): ?> · <?php echo htmlspecialchars($svc['description']); ?><?php endif; ?></p>
                            </div>
                            <div class="svc-opt-price">₱<?php echo number_format($svc['price'], 0); ?></div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (!empty($addons)): ?>
                <div class="fsec">
                    <div class="fsec-title">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                        Add-ons <span style="text-transform:none;font-weight:500;color:var(--apt-muted);letter-spacing:0;">(optional)</span>
                    </div>
                    <div class="addon-list">
                        <?php foreach ($addons as $addon): ?>
                        <label class="addon-opt" data-addon-price="<?php echo $addon['price']; ?>">
                            <input type="checkbox" name="addons[]" value="<?php echo $addon['id']; ?>" onchange="recalc()">
                            <div class="addon-opt-info">
                                <div class="addon-opt-name"><?php echo htmlspecialchars($addon['name']); ?></div>
                                <?php if (!empty($addon['description'])): ?>
                                <div class="addon-opt-desc"><?php echo htmlspecialchars($addon['description']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="addon-opt-price">+₱<?php echo number_format($addon['price'], 0); ?></div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="price-card" id="priceCard" style="display:none;">
                    <div class="price-card-header"><span>Price Breakdown</span></div>
                    <div class="price-lines" id="priceLines"></div>
                    <div class="price-total">
                        <span class="ptl">Total</span>
                        <span class="pta" id="priceTotal">₱0</span>
                    </div>
                </div>

                <div class="fsec">
                    <div class="fsec-title">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        Notes <span style="text-transform:none;font-weight:500;color:var(--apt-muted);letter-spacing:0;">(optional)</span>
                    </div>
                    <textarea name="notes" class="fnotes" placeholder="Special requests or details…"></textarea>
                </div>
            </div>

            <div class="mfoot">
                <button type="submit" name="book_appointment" class="btn-book" id="bkSubmit" disabled>
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>
                    Confirm Booking
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ QR Success Modal ══════════════════════════════════════ -->
<div class="qr-overlay" id="qrModal">
    <div class="qr-box">
        <div class="qr-head">
            <h2>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Booking Confirmed!
            </h2>
            <p>Your appointment has been successfully booked</p>
        </div>
        <div class="qr-body">
            <div class="qr-success-ring">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div class="qr-ref-box">
                <small>Reference Number</small>
                <div class="qr-ref-num" id="qrRefNum">APT-000000</div>
            </div>
            <div class="qr-code-wrap">
                <div id="qrcode"></div>
            </div>
            <div class="qr-dets" id="qrDets"></div>
        </div>
        <div class="qr-foot">
            <button class="qr-btn qr-btn-secondary" onclick="dlQR()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Download QR
            </button>
            <button class="qr-btn qr-btn-primary" onclick="closeQR()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Done
            </button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
/* ══════════════════════════════════════════════════════
   CALENDAR
══════════════════════════════════════════════════════ */
let curDate = new Date();
let selDate = null;
const MN = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const DN = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
const calAppts = <?php echo json_encode($calendarAppointments); ?>;

function renderCal() {
    const y = curDate.getFullYear(), m = curDate.getMonth();
    document.getElementById('calMonth').textContent = MN[m] + ' ' + y;
    const firstDow = new Date(y, m, 1).getDay();
    const totalDays = new Date(y, m + 1, 0).getDate();
    const today = new Date(); today.setHours(0,0,0,0);
    let html = '';
    for (let i = 0; i < firstDow; i++) html += '<div class="cal-day empty"></div>';
    for (let d = 1; d <= totalDays; d++) {
        const date = new Date(y, m, d);
        const ds   = fmtDate(date);
        const dow  = date.getDay();
        let cls    = 'cal-day';
        if (date.getTime() === today.getTime()) cls += ' today';
        if (selDate === ds)  cls += ' selected';
        if (dow === 0 || dow === 6) cls += ' weekend';
        if (date < today || dow === 1) cls += ' disabled';
        const disabled = cls.includes('disabled');
        const apts = calAppts[ds] || [];
        const cnt  = apts.length;
        let inner  = `<span class="cal-day-num">${d}</span>`;
        if (cnt > 0) inner += `<div class="cal-apt-badge">${cnt}</div>`;
        if (cnt > 0) {
            inner += `<div class="cal-day-apts">`;
            const first = esc(apts[0].name.split(' ')[0]);
            inner += `<span class="cal-day-chip" onclick="event.stopPropagation();openVD('${ds}',${d})" title="View appointments">${first}</span>`;
            if (cnt > 1) inner += `<span class="cal-day-more" onclick="event.stopPropagation();openVD('${ds}',${d})">+${cnt-1} more</span>`;
            inner += `</div>`;
        }
        const attrs = disabled
            ? 'aria-disabled="true"'
            : `onclick="pickDate('${ds}',${d})" role="button" tabindex="0"`;
        const title = `${DN[dow]}, ${MN[m]} ${d}, ${y}` + (dow===1?' — Closed':'');
        html += `<div class="${cls}" ${attrs} title="${title}">${inner}</div>`;
    }
    document.getElementById('calDays').innerHTML = html;
}

function changeMonth(dir) { curDate.setMonth(curDate.getMonth() + dir); renderCal(); }

function pickDate(ds, day) {
    selDate = ds;
    renderCal();
    const date = new Date(ds);
    document.getElementById('bkDateLabel').textContent =
        `${DN[date.getDay()]}, ${MN[date.getMonth()]} ${day}, ${date.getFullYear()}`;
    document.getElementById('hdDate').value = ds;
    buildTimeSlots(ds);
    resetBookingForm();
    document.getElementById('bookingModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('bkContact').focus(), 60);
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('calDays').addEventListener('keydown', e => {
        if ((e.key==='Enter'||e.key===' ') && e.target.classList.contains('cal-day')
            && !e.target.classList.contains('disabled') && !e.target.classList.contains('empty')) {
            const numEl = e.target.querySelector('.cal-day-num');
            const n = numEl ? parseInt(numEl.textContent) : NaN;
            if (!isNaN(n)) {
                const d = new Date(curDate.getFullYear(), curDate.getMonth(), n);
                pickDate(fmtDate(d), n);
                e.preventDefault();
            }
        }
    });
});

/* ══ View-date Modal ══════════════════════════════════ */
function openVD(ds, day) {
    const date = new Date(ds);
    document.getElementById('vdDateLabel').textContent = `${MN[date.getMonth()]} ${day}, ${date.getFullYear()}`;
    const apts = calAppts[ds] || [];
    let html = '';
    if (!apts.length) {
        html = '<p style="text-align:center;color:var(--apt-muted);padding:2rem;">No appointments on this date.</p>';
    } else {
        const SC = {completed:'rgba(34,197,94,.1)',cancelled:'rgba(239,68,68,.1)',pending:'rgba(59,130,246,.1)',in_progress:'rgba(249,115,22,.1)',confirmed:'rgba(34,197,94,.1)'};
        const ST = {completed:'#4ade80',cancelled:'#f87171',pending:'#60a5fa',in_progress:'#fb923c',confirmed:'#4ade80'};
        apts.forEach(a => {
            const c = ST[a.status]||'#94a3b8';
            html += `<div class="vd-item">
                <div><div class="vd-name">${esc(a.name)}</div><div class="vd-time">${fmtTime(a.time)}</div></div>
                <span class="vd-pill" style="background:${SC[a.status]||'rgba(255,255,255,.08)'};color:${c};">${a.status}</span>
            </div>`;
        });
    }
    document.getElementById('vdList').innerHTML = html;
    document.getElementById('vdModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeVD() { document.getElementById('vdModal').classList.remove('active'); document.body.style.overflow=''; }

/* ══ Time-taken Modal ════════════════════════════════ */
function openTT(time, ds) {
    document.getElementById('ttTime').textContent = fmtTime(time);
    const apts = (calAppts[ds]||[]).filter(a=>a.time===time);
    const SC = {completed:'#4ade80',cancelled:'#f87171',pending:'#fbbf24',in_progress:'#fb923c',confirmed:'#4ade80'};
    let html = '';
    apts.forEach(a => {
        html += `<div class="tt-who">
            <div class="tt-avatar">${esc(a.name.charAt(0).toUpperCase())}</div>
            <div>
                <div class="tt-person-name">${esc(a.name)}</div>
                <div class="tt-person-stat" style="color:${SC[a.status]||'#94a3b8'};">${a.status}</div>
            </div>
        </div>`;
    });
    document.getElementById('ttWhoList').innerHTML = html;
    document.getElementById('ttModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeTT() { document.getElementById('ttModal').classList.remove('active'); document.body.style.overflow=''; }

/* ══ Booking Modal ═══════════════════════════════════ */
function buildTimeSlots(ds) {
    const booked = (calAppts[ds]||[]).map(a=>a.time);
    const slots = ['10:00','10:30','11:00','11:30','12:00','12:30',
                   '13:00','13:30','14:00','14:30','15:00','15:30',
                   '16:00','16:30','17:00','17:30','18:00','18:30',
                   '19:00','19:30','20:00'];
    let html = '';
    slots.forEach(t => {
        const full  = t + ':00';
        const taken = booked.includes(full);
        const cls   = taken ? 'tslot ts-taken' : 'tslot';
        const oc    = taken ? `onclick="openTT('${full}','${ds}')"` : `onclick="pickTime('${full}',this)"`;
        html += `<div class="${cls}" ${oc} title="${taken?'Tap to see who booked':'Available'}">${fmtTime(full)}</div>`;
    });
    document.getElementById('timeGrid').innerHTML = html;
}

function pickTime(time, el) {
    document.querySelectorAll('.tslot').forEach(s => s.classList.remove('ts-selected'));
    el.classList.add('ts-selected');
    document.getElementById('hdTime').value = time;
    checkSubmit();
}

let curSvcPrice = 0;

function pickService(radio) {
    const label = radio.closest('.svc-opt');
    document.querySelectorAll('.svc-opt').forEach(l => l.classList.remove('svc-sel'));
    label.classList.add('svc-sel');
    curSvcPrice = parseFloat(label.dataset.price) || 0;
    document.getElementById('hdSvcId').value = radio.value;
    recalc();
    checkSubmit();
}

function recalc() {
    const qty = parseInt(document.getElementById('bkQty').value)||1;
    const svcTotal = curSvcPrice * qty;
    let addonUnit = 0;
    document.querySelectorAll('.addon-opt input:checked').forEach(cb => {
        addonUnit += parseFloat(cb.closest('.addon-opt').dataset.addonPrice)||0;
    });
    const addTotal = addonUnit * qty;
    const grand    = svcTotal + addTotal;
    if (curSvcPrice > 0) {
        let lines = `<div class="price-line"><span class="pl">Service × ${qty}</span><span class="pa">₱${fmt(svcTotal)}</span></div>`;
        if (addTotal > 0) lines += `<div class="price-line"><span class="pl">Add-ons × ${qty}</span><span class="pa">₱${fmt(addTotal)}</span></div>`;
        document.getElementById('priceLines').innerHTML = lines;
        document.getElementById('priceTotal').textContent = '₱' + fmt(grand);
        document.getElementById('priceCard').style.display = 'block';
    } else {
        document.getElementById('priceCard').style.display = 'none';
    }
}

function checkSubmit() {
    const ok = selDate
        && document.getElementById('bkContact').value.trim()
        && document.getElementById('hdTime').value
        && document.getElementById('bkHelmet').value
        && document.getElementById('bkQty').value
        && document.getElementById('hdSvcId').value;
    document.getElementById('bkSubmit').disabled = !ok;
}

function resetBookingForm() {
    document.getElementById('hdTime').value   = '';
    document.getElementById('hdSvcId').value  = '';
    document.getElementById('bkHelmet').value = '';
    document.getElementById('bkQty').value    = 1;
    document.getElementById('bkContact').value = '';
    document.querySelectorAll('.svc-opt').forEach(l  => l.classList.remove('svc-sel'));
    document.querySelectorAll('.svc-opt input').forEach(r => r.checked = false);
    document.querySelectorAll('.addon-opt input').forEach(cb => cb.checked = false);
    document.querySelectorAll('.tslot').forEach(s => s.classList.remove('ts-selected'));
    document.getElementById('priceCard').style.display = 'none';
    const n = document.querySelector('[name=notes]');
    if (n) n.value = '';
    curSvcPrice = 0;
    checkSubmit();
}

function closeBk() {
    document.getElementById('bookingModal').classList.remove('active');
    document.body.style.overflow = '';
}

['bkContact','bkHelmet','bkQty'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', checkSubmit);
});
document.getElementById('bkQty').addEventListener('input', recalc);

/* ══ QR Modal ════════════════════════════════════════ */
<?php if ($showQrModal && $qrAppointmentData): ?>
const QR_DATA = <?php echo json_encode($qrAppointmentData); ?>;
document.addEventListener('DOMContentLoaded', () => setTimeout(() => showQR(QR_DATA), 500));
<?php endif; ?>

function showQR(d) {
    document.getElementById('qrRefNum').textContent = d.reference;
    const rows = [
        ['Customer', d.customer,    ''],
        ['Date',     fmtDateDisp(d.date), ''],
        ['Time',     fmtTime(d.time), ''],
        ['Service',  d.service,     ''],
        ['Helmet',   d.helmet_type, ''],
        ['Qty',      d.quantity + 'x helmet(s)', ''],
        ['Total',    '₱' + parseFloat(d.price).toLocaleString('en-US',{minimumFractionDigits:2}), 'green'],
        ['Status',   d.status,      'yellow'],
    ];
    document.getElementById('qrDets').innerHTML = rows.map(([l,v,cls]) =>
        `<div class="qr-det-row"><span class="ql">${l}</span><span class="qv ${cls}">${esc(v)}</span></div>`
    ).join('');
    const qrJson = JSON.stringify({
        ref: d.reference, id: d.id, name: d.customer, phone: d.contact,
        date: d.date, time: d.time, service: d.service,
        helmet: d.helmet_type, qty: d.quantity, price: d.price,
        svc_price: d.service_price || 0, addon_total: d.addon_total || 0, status: d.status,
    });
    const wrap = document.getElementById('qrcode');
    wrap.innerHTML = '';
    try {
        new QRCode(wrap, { text: qrJson, width: 160, height: 160, colorDark: '#111318', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
    } catch(e) { wrap.innerHTML = '<p style="color:#f87171;">QR error</p>'; }
    document.getElementById('qrModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeQR() {
    document.getElementById('qrModal').classList.remove('active');
    document.body.style.overflow = '';
}

function dlQR() {
    const src = document.querySelector('#qrcode canvas') || document.querySelector('#qrcode img');
    if (!src) { alert('QR not ready'); return; }
    const cv = document.createElement('canvas');
    const cx = cv.getContext('2d');
    const pad = 28, hh = 55, fh = 72, sz = 200;
    cv.width  = sz + pad*2;
    cv.height = sz + pad*2 + hh + fh;
    cx.fillStyle = '#ffffff'; cx.fillRect(0,0,cv.width,cv.height);
    cx.fillStyle = '#111318'; cx.font = 'bold 16px Plus Jakarta Sans, Arial'; cx.textAlign = 'center';
    cx.fillText('Appointment QR Code', cv.width/2, 34);
    cx.drawImage(src, pad, hh, sz, sz);
    const ref = document.getElementById('qrRefNum').textContent;
    cx.fillStyle = '#e63946'; cx.font = 'bold 15px Courier New, monospace';
    cx.fillText(ref, cv.width/2, sz+hh+28);
    cx.fillStyle = '#666'; cx.font = '11px Arial';
    cx.fillText('Scan at the shop for quick check-in', cv.width/2, sz+hh+48);
    const a = document.createElement('a');
    a.download = `appointment-${ref}.png`; a.href = cv.toDataURL('image/png'); a.click();
}

/* ══ Utils ═══════════════════════════════════════════ */
function fmtDate(d) {
    return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
}
function fmtTime(t) {
    if (!t) return '—';
    const [h,m] = t.split(':'), hr = +h;
    return `${hr%12||12}:${m} ${hr>=12?'PM':'AM'}`;
}
function fmtDateDisp(ds) {
    return new Date(ds).toLocaleDateString('en-US',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
}
function fmt(n) { return parseFloat(n||0).toLocaleString('en-US',{minimumFractionDigits:2}); }
function esc(s) { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

['vdModal','ttModal','bookingModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target===this) {
            if (id==='vdModal') closeVD();
            else if (id==='ttModal') closeTT();
            else closeBk();
        }
    });
});
document.getElementById('qrModal').addEventListener('click', function(e) { if(e.target===this) closeQR(); });
document.addEventListener('keydown', e => {
    if (e.key==='Escape') { closeVD(); closeTT(); closeBk(); closeQR(); }
});

document.addEventListener('DOMContentLoaded', renderCal);
</script>
</body>
</html>