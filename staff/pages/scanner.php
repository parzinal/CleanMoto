<?php
require_once __DIR__ . '/../../config/config.php';

if (!isLoggedIn() || getUserRole() !== 'staff') {
    redirect('login.php');
}

$db = Database::getInstance()->getConnection();

/* ══════════════════════════ AJAX ══════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    /* ── lookup: verify appointment exists, return details ──────── */
    if ($action === 'lookup') {
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);
        try {
            $stmt = $db->prepare("
                SELECT a.*,
                       s.name  AS service_name, s.label AS service_label, s.price AS service_price,
                       u.name  AS user_full_name, u.email AS user_email
                FROM   appointments a
                LEFT JOIN services s ON a.service_id = s.id
                LEFT JOIN users    u ON a.user_id    = u.id
                WHERE  a.id = ?
                LIMIT  1
            ");
            $stmt->execute([$appointmentId]);
            $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($appointment) {
                echo json_encode(['success' => true, 'appointment' => $appointment]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Appointment not found']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
        exit;
    }

    /* ── update_status (used if staff confirms from scanner page) ── */
    if ($action === 'update_status') {
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);
        $status        = $_POST['status'] ?? '';
        $validStatuses = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'];

        if (!in_array($status, $validStatuses, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status']);
            exit;
        }
        try {
            $getStmt = $db->prepare("
                SELECT a.*, s.name AS service_name, s.label AS service_label
                FROM   appointments a
                LEFT JOIN services s ON a.service_id = s.id
                WHERE  a.id = ?
            ");
            $getStmt->execute([$appointmentId]);
            $appointment = $getStmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $db->prepare("UPDATE appointments SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $appointmentId]);

            if ($appointment && !empty($appointment['user_id'])) {
                try {
                    $labels = [
                        'pending'     => 'Pending',
                        'confirmed'   => 'Confirmed (Checked-in)',
                        'in_progress' => 'In Progress',
                        'completed'   => 'Completed',
                        'cancelled'   => 'Cancelled',
                    ];
                    $label    = $labels[$status] ?? ucfirst($status);
                    $svcName  = ($appointment['service_label'] ?? '') . ': ' . ($appointment['service_name'] ?? 'Service');
                    $ref      = 'APT-' . str_pad($appointmentId, 6, '0', STR_PAD_LEFT);
                    $insStmt  = $db->prepare("INSERT INTO notifications
                        (user_id, role, type, title, body, url, is_read, created_at)
                        VALUES (?, NULL, ?, ?, ?, ?, 0, NOW())");
                    $insStmt->execute([
                        $appointment['user_id'],
                        'appointment.status_changed',
                        'Appointment Status Updated',
                        "Your appointment ({$ref}) for {$svcName} has been updated to: {$label}",
                        rtrim(APP_URL, '/') . '/user/pages/my-appointments.php'
                    ]);
                } catch (Exception $e) {
                    error_log('Scanner notify failed: ' . $e->getMessage());
                }
            }
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Update failed']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QR Scanner — <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; }
body { font-family: 'Plus Jakarta Sans', sans-serif; }

.scanner-page { padding: 1.5rem; }
.sc-wrap { max-width: 820px; margin: 0 auto; display: flex; flex-direction: column; gap: 1.25rem; }

/* ── Page heading ──────────────────────────────────── */
.sc-heading { display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; flex-wrap: wrap; }
.sc-heading h1 { font-size: 1.65rem; font-weight: 800; color: var(--cream); letter-spacing: -.6px; margin: 0 0 3px; }
.sc-heading p  { font-size: .83rem; color: var(--gray-text); margin: 0; }
.btn-back {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 18px; border-radius: 10px;
    border: 1px solid var(--border-color); background: transparent;
    color: var(--gray-text); font-family: 'Plus Jakarta Sans', sans-serif;
    font-weight: 600; font-size: .875rem; text-decoration: none;
    transition: all .18s; white-space: nowrap;
}
.btn-back:hover { color: var(--cream); border-color: rgba(255,255,255,.25); background: rgba(255,255,255,.04); }

/* ── Main card ─────────────────────────────────────── */
.sc-card {
    background: var(--dark-card);
    border: 1px solid var(--border-color);
    border-radius: 18px;
    overflow: hidden;
}

/* ── Camera panel ──────────────────────────────────── */
.sc-cam-panel {
    position: relative;
    background: #050608;
    aspect-ratio: 16/9;
    max-height: 380px;
    overflow: hidden;
}
#scVideo  { width: 100%; height: 100%; object-fit: cover; display: block; }
#scCanvas { display: none; }

/* Corner frame */
.sc-frame { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; }
.sc-frame-box { width: 200px; height: 200px; position: relative; }
.sc-frame-box::before, .sc-frame-box::after,
.sc-corner-br, .sc-corner-bl {
    content: ''; position: absolute;
    width: 26px; height: 26px;
    border-color: var(--primary-red);
    border-style: solid;
    transition: opacity .3s;
}
.sc-frame-box::before { top:0; left:0;   border-width: 3px 0 0 3px; border-radius: 5px 0 0 0; }
.sc-frame-box::after  { top:0; right:0;  border-width: 3px 3px 0 0; border-radius: 0 5px 0 0; }
.sc-corner-br         { bottom:0; right:0; border-width: 0 3px 3px 0; border-radius: 0 0 5px 0; }
.sc-corner-bl         { bottom:0; left:0;  border-width: 0 0 3px 3px; border-radius: 0 0 0 5px; }

/* Pulse ring on scan */
.sc-frame-box.pulse::before, .sc-frame-box.pulse::after,
.sc-frame-box.pulse ~ .sc-corner-br,
.sc-frame-box.pulse ~ .sc-corner-bl { border-color: #4ade80; }

/* Laser */
.sc-laser {
    position: absolute; left: 50%; top: 50%;
    transform: translate(-50%, calc(-50% - 90px));
    width: 170px; height: 2px;
    background: linear-gradient(90deg, transparent, var(--primary-red), transparent);
    border-radius: 1px;
    opacity: 0; transition: opacity .2s;
}
.sc-laser.on { opacity: 1; animation: laserScan 1.8s ease-in-out infinite; }
@keyframes laserScan {
    0%   { transform: translate(-50%, calc(-50% - 90px)); }
    50%  { transform: translate(-50%, calc(-50% + 90px)); }
    100% { transform: translate(-50%, calc(-50% - 90px)); }
}

/* Status badge */
.sc-badge {
    position: absolute; top: 12px; left: 12px;
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 11px; border-radius: 100px;
    font-size: .7rem; font-weight: 700; letter-spacing: .05em;
    backdrop-filter: blur(8px);
    background: rgba(0,0,0,.5); color: var(--gray-text);
    border: 1px solid rgba(255,255,255,.07);
    transition: all .2s;
}
.sc-badge.scanning { background: rgba(230,57,70,.18); color: #fca5a5; border-color: rgba(230,57,70,.3); }
.sc-badge.ready    { background: rgba(255,255,255,.06); color: var(--gray-text); }
.sc-badge.success  { background: rgba(34,197,94,.18); color: #86efac; border-color: rgba(34,197,94,.3); }
.sc-badge .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.sc-badge.scanning .dot { animation: blink .8s ease-in-out infinite; }
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.2} }

/* Success flash overlay */
.sc-flash {
    position: absolute; inset: 0;
    background: rgba(34,197,94,.15);
    opacity: 0; pointer-events: none;
    transition: opacity .1s;
}
.sc-flash.show { opacity: 1; }

/* ── Controls row ──────────────────────────────────── */
.sc-controls {
    display: grid;
    grid-template-columns: 1fr auto auto;
    gap: 10px; align-items: center;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-color);
}
.sc-select {
    width: 100%; padding: 9px 12px; border-radius: 9px;
    background: rgba(255,255,255,.04); border: 1px solid var(--border-color);
    color: var(--cream); font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: .85rem; outline: none; cursor: pointer;
    transition: border-color .18s;
}
.sc-select:focus { border-color: rgba(230,57,70,.5); }
.sc-select option { background: var(--dark-card); }

.sc-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 18px; border-radius: 9px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: .85rem; font-weight: 700;
    cursor: pointer; white-space: nowrap; border: none;
    transition: all .18s;
}
.sc-btn:disabled { opacity: .35; cursor: not-allowed; transform: none !important; }
.sc-btn-start {
    background: var(--primary-red); color: #fff;
    box-shadow: 0 4px 16px rgba(230,57,70,.35);
}
.sc-btn-start:hover:not(:disabled) { background: #c32030; transform: translateY(-1px); box-shadow: 0 6px 22px rgba(230,57,70,.45); }
.sc-btn-stop {
    background: transparent; color: var(--gray-text);
    border: 1px solid var(--border-color);
}
.sc-btn-stop:hover:not(:disabled) { color: var(--cream); border-color: rgba(255,255,255,.25); }

/* ── Bottom section ────────────────────────────────── */
.sc-bottom { padding: 16px; display: flex; flex-direction: column; gap: 14px; }

/* Result strip */
.sc-result {
    display: none; align-items: flex-start; gap: 12px;
    padding: 12px 14px; border-radius: 11px; border: 1px solid transparent;
}
.sc-result.show    { display: flex; }
.sc-result.success { background: rgba(34,197,94,.07);  border-color: rgba(34,197,94,.25); }
.sc-result.error   { background: rgba(239,68,68,.07);  border-color: rgba(239,68,68,.22); }
.sc-result.info    { background: rgba(59,130,246,.07); border-color: rgba(59,130,246,.2); }

.sc-res-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sc-result.success .sc-res-icon { background: rgba(34,197,94,.12); color: #4ade80; }
.sc-result.error   .sc-res-icon { background: rgba(239,68,68,.12); color: #f87171; }
.sc-result.info    .sc-res-icon { background: rgba(59,130,246,.12); color: #60a5fa; }
.sc-res-body { flex: 1; }
.sc-res-title { font-size: .88rem; font-weight: 700; color: var(--cream); margin: 0 0 2px; }
.sc-res-sub   { font-size: .8rem;  color: var(--gray-text); margin: 0; }

/* Appointment preview card */
.sc-appt {
    display: none;
    background: rgba(255,255,255,.03);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    overflow: hidden;
}
.sc-appt.show { display: block; }
.sc-appt-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 11px 14px;
    border-bottom: 1px solid var(--border-color);
    background: rgba(255,255,255,.02);
}
.sc-appt-header-left { display: flex; align-items: center; gap: 9px; }
.sc-appt-icon { width: 30px; height: 30px; border-radius: 8px; background: rgba(230,57,70,.1); border: 1px solid rgba(230,57,70,.2); display: flex; align-items: center; justify-content: center; color: var(--primary-red); flex-shrink: 0; }
.sc-appt-ref  { font-family: 'IBM Plex Mono', monospace; font-size: .82rem; font-weight: 600; color: #f87171; }
.sc-appt-name { font-size: .78rem; color: var(--gray-text); margin-top: 1px; }

.sc-appt-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
.sc-appt-cell { padding: 10px 14px; border-bottom: 1px solid rgba(255,255,255,.04); }
.sc-appt-cell:nth-child(odd) { border-right: 1px solid rgba(255,255,255,.04); }
.sc-appt-cell label { display: block; font-size: .67rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--gray-text); margin-bottom: 4px; }
.sc-appt-cell span  { font-size: .85rem; font-weight: 600; color: var(--cream); }
.sc-appt-grid .sc-appt-cell:last-child,
.sc-appt-grid .sc-appt-cell:nth-last-child(2):nth-child(odd) { border-bottom: none; }

/* Status pill */
.spill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 100px; font-size: .72rem; font-weight: 700; }
.spill::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
.spill-pending     { background: rgba(251,191,36,.1);  color: #fbbf24; }
.spill-confirmed   { background: rgba(34,197,94,.1);   color: #4ade80; }
.spill-in_progress { background: rgba(249,115,22,.1);  color: #fb923c; }
.spill-completed   { background: rgba(156,163,175,.1); color: #9ca3af; }
.spill-cancelled   { background: rgba(239,68,68,.1);   color: #f87171; }

/* Brightness */
.sc-brightness {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 12px; border-radius: 9px;
    background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.06);
}
.sc-brightness label { font-size: .75rem; font-weight: 600; color: var(--gray-text); white-space: nowrap; display: flex; align-items: center; gap: 5px; }
.sc-brightness input[type=range] { flex: 1; accent-color: var(--primary-red); }
.sc-brightness .bv { font-family: 'IBM Plex Mono', monospace; font-size: .75rem; color: var(--gray-text); min-width: 28px; text-align: right; }

/* Section label */
.sc-lbl { font-size: .68rem; font-weight: 800; text-transform: uppercase; letter-spacing: .1em; color: var(--gray-text); margin: 0 0 9px; display: flex; align-items: center; gap: 7px; }
.sc-lbl::after { content: ''; flex: 1; height: 1px; background: var(--border-color); }

/* Manual lookup */
.sc-manual { display: flex; gap: 8px; }
.sc-manual-input {
    flex: 1; padding: 9px 12px; border-radius: 9px;
    background: rgba(255,255,255,.04); border: 1px solid var(--border-color);
    color: var(--cream); font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: .87rem; outline: none; transition: border-color .18s;
}
.sc-manual-input:focus { border-color: rgba(230,57,70,.5); }
.sc-manual-input::placeholder { color: var(--gray-text); opacity: .6; }
.sc-btn-lookup {
    padding: 9px 18px; border-radius: 9px;
    background: rgba(255,255,255,.05); border: 1px solid var(--border-color);
    color: var(--cream); font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: .85rem; font-weight: 700; cursor: pointer;
    transition: all .18s; white-space: nowrap;
}
.sc-btn-lookup:hover { background: rgba(255,255,255,.1); border-color: rgba(255,255,255,.2); }

/* Tip */
.sc-tip {
    background: rgba(255,255,255,.02);
    border: 1px solid rgba(255,255,255,.05);
    border-radius: 10px; padding: 11px 14px;
    font-size: .8rem; color: var(--gray-text); line-height: 1.55;
}
.sc-tip strong { color: rgba(255,255,255,.45); }

/* Loading overlay */
.sc-overlay {
    display: none; position: fixed; inset: 0; z-index: 9999;
    background: rgba(0,0,0,.65); backdrop-filter: blur(6px);
    align-items: center; justify-content: center; flex-direction: column; gap: 14px;
}
.sc-overlay.show { display: flex; }
.sc-spinner {
    width: 42px; height: 42px; border-radius: 50%;
    border: 3px solid rgba(230,57,70,.2);
    border-top-color: var(--primary-red);
    animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.sc-overlay-txt { font-size: .875rem; font-weight: 700; color: var(--gray-text); }

@media(max-width:600px) {
    .sc-controls { grid-template-columns: 1fr; }
    .sc-appt-grid { grid-template-columns: 1fr; }
    .sc-appt-cell:nth-child(odd) { border-right: none; }
}
</style>
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <main class="main-content scanner-page">
        <div class="sc-wrap">

            <!-- Heading -->
            <div class="sc-heading">
                <div>
                    <h1>QR Scanner</h1>
                    <p>Scan a customer's appointment QR code to verify and check in</p>
                </div>
                <a href="appointments.php" class="btn-back">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <polyline points="15 18 9 12 15 6"/>
                    </svg>
                    Appointments
                </a>
            </div>

            <!-- Scanner card -->
            <div class="sc-card">

                <!-- Camera viewport -->
                <div class="sc-cam-panel">
                    <video id="scVideo" autoplay playsinline muted></video>
                    <canvas id="scCanvas"></canvas>

                    <!-- Scan frame corners -->
                    <div class="sc-frame">
                        <div class="sc-frame-box" id="scFrameBox">
                            <div class="sc-corner-br"></div>
                            <div class="sc-corner-bl"></div>
                        </div>
                    </div>

                    <!-- Laser line -->
                    <div class="sc-laser" id="scLaser"></div>

                    <!-- Success flash -->
                    <div class="sc-flash" id="scFlash"></div>

                    <!-- Status badge -->
                    <div class="sc-badge ready" id="scBadge">
                        <div class="dot"></div>
                        <span id="scBadgeTxt">Camera off</span>
                    </div>
                </div>

                <!-- Controls -->
                <div class="sc-controls">
                    <select id="scCamSel" class="sc-select">
                        <option value="">Select camera…</option>
                    </select>
                    <button id="scStartBtn" class="sc-btn sc-btn-start" onclick="startScanner()">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                        Start
                    </button>
                    <button id="scStopBtn" class="sc-btn sc-btn-stop" onclick="stopScanner()" disabled>
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><rect x="4" y="4" width="16" height="16" rx="2"/></svg>
                        Stop
                    </button>
                </div>

                <!-- Bottom -->
                <div class="sc-bottom">

                    <!-- Result strip -->
                    <div id="scResult" class="sc-result">
                        <div class="sc-res-icon" id="scResIcon">
                            <!-- SVG set by JS -->
                        </div>
                        <div class="sc-res-body">
                            <p class="sc-res-title" id="scResTitle"></p>
                            <p class="sc-res-sub"   id="scResSub"></p>
                        </div>
                    </div>

                    <!-- Appointment preview -->
                    <div id="scAppt" class="sc-appt">
                        <div class="sc-appt-header">
                            <div class="sc-appt-header-left">
                                <div class="sc-appt-icon">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                                    </svg>
                                </div>
                                <div>
                                    <div class="sc-appt-ref"  id="scApptRef">—</div>
                                    <div class="sc-appt-name" id="scApptName">—</div>
                                </div>
                            </div>
                            <span id="scApptStatusPill"></span>
                        </div>
                        <div class="sc-appt-grid" id="scApptGrid"></div>
                    </div>

                    <!-- Brightness -->
                    <div class="sc-brightness">
                        <label for="scBright">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="5"/>
                                <line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
                                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                                <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
                                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                            </svg>
                            Brightness
                        </label>
                        <input type="range" id="scBright" min="-60" max="120" value="0" step="1">
                        <span class="bv" id="scBrightVal">0</span>
                    </div>

                    <!-- Manual lookup -->
                    <div>
                        <p class="sc-lbl">Manual Lookup</p>
                        <div class="sc-manual">
                            <input type="text" id="scManualInput" class="sc-manual-input"
                                   placeholder="APT-000001 or appointment ID…">
                            <button class="sc-btn-lookup" onclick="lookupManual()">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline;vertical-align:middle;margin-right:4px;">
                                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                                </svg>
                                Lookup
                            </button>
                        </div>
                    </div>

                    <!-- Tip -->
                    <div class="sc-tip">
                        <strong>Use your phone as a webcam:</strong> Install DroidCam, Iriun, or EpocCam on your phone and its desktop client. Your phone will appear in the camera dropdown — select it and press <strong>Start</strong>.
                    </div>

                </div>
            </div>
        </div>
    </main>
</div>

<!-- Loading overlay -->
<div class="sc-overlay" id="scOverlay">
    <div class="sc-spinner"></div>
    <div class="sc-overlay-txt">Verifying appointment…</div>
</div>

<!-- jsQR -->
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script>
(function () {
    'use strict';

    /* ── State ──────────────────────────────────────────────── */
    let stream    = null;
    let rafTimer  = null;
    let scanning  = false;
    let lastScan  = 0;
    const COOLDOWN = 2800; // ms before next scan attempt
    const TICK     = 80;   // decode interval ms (~12 fps)

    /* ── DOM ────────────────────────────────────────────────── */
    const video     = document.getElementById('scVideo');
    const canvas    = document.getElementById('scCanvas');
    const ctx       = canvas.getContext('2d', { willReadFrequently: true });
    const camSel    = document.getElementById('scCamSel');
    const startBtn  = document.getElementById('scStartBtn');
    const stopBtn   = document.getElementById('scStopBtn');
    const laser     = document.getElementById('scLaser');
    const badge     = document.getElementById('scBadge');
    const badgeTxt  = document.getElementById('scBadgeTxt');
    const flash     = document.getElementById('scFlash');
    const frameBox  = document.getElementById('scFrameBox');
    const brightSlider = document.getElementById('scBright');
    const brightVal    = document.getElementById('scBrightVal');

    /* ── Camera list ─────────────────────────────────────────── */
    async function populateCameras() {
        try {
            const tmp = await navigator.mediaDevices.getUserMedia({ video: true }).catch(() => null);
            const devs = await navigator.mediaDevices.enumerateDevices();
            if (tmp) tmp.getTracks().forEach(t => t.stop());

            const cams = devs.filter(d => d.kind === 'videoinput');
            camSel.innerHTML = '<option value="">Select camera…</option>';
            cams.forEach((c, i) => {
                const o = document.createElement('option');
                o.value = c.deviceId;
                o.textContent = c.label || 'Camera ' + (i + 1);
                camSel.appendChild(o);
            });

            const rear = cams.find(c => /back|rear|environment/i.test(c.label || ''));
            if (rear) camSel.value = rear.deviceId;
            else if (cams.length) camSel.value = cams[0].deviceId;
        } catch (e) {
            showResult('error', errIcon(), 'Camera permission denied', 'Please allow camera access and refresh.');
        }
    }

    /* ── Stream ──────────────────────────────────────────────── */
    async function startPreview(deviceId) {
        stopStream();
        if (!deviceId) return;
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { deviceId: { exact: deviceId }, width: { ideal: 1280 }, height: { ideal: 720 } }
            });
            video.srcObject = stream;
            setBadge('ready', 'Ready');
        } catch (e) {
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: true });
                video.srcObject = stream;
                setBadge('ready', 'Ready');
            } catch (err) {
                showResult('error', errIcon(), 'Camera unavailable', err.message || 'Check permissions.');
            }
        }
    }

    function stopStream() {
        scanning = false;
        clearTimeout(rafTimer);
        if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
        video.srcObject = null;
        setBadge('ready', 'Camera off');
    }

    /* ── Decode loop ─────────────────────────────────────────── */
    function decodeTick() {
        if (!scanning) return;
        if (video.readyState === video.HAVE_ENOUGH_DATA) {
            canvas.width  = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0);
            const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const code = jsQR(imgData.data, imgData.width, imgData.height, {
                inversionAttempts: 'dontInvert'
            });
            if (code && code.data && Date.now() - lastScan > COOLDOWN) {
                lastScan = Date.now();
                playBeep();
                processQR(code.data);
                return; // suspend loop — processQR will resume or redirect
            }
        }
        rafTimer = setTimeout(decodeTick, TICK);
    }

    /* ── Start / Stop ────────────────────────────────────────── */
    window.startScanner = async function () {
        if (!window.jsQR) {
            showResult('error', errIcon(), 'jsQR not loaded', 'Check your internet and refresh.');
            return;
        }
        const devId = camSel.value;
        if (!stream) await startPreview(devId);
        if (!stream) return;

        scanning = true;
        startBtn.disabled = true;
        stopBtn.disabled  = false;
        laser.classList.add('on');
        setBadge('scanning', 'Scanning…');
        showResult('info', infoIcon(), 'Scanner active', 'Point camera at the appointment QR code.');
        decodeTick();
    };

    window.stopScanner = function () {
        scanning = false;
        clearTimeout(rafTimer);
        startBtn.disabled = false;
        stopBtn.disabled  = true;
        laser.classList.remove('on');
        setBadge('ready', 'Ready');
    };

    /* ── QR Processing ───────────────────────────────────────── */
    /*
     * The QR from book-appointment.php encodes a JSON string:
     *   { ref, id, name, phone, date, time, service, helmet, qty, price, status }
     *
     * We extract the numeric ID from it, then redirect to appointments.php
     * with BOTH the raw QR string (as ?scanned=) AND the id, so appointments.php
     * can do a fresh DB fetch for the full confirm popup.
     *
     * We pass the raw QR string URL-encoded so appointments.php PHP can decode it
     * and extract the id even from the JSON form.
     */
    function processQR(rawText) {
        stopScanner();

        let appointmentId = null;
        let rawForUrl     = rawText; // what we send in ?scanned=

        // 1. Try JSON parse (booking page format)
        try {
            const parsed = JSON.parse(rawText);
            if (parsed && parsed.id) {
                appointmentId = parseInt(parsed.id, 10);
                rawForUrl     = rawText; // full JSON
            }
        } catch (_) {}

        // 2. Try APT-XXXXXX reference format
        if (!appointmentId) {
            const m = rawText.match(/APT-0*(\d+)/i);
            if (m) { appointmentId = parseInt(m[1], 10); rawForUrl = rawText; }
        }

        // 3. Plain numeric ID
        if (!appointmentId && /^\d+$/.test(rawText.trim())) {
            appointmentId = parseInt(rawText.trim(), 10);
            rawForUrl     = rawText.trim();
        }

        if (!appointmentId) {
            showResult('error', errIcon(), 'Unrecognised QR', 'Format not supported: ' + rawText.slice(0, 50));
            return;
        }

        // Quick lookup to confirm it exists before redirecting
        showOverlay(true);
        const fd = new FormData();
        fd.append('action', 'lookup');
        fd.append('appointment_id', appointmentId);
        fetch('scanner.php', { method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
            .then(r => r.json())
            .then(d => {
                showOverlay(false);
                if (d.success && d.appointment) {
                    // Flash green + show mini preview
                    flashSuccess();
                    renderApptPreview(d.appointment);
                    showResult('success', okIcon(), 'QR Verified!', 'Redirecting to appointment details…');
                    setBadge('success', 'Verified');
                    // Redirect — pass raw QR string so appointments.php can parse full JSON
                    setTimeout(() => {
                        location.href = 'appointments.php?scanned=' + encodeURIComponent(rawForUrl);
                    }, 1100);
                } else {
                    showResult('error', errIcon(), 'Not Found', d.message || 'No matching appointment.');
                }
            })
            .catch(() => {
                showOverlay(false);
                showResult('error', errIcon(), 'Network error', 'Could not reach the server.');
            });
    }

    /* ── Manual lookup ───────────────────────────────────────── */
    window.lookupManual = function () {
        const v = document.getElementById('scManualInput').value.trim();
        if (!v) { showResult('error', errIcon(), 'Enter a reference', 'e.g. APT-000001'); return; }
        const m = v.match(/APT-0*(\d+)/i);
        const numId = m ? parseInt(m[1], 10) : (/^\d+$/.test(v) ? parseInt(v, 10) : null);
        if (!numId) { showResult('error', errIcon(), 'Invalid format', 'Use APT-XXXXXX or a numeric ID.'); return; }

        showOverlay(true);
        const fd = new FormData();
        fd.append('action', 'lookup');
        fd.append('appointment_id', numId);
        fetch('scanner.php', { method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
            .then(r => r.json())
            .then(d => {
                showOverlay(false);
                if (d.success && d.appointment) {
                    renderApptPreview(d.appointment);
                    showResult('success', okIcon(), 'Appointment Found', 'Redirecting…');
                    setTimeout(() => {
                        location.href = 'appointments.php?scanned=' + numId;
                    }, 900);
                } else {
                    showResult('error', errIcon(), 'Not Found', d.message || 'No matching appointment.');
                }
            })
            .catch(() => { showOverlay(false); showResult('error', errIcon(), 'Network error', 'Check connection.'); });
    };

    /* ── Appointment preview (mini card) ─────────────────────── */
    function renderApptPreview(a) {
        const ref    = 'APT-' + String(a.id).padStart(6, '0');
        const svc    = ((a.service_label ? a.service_label + ': ' : '') + (a.service_name || '—'));
        const price  = a.service_price ? '₱' + parseFloat(a.service_price).toLocaleString('en-US',{minimumFractionDigits:2}) : '—';
        const dt     = fDate(a.appointment_date) + ' · ' + fTime(a.appointment_time);
        const status = a.status || 'pending';

        document.getElementById('scApptRef').textContent  = ref;
        document.getElementById('scApptName').textContent = a.full_name || a.user_full_name || '—';
        document.getElementById('scApptStatusPill').innerHTML = `<span class="spill spill-${status}">${fStatus(status)}</span>`;

        document.getElementById('scApptGrid').innerHTML = `
            <div class="sc-appt-cell"><label>Customer</label><span>${esc(a.full_name || a.user_full_name || '—')}</span></div>
            <div class="sc-appt-cell"><label>Contact</label><span>${esc(a.contact || '—')}</span></div>
            <div class="sc-appt-cell"><label>Service</label><span>${esc(svc)}</span></div>
            <div class="sc-appt-cell"><label>Price</label><span style="color:#4ade80;font-family:'IBM Plex Mono',monospace;">${price}</span></div>
            <div class="sc-appt-cell"><label>Date &amp; Time</label><span>${dt}</span></div>
            <div class="sc-appt-cell"><label>Helmet</label><span>${esc(a.helmet_type || '—')} × ${a.quantity || 1}</span></div>
        `;

        document.getElementById('scAppt').classList.add('show');
    }

    /* ── Visual helpers ──────────────────────────────────────── */
    function flashSuccess() {
        flash.classList.add('show');
        setTimeout(() => flash.classList.remove('show'), 400);
    }

    function showResult(type, iconHtml, title, sub) {
        const el = document.getElementById('scResult');
        el.className = 'sc-result show ' + type;
        document.getElementById('scResIcon').innerHTML  = iconHtml;
        document.getElementById('scResTitle').textContent = title;
        document.getElementById('scResSub').textContent   = sub;
    }

    function setBadge(state, txt) {
        badge.className = 'sc-badge ' + state;
        badgeTxt.textContent = txt;
    }

    function showOverlay(v) {
        document.getElementById('scOverlay').classList.toggle('show', v);
    }

    /* ── SVG icon helpers ────────────────────────────────────── */
    function okIcon() {
        return `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>`;
    }
    function errIcon() {
        return `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>`;
    }
    function infoIcon() {
        return `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`;
    }

    /* ── Beep ────────────────────────────────────────────────── */
    function playBeep() {
        try {
            const ac = new (window.AudioContext || window.webkitAudioContext)();
            const o  = ac.createOscillator();
            const g  = ac.createGain();
            o.frequency.value = 1380;
            o.connect(g); g.connect(ac.destination);
            g.gain.setValueAtTime(0.28, ac.currentTime);
            g.gain.exponentialRampToValueAtTime(0.001, ac.currentTime + 0.1);
            o.start(); o.stop(ac.currentTime + 0.1);
        } catch (e) {}
    }

    /* ── Brightness ──────────────────────────────────────────── */
    brightSlider.addEventListener('input', () => {
        const v = parseInt(brightSlider.value, 10);
        brightVal.textContent = v;
        video.style.filter = `brightness(${1 + v / 100})`;
        if (stream) {
            stream.getVideoTracks().forEach(t => {
                try { t.applyConstraints({ advanced:[{ brightness:v },{ exposureCompensation:v/10 }] }).catch(()=>{}); } catch(_){}
            });
        }
    });

    /* ── Camera change ───────────────────────────────────────── */
    camSel.addEventListener('change', () => {
        const was = scanning;
        stopScanner();
        stopStream();
        startPreview(camSel.value).then(() => { if (was) window.startScanner(); });
    });

    /* ── Enter key on manual input ───────────────────────────── */
    document.getElementById('scManualInput').addEventListener('keypress', e => {
        if (e.key === 'Enter') window.lookupManual();
    });

    /* ── Formatters ──────────────────────────────────────────── */
    const SM = { pending:'Pending', confirmed:'Confirmed', in_progress:'In Progress', completed:'Completed', cancelled:'Cancelled' };
    function fStatus(s) { return SM[s] || s; }
    function fDate(d)   { return new Date(d).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); }
    function fTime(t)   { if(!t) return '—'; const[h,m]=t.split(':'),hr=+h; return `${hr%12||12}:${m} ${hr>=12?'PM':'AM'}`; }
    function esc(s)     { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

    /* ── Sidebar ─────────────────────────────────────────────── */
    window.toggleSidebar = function() {
        const sb = document.querySelector('.sidebar');
        const isOpen = !(sb && sb.classList.contains('open'));
        if (typeof setSidebarOpenState === 'function') {
            setSidebarOpenState(isOpen);
            return;
        }
        document.querySelector('.sidebar').classList.toggle('open');
        document.querySelector('.sidebar-overlay').classList.toggle('open');
    };

    /* ── Init ────────────────────────────────────────────────── */
    document.addEventListener('DOMContentLoaded', async () => {
        await populateCameras();
        if (camSel.value) await startPreview(camSel.value);
    });

})();
</script>
</body>
</html>