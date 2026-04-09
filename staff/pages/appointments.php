<?php
require_once __DIR__ . '/../../config/config.php';

if (!isLoggedIn() || getUserRole() !== 'staff') {
    redirect('login.php');
}

$db = Database::getInstance()->getConnection();

$scannedRaw           = $_GET['scanned'] ?? null;
$scannedAppointmentId = null;
if ($scannedRaw !== null) {
    $decoded = json_decode($scannedRaw, true);
    if (is_array($decoded) && isset($decoded['id'])) {
        $scannedAppointmentId = (int)$decoded['id'];
    } else {
        $scannedAppointmentId = (int)$scannedRaw;
    }
}

/* ═══════════════════════════ AJAX ══════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    /* ── scan_check ─────────────────────────────────────────────── */
    if ($action === 'scan_check') {
        $appointmentId = 0;
        $qrRaw = $_POST['qr_data'] ?? '';
        if ($qrRaw !== '') {
            $qrParsed = json_decode($qrRaw, true);
            if (is_array($qrParsed) && isset($qrParsed['id'])) {
                $appointmentId = (int)$qrParsed['id'];
            }
        }
        if ($appointmentId === 0) $appointmentId = (int)($_POST['appointment_id'] ?? 0);
        if ($appointmentId === 0) {
            echo json_encode(['success' => false, 'code' => 'not_found', 'message' => 'Invalid QR code.']);
            exit;
        }

        try {
            $stmt = $db->prepare("
                SELECT a.*,
                       s.name        AS service_name,
                       s.label       AS service_label,
                       s.price       AS service_price,
                       s.duration    AS service_duration,
                       s.description AS service_description,
                       p.id          AS payment_id,
                       p.amount      AS payment_amount,
                       p.method      AS payment_method,
                       p.status      AS payment_status,
                       p.reference   AS payment_reference,
                       p.paid_at     AS payment_paid_at
                FROM  appointments a
                LEFT JOIN services  s ON a.service_id     = s.id
                LEFT JOIN payments  p ON p.appointment_id = a.id
                WHERE a.id = ? LIMIT 1
            ");
            $stmt->execute([$appointmentId]);
            $appt = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$appt) {
                echo json_encode(['success' => false, 'code' => 'not_found', 'message' => 'Appointment not found.']);
                exit;
            }

            // Fetch addon details + compute totals
            $addonDetails    = [];
            $addonsTotalUnit = 0.0;
            if (!empty($appt['addons'])) {
                $addonIds = json_decode($appt['addons'], true);
                if (is_array($addonIds) && count($addonIds)) {
                    $ph = implode(',', array_fill(0, count($addonIds), '?'));
                    $adSt = $db->prepare("SELECT id, name, price FROM addons WHERE id IN ($ph)");
                    $adSt->execute($addonIds);
                    $addonDetails = $adSt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($addonDetails as $ad) $addonsTotalUnit += (float)($ad['price'] ?? 0);
                }
            }
            $appt['addon_details'] = $addonDetails;

            $qty           = (int)($appt['quantity'] ?? 1);
            $servicePrice  = (float)($appt['service_price'] ?? 0);
            $serviceTotal  = $servicePrice * $qty;
            $addonsTotal   = $addonsTotalUnit * $qty;
            $computedTotal = $serviceTotal + $addonsTotal;

            $appt['computed_total']    = $computedTotal;
            $appt['computed_service']  = $serviceTotal;
            $appt['computed_addons']   = $addonsTotal;
            $appt['addons_unit_price'] = $addonsTotalUnit;

            if (in_array($appt['status'], ['completed', 'cancelled'])) {
                echo json_encode([
                    'success'   => false,
                    'code'      => 'locked',
                    'status'    => $appt['status'],
                    'reference' => 'APT-' . str_pad($appointmentId, 6, '0', STR_PAD_LEFT),
                    'name'      => $appt['full_name'],
                    'service'   => ($appt['service_label'] ?? '') . ': ' . ($appt['service_name'] ?? 'N/A'),
                ]);
                exit;
            }

            echo json_encode(['success' => true, 'appointment' => $appt]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'code' => 'db_error', 'message' => 'Database error.']);
        }
        exit;
    }

    /* ── confirm_scan ───────────────────────────────────────────── */
    if ($action === 'confirm_scan') {
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);
        try {
            $upd = $db->prepare("UPDATE appointments SET status = 'pending', updated_at = NOW() WHERE id = ?");
            $upd->execute([$appointmentId]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error.']);
        }
        exit;
    }

    /* ── update_status ──────────────────────────────────────────── */
    if ($action === 'update_status') {
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);
        $newStatus     = $_POST['status'] ?? '';
        $validStatuses = ['pending', 'in_progress', 'completed', 'cancelled'];
        if (!in_array($newStatus, $validStatuses)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status']); exit;
        }
        try {
            $getStmt = $db->prepare("SELECT a.*, s.name AS service_name, s.label AS service_label FROM appointments a LEFT JOIN services s ON a.service_id = s.id WHERE a.id = ?");
            $getStmt->execute([$appointmentId]);
            $appointmentData = $getStmt->fetch(PDO::FETCH_ASSOC);
            $stmt = $db->prepare("UPDATE appointments SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newStatus, $appointmentId]);
            if ($stmt->rowCount() > 0) {
                if ($appointmentData && !empty($appointmentData['user_id'])) {
                    try {
                        $labels  = ['pending'=>'Pending','in_progress'=>'In Progress','completed'=>'Completed','cancelled'=>'Cancelled'];
                        $ref     = 'APT-' . str_pad($appointmentId, 6, '0', STR_PAD_LEFT);
                        $body    = "Your appointment ({$ref}) has been updated to: " . ($labels[$newStatus] ?? $newStatus);
                        $insStmt = $db->prepare("INSERT INTO notifications (user_id, role, type, title, body, url, is_read, created_at) VALUES (?, NULL, ?, ?, ?, ?, 0, NOW())");
                        $insStmt->execute([$appointmentData['user_id'], 'appointment.status_changed', 'Appointment Status Updated', $body, rtrim(APP_URL, '/') . '/user/pages/my-appointments.php']);
                    } catch (Exception $e) { error_log('Notification error: ' . $e->getMessage()); }
                }
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Appointment not found']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

/* ═══════════════════════ FETCH LIST ════════════════════════════════
 * We compute addon_unit_total per appointment directly in PHP
 * so the JS openModal() can display the correct grand total.
 */
$appointments = [];
try {
    $stmt = $db->query("
        SELECT a.*,
               s.name     AS service_name,  s.label    AS service_label,
               s.price    AS service_price, s.duration AS service_duration,
               u.name     AS user_name,     u.email    AS user_email,
               p.id       AS payment_id,
               p.amount   AS payment_amount, p.method  AS payment_method,
               p.status   AS payment_status, p.reference AS payment_reference
        FROM   appointments a
        LEFT JOIN services  s ON a.service_id    = s.id
        LEFT JOIN users     u ON a.user_id        = u.id
        LEFT JOIN payments  p ON p.appointment_id = a.id
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");
    $rawAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Resolve addon unit totals for each appointment
    // Collect all unique addon IDs first (one query)
    $allAddonIds = [];
    foreach ($rawAppointments as $a) {
        if (!empty($a['addons'])) {
            $ids = json_decode($a['addons'], true);
            if (is_array($ids)) foreach ($ids as $id) $allAddonIds[$id] = true;
        }
    }
    $addonPriceMap = [];
    if (!empty($allAddonIds)) {
        $ph = implode(',', array_fill(0, count($allAddonIds), '?'));
        $adSt = $db->prepare("SELECT id, price FROM addons WHERE id IN ($ph)");
        $adSt->execute(array_keys($allAddonIds));
        foreach ($adSt->fetchAll(PDO::FETCH_ASSOC) as $ad) {
            $addonPriceMap[(int)$ad['id']] = (float)$ad['price'];
        }
    }

    foreach ($rawAppointments as &$a) {
        $addonUnitTotal = 0.0;
        if (!empty($a['addons'])) {
            $ids = json_decode($a['addons'], true);
            if (is_array($ids)) {
                foreach ($ids as $id) $addonUnitTotal += ($addonPriceMap[(int)$id] ?? 0);
            }
        }
        $qty = (int)($a['quantity'] ?? 1);
        $a['addon_unit_total']   = $addonUnitTotal;           // sum of addon prices (per unit)
        $a['addon_grand_total']  = $addonUnitTotal * $qty;    // × qty
        $a['service_grand_total']= (float)($a['service_price'] ?? 0) * $qty;
        $a['computed_total']     = $a['service_grand_total'] + $a['addon_grand_total'];
    }
    unset($a);
    $appointments = $rawAppointments;

} catch (PDOException $e) {}

$counts = ['all' => count($appointments)];
foreach (['pending','in_progress','completed','cancelled'] as $s) {
    $counts[$s] = count(array_filter($appointments, fn($a) => $a['status'] === $s));
}
?>
<!DOCTYPE html>
<html lang="en" class="allow-page-x-scroll">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Appointments — <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; }
body { font-family: 'Plus Jakarta Sans', sans-serif; }

.topbar { display:flex; justify-content:space-between; align-items:flex-end; gap:1rem; margin-bottom:1.75rem; flex-wrap:wrap; }
.topbar-left h1 { font-size:1.65rem; font-weight:800; color:var(--cream); letter-spacing:-.6px; margin:0 0 3px; }
.topbar-left p  { font-size:.83rem; color:var(--gray-text); margin:0; }
.btn-scan { display:inline-flex; align-items:center; gap:8px; padding:10px 20px; border-radius:11px; background:var(--primary-red); color:#fff; border:none; font-family:'Plus Jakarta Sans',sans-serif; font-weight:700; font-size:.875rem; text-decoration:none; cursor:pointer; box-shadow:0 4px 18px rgba(230,57,70,.35); transition:background .18s,transform .15s,box-shadow .18s; }
.btn-scan:hover { background:#c32030; transform:translateY(-2px); box-shadow:0 7px 26px rgba(230,57,70,.45); }

#scanBanner { margin-bottom:1.25rem; }
.s-banner { display:flex; align-items:center; gap:14px; padding:14px 18px; border-radius:14px; border:1px solid; animation:banIn .35s cubic-bezier(.34,1.56,.64,1); }
@keyframes banIn { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:translateY(0)} }
.s-banner.ok  { background:rgba(34,197,94,.07);  border-color:rgba(34,197,94,.3); }
.s-banner.err { background:rgba(239,68,68,.07);  border-color:rgba(239,68,68,.3); }
.bico { width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.s-banner.ok  .bico { background:rgba(34,197,94,.12); color:#4ade80; }
.s-banner.err .bico { background:rgba(239,68,68,.12); color:#f87171; }
.btxt { flex:1; }
.btxt strong { display:block; font-size:.875rem; font-weight:700; margin-bottom:2px; }
.s-banner.ok  .btxt strong { color:#4ade80; }
.s-banner.err .btxt strong { color:#f87171; }
.btxt p { font-size:.82rem; color:var(--gray-text); line-height:1.5; margin:0; }
.bdismiss { background:transparent; border:1px solid rgba(255,255,255,.1); color:var(--gray-text); padding:6px 14px; border-radius:7px; cursor:pointer; font-family:'Plus Jakarta Sans',sans-serif; font-size:.8rem; white-space:nowrap; transition:all .18s; }
.bdismiss:hover { color:var(--cream); border-color:rgba(255,255,255,.25); }

.fpills { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:1.25rem; }
.fpill { display:inline-flex; align-items:center; gap:7px; padding:7px 15px; border-radius:100px; border:1px solid var(--border-color); background:transparent; color:var(--gray-text); font-family:'Plus Jakarta Sans',sans-serif; font-size:.82rem; font-weight:600; cursor:pointer; transition:all .18s; user-select:none; }
.fpill:hover { color:var(--cream); border-color:rgba(255,255,255,.2); }
.fpill.active { background:rgba(230,57,70,.1); border-color:rgba(230,57,70,.5); color:var(--cream); }
.fpill .fc { font-family:'IBM Plex Mono',monospace; font-size:.72rem; font-weight:600; background:rgba(255,255,255,.07); border-radius:100px; padding:1px 8px; }
.fpill.active .fc { background:rgba(230,57,70,.22); color:#f87171; }

.acard { background:var(--dark-card); border:1px solid var(--border-color); border-radius:16px; overflow:hidden; }
.acard-top { display:flex; align-items:center; justify-content:space-between; padding:13px 18px; border-bottom:1px solid var(--border-color); gap:12px; flex-wrap:wrap; }
.srch { position:relative; flex:1; min-width:180px; max-width:340px; }
.srch svg { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--gray-text); pointer-events:none; }
.srch input { width:100%; padding:9px 12px 9px 36px; border-radius:9px; border:1px solid var(--border-color); background:rgba(255,255,255,.04); color:var(--cream); font-family:'Plus Jakarta Sans',sans-serif; font-size:.87rem; transition:border-color .18s,background .18s; }
.srch input:focus { outline:none; border-color:rgba(230,57,70,.5); background:rgba(255,255,255,.06); }
.srch input::placeholder { color:var(--gray-text); }

.twrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior-x: contain;
    scroll-padding-inline: 0.35rem;
    padding-bottom: 2px;
}
.atbl { width:100%; border-collapse:collapse; font-size:.875rem; }
.atbl thead th { padding:10px 16px; text-align:left; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--gray-text); background:rgba(255,255,255,.02); border-bottom:1px solid var(--border-color); white-space:nowrap; }
.atbl tbody tr { transition:background .12s; }
.atbl tbody tr:hover { background:rgba(255,255,255,.025); }
.atbl td { padding:13px 16px; border-bottom:1px solid rgba(255,255,255,.04); vertical-align:middle; color:var(--cream); }
.atbl tbody tr:last-child td { border-bottom:none; }
.atbl tbody tr.highlighted { background:rgba(230,57,70,.07)!important; box-shadow:inset 3px 0 0 var(--primary-red); }

.ref { font-family:'IBM Plex Mono',monospace; font-size:.78rem; font-weight:600; color:#f87171; background:rgba(230,57,70,.1); padding:4px 9px; border-radius:7px; display:inline-block; white-space:nowrap; }
.cn  { font-weight:700; font-size:.88rem; }
.cc  { font-size:.78rem; color:var(--gray-text); margin-top:2px; }
.sl  { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--gray-text); }
.sn  { font-size:.87rem; font-weight:600; margin-top:2px; }
.dd  { font-weight:600; font-size:.87rem; }
.dt  { font-size:.78rem; color:var(--gray-text); margin-top:2px; }

.pay-chip { display:inline-flex; align-items:center; gap:5px; font-size:.75rem; font-weight:700; padding:3px 9px; border-radius:100px; white-space:nowrap; }
.pay-paid   { background:rgba(34,197,94,.1);  color:#4ade80; border:1px solid rgba(34,197,94,.2); }
.pay-unpaid { background:rgba(251,191,36,.1); color:#fbbf24; border:1px solid rgba(251,191,36,.2); }
.pay-none   { background:rgba(255,255,255,.05); color:var(--gray-text); border:1px solid var(--border-color); }

.badge { display:inline-flex; align-items:center; gap:5px; padding:4px 11px; border-radius:100px; font-size:.74rem; font-weight:700; white-space:nowrap; }
.badge::before { content:''; width:5px; height:5px; border-radius:50%; background:currentColor; flex-shrink:0; }
.badge-pending     { background:rgba(251,191,36,.1);  color:#fbbf24; }
.badge-in_progress { background:rgba(249,115,22,.1);  color:#fb923c; }
.badge-completed   { background:rgba(34,197,94,.1);   color:#4ade80; }
.badge-cancelled   { background:rgba(239,68,68,.1);   color:#f87171; }

.btn-upd { display:inline-flex; align-items:center; gap:6px; padding:7px 13px; border-radius:8px; border:1px solid var(--border-color); background:transparent; color:var(--gray-text); font-family:'Plus Jakarta Sans',sans-serif; font-size:.8rem; font-weight:600; cursor:pointer; transition:all .18s; white-space:nowrap; }
.btn-upd:hover { background:rgba(230,57,70,.08); border-color:rgba(230,57,70,.4); color:var(--cream); }
.acard-foot { padding:10px 18px; border-top:1px solid var(--border-color); font-size:.8rem; color:var(--gray-text); }
.swipe-hint { display:none; text-align:center; font-size:.72rem; color:var(--gray-text); padding:4px 0 6px; letter-spacing:.03em; }

.empty-state { text-align:center; padding:4rem 2rem; }
.empty-state .eico { width:60px;height:60px;border-radius:15px;background:rgba(255,255,255,.04);border:1px solid var(--border-color);display:inline-flex;align-items:center;justify-content:center;margin-bottom:1rem;color:var(--gray-text); }
.empty-state h4 { color:var(--cream); margin:0 0 5px; font-size:1rem; font-weight:700; }
.empty-state p  { color:var(--gray-text); font-size:.85rem; margin:0; }

/* ── Modals ──────────────────────────────────────────── */
.moverlay { position:fixed; inset:0; background:rgba(0,0,0,.6); backdrop-filter:blur(10px); display:none; align-items:center; justify-content:center; z-index:9990; padding:1rem; }
.moverlay.open { display:flex; }
.mbox { background:var(--dark-card); border:1px solid var(--border-color); border-radius:22px; width:660px; max-width:100%; max-height:92vh; overflow-y:auto; animation:mIn .27s cubic-bezier(.34,1.56,.64,1); }
@keyframes mIn { from{opacity:0;transform:scale(.93) translateY(14px)} to{opacity:1;transform:scale(1) translateY(0)} }
.mhead { display:flex; align-items:center; justify-content:space-between; padding:20px 24px; border-bottom:1px solid var(--border-color); position:sticky; top:0; background:var(--dark-card); border-radius:22px 22px 0 0; z-index:1; }
.mhead-l { display:flex; align-items:center; gap:12px; }
.mhead-ico { width:38px; height:38px; border-radius:11px; display:flex; align-items:center; justify-content:center; flex-shrink:0; background:rgba(230,57,70,.1); border:1px solid rgba(230,57,70,.2); color:var(--primary-red); }
.mhead h3 { font-size:1.08rem; font-weight:800; color:var(--cream); margin:0; line-height:1.2; }
.mhead-sub { font-size:.78rem; color:var(--gray-text); margin-top:2px; font-family:'IBM Plex Mono',monospace; }
.mclose { width:32px; height:32px; border-radius:8px; background:rgba(255,255,255,.06); border:1px solid var(--border-color); color:var(--gray-text); display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all .18s; flex-shrink:0; }
.mclose:hover { color:var(--cream); border-color:rgba(255,255,255,.25); background:rgba(255,255,255,.1); }
.mbody { padding:22px 24px; }

.sec-label { font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.1em; color:var(--gray-text); margin:0 0 11px; display:flex; align-items:center; gap:8px; }
.sec-label::after { content:''; flex:1; height:1px; background:var(--border-color); }

.info-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:20px; }
.ig-item label { display:block; font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--gray-text); margin-bottom:5px; }
.ig-val { background:rgba(255,255,255,.04); border:1px solid var(--border-color); border-radius:9px; padding:9px 12px; color:var(--cream); font-size:.875rem; min-height:38px; display:flex; align-items:center; flex-wrap:wrap; gap:5px; }
.ig-val.mono { font-family:'IBM Plex Mono',monospace; font-size:.82rem; color:#f87171; }
.ig-full { grid-column:1/-1; }

.svc-card { background:rgba(255,255,255,.03); border:1px solid var(--border-color); border-radius:13px; padding:16px; margin-bottom:20px; display:flex; align-items:flex-start; gap:14px; }
.svc-card-icon { width:44px; height:44px; border-radius:11px; background:rgba(230,57,70,.1); border:1px solid rgba(230,57,70,.2); display:flex; align-items:center; justify-content:center; flex-shrink:0; color:var(--primary-red); }
.svc-card-info { flex:1; min-width:0; }
.svc-card-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--gray-text); }
.svc-card-name  { font-size:1rem; font-weight:800; color:var(--cream); margin:3px 0 2px; }
.svc-card-meta  { font-size:.82rem; color:var(--gray-text); }
.svc-card-price { font-family:'IBM Plex Mono',monospace; font-size:1.15rem; font-weight:700; color:#4ade80; white-space:nowrap; flex-shrink:0; }

.addon-chips { display:flex; flex-wrap:wrap; gap:7px; margin-bottom:20px; }
.addon-chip { display:inline-flex; align-items:center; gap:6px; padding:5px 11px; border-radius:100px; background:rgba(230,57,70,.08); border:1px solid rgba(230,57,70,.2); font-size:.78rem; font-weight:600; color:var(--cream); }
.addon-chip .acp { font-family:'IBM Plex Mono',monospace; color:#f87171; font-size:.75rem; }

/* ── Payment breakdown ────────────────────────────────── */
.pay-breakdown { background:rgba(255,255,255,.03); border:1px solid var(--border-color); border-radius:13px; overflow:hidden; margin-bottom:20px; }
.pay-breakdown-header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid var(--border-color); background:rgba(255,255,255,.02); }
.pay-breakdown-header-left { display:flex; align-items:center; gap:10px; }
.pay-icon { width:34px; height:34px; border-radius:9px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.pay-icon.paid     { background:rgba(34,197,94,.1);  color:#4ade80; border:1px solid rgba(34,197,94,.2); }
.pay-icon.unpaid   { background:rgba(251,191,36,.1); color:#fbbf24; border:1px solid rgba(251,191,36,.2); }
.pay-icon.computed { background:rgba(59,130,246,.1); color:#60a5fa; border:1px solid rgba(59,130,246,.2); }
.pay-status-label { font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--gray-text); margin-bottom:2px; }
.pay-status-val   { font-size:.92rem; font-weight:700; color:var(--cream); }
.pay-status-meta  { font-size:.75rem; color:var(--gray-text); }
.pay-total-amount { font-family:'IBM Plex Mono',monospace; font-size:1.4rem; font-weight:700; white-space:nowrap; flex-shrink:0; }
.pay-total-amount.paid     { color:#4ade80; }
.pay-total-amount.unpaid   { color:#fbbf24; }
.pay-total-amount.computed { color:#60a5fa; }
.pay-lines { padding:12px 16px; display:flex; flex-direction:column; gap:6px; }
.pay-line { display:flex; justify-content:space-between; align-items:center; font-size:.83rem; }
.pay-line .pl-label { color:var(--gray-text); }
.pay-line .pl-amt   { font-family:'IBM Plex Mono',monospace; font-weight:600; color:var(--cream); }
.pay-line.total-line { border-top:1px solid var(--border-color); padding-top:9px; margin-top:3px; font-size:.92rem; font-weight:800; }
.pay-line.total-line .pl-label { color:var(--cream); }
.pay-line.total-line .pl-amt   { color:#4ade80; font-size:1rem; }
.pay-source-note { padding:8px 16px; border-top:1px solid var(--border-color); font-size:.72rem; color:var(--gray-text); display:flex; align-items:center; gap:6px; background:rgba(255,255,255,.01); }
.pay-source-note svg { flex-shrink:0; opacity:.6; }

/* ── Status modal ─────────────────────────────────────── */
.dgrid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:20px; }
.di label { display:block; font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--gray-text); margin-bottom:5px; }
.dv { background:rgba(255,255,255,.04); border:1px solid var(--border-color); border-radius:9px; padding:9px 12px; color:var(--cream); font-size:.87rem; min-height:38px; display:flex; align-items:center; flex-wrap:wrap; gap:6px; }
.dv.mono { font-family:'IBM Plex Mono',monospace; font-size:.82rem; color:#f87171; }

/* ── Amount breakdown inside status modal ─────────────── */
.amt-breakdown { background:rgba(34,197,94,.05); border:1px solid rgba(34,197,94,.18); border-radius:11px; overflow:hidden; grid-column:1/-1; }
.amt-breakdown-lines { padding:10px 14px; display:flex; flex-direction:column; gap:5px; }
.amt-line { display:flex; justify-content:space-between; font-size:.82rem; }
.amt-line .al { color:var(--gray-text); }
.amt-line .av { font-family:'IBM Plex Mono',monospace; color:var(--cream); font-weight:600; }
.amt-total { display:flex; justify-content:space-between; align-items:center; padding:9px 14px; border-top:1px solid rgba(34,197,94,.15); }
.amt-total .atl { font-size:.85rem; font-weight:800; color:var(--cream); }
.amt-total .ata { font-family:'IBM Plex Mono',monospace; font-size:1.1rem; font-weight:800; color:#4ade80; }
/* if payment record exists override color */
.amt-total .ata.paid   { color:#4ade80; }
.amt-total .ata.unpaid { color:#fbbf24; }

.spick-lbl { font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.1em; color:var(--gray-text); margin-bottom:12px; display:flex; align-items:center; gap:8px; }
.spick-lbl::after { content:''; flex:1; height:1px; background:var(--border-color); }
.status-flow { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:12px; }
.sf-btn { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; padding:16px 10px; border-radius:13px; border:1px solid; cursor:pointer; font-family:'Plus Jakarta Sans',sans-serif; font-weight:700; font-size:.82rem; text-align:center; line-height:1.3; transition:all .2s; }
.sf-btn:hover:not(:disabled) { transform:translateY(-3px); filter:brightness(1.15); }
.sf-btn:disabled { opacity:.22; cursor:not-allowed; transform:none!important; filter:none!important; }
.sf-icon { width:36px; height:36px; border-radius:9px; display:flex; align-items:center; justify-content:center; }
.sf-pending     { background:rgba(251,191,36,.08); border-color:rgba(251,191,36,.3); color:#fbbf24; }
.sf-pending     .sf-icon { background:rgba(251,191,36,.12); }
.sf-in_progress { background:rgba(249,115,22,.08); border-color:rgba(249,115,22,.3); color:#fb923c; }
.sf-in_progress .sf-icon { background:rgba(249,115,22,.12); }
.sf-completed   { background:rgba(34,197,94,.08);  border-color:rgba(34,197,94,.3);  color:#4ade80; }
.sf-completed   .sf-icon { background:rgba(34,197,94,.12); }

.status-cancel-row { display:flex; justify-content:flex-end; }
.btn-cancel-apt { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:9px; border:1px solid rgba(239,68,68,.2); background:rgba(239,68,68,.06); color:#f87171; font-family:'Plus Jakarta Sans',sans-serif; font-size:.8rem; font-weight:600; cursor:pointer; transition:all .18s; }
.btn-cancel-apt:hover { background:rgba(239,68,68,.13); border-color:rgba(239,68,68,.35); }
.btn-cancel-apt:disabled { opacity:.25; cursor:not-allowed; }

.mpending { display:flex; align-items:flex-start; gap:10px; padding:12px 14px; border-radius:10px; background:rgba(251,191,36,.07); border:1px solid rgba(251,191,36,.22); margin-bottom:18px; font-size:.85rem; color:var(--cream); line-height:1.5; }
.mpending svg { flex-shrink:0; margin-top:1px; color:#fbbf24; }
.mpending span { color:#fbbf24; font-weight:700; }

.confirm-actions { display:flex; gap:10px; }
.btn-approve { flex:1; display:flex; align-items:center; justify-content:center; gap:8px; padding:13px; border-radius:12px; cursor:pointer; background:rgba(34,197,94,.1); border:1px solid rgba(34,197,94,.28); color:#4ade80; font-family:'Plus Jakarta Sans',sans-serif; font-weight:700; font-size:.9rem; transition:all .2s; }
.btn-approve:hover { background:rgba(34,197,94,.2); transform:translateY(-2px); box-shadow:0 6px 20px rgba(34,197,94,.18); }
.btn-decline { flex:1; display:flex; align-items:center; justify-content:center; gap:8px; padding:13px; border-radius:12px; cursor:pointer; background:rgba(239,68,68,.07); border:1px solid rgba(239,68,68,.22); color:#f87171; font-family:'Plus Jakarta Sans',sans-serif; font-weight:700; font-size:.9rem; transition:all .2s; }
.btn-decline:hover { background:rgba(239,68,68,.16); transform:translateY(-2px); }

#lockedModal .mbox { max-width:430px; }
.locked-body { padding:28px 24px; text-align:center; }
.lico { width:72px; height:72px; border-radius:20px; display:inline-flex; align-items:center; justify-content:center; margin-bottom:18px; }
.lico.completed { background:rgba(34,197,94,.1); color:#4ade80; border:1px solid rgba(34,197,94,.2); }
.lico.cancelled { background:rgba(239,68,68,.1); color:#f87171; border:1px solid rgba(239,68,68,.2); }
.ltitle { font-size:1.1rem; font-weight:800; color:var(--cream); margin:0 0 8px; }
.lmsg   { font-size:.87rem; color:var(--gray-text); line-height:1.55; margin:0 0 18px; }
.lref   { display:inline-block; font-family:'IBM Plex Mono',monospace; font-size:.82rem; font-weight:600; color:#f87171; background:rgba(230,57,70,.1); padding:5px 14px; border-radius:8px; margin-bottom:4px; }
.lname  { font-size:.83rem; color:var(--gray-text); margin:0 0 18px; }
.lbadge { margin-bottom:22px; }
.btn-got { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; padding:12px; border-radius:11px; border:none; background:var(--primary-red); color:#fff; font-family:'Plus Jakarta Sans',sans-serif; font-weight:700; font-size:.9rem; cursor:pointer; box-shadow:0 4px 16px rgba(230,57,70,.3); transition:all .18s; }
.btn-got:hover { background:#c32030; transform:translateY(-1px); }

#toast { position:fixed; bottom:1.5rem; right:1.5rem; min-width:260px; background:var(--dark-card); border:1px solid var(--border-color); border-radius:13px; padding:13px 18px; display:flex; align-items:center; gap:10px; font-family:'Plus Jakarta Sans',sans-serif; font-size:.875rem; font-weight:500; color:var(--cream); z-index:99999; pointer-events:none; transform:translateY(60px) scale(.96); opacity:0; transition:all .3s cubic-bezier(.34,1.56,.64,1); }
#toast.show { transform:translateY(0) scale(1); opacity:1; }
#toast.ts { border-color:rgba(34,197,94,.35); }
#toast.te { border-color:rgba(239,68,68,.35); }
#toast.tw { border-color:rgba(251,191,36,.35); }
.tdot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
#toast.ts .tdot { background:#4ade80; }
#toast.te .tdot { background:#f87171; }
#toast.tw .tdot { background:#fbbf24; }

@media (hover: none) and (pointer: coarse) {
    .fpills {
        flex-wrap: nowrap;
        overflow-x: auto;
        overflow-y: hidden;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior-x: contain;
        scrollbar-width: none;
        padding-bottom: 4px;
    }

    .fpills::-webkit-scrollbar { display: none; }

    .fpill {
        flex: 0 0 auto;
        white-space: nowrap;
    }

    .twrap {
        overflow-x: auto;
        overflow-y: hidden;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior-x: contain;
    }

    .atbl {
        min-width: 660px;
    }
}

@media(max-width:860px) {
    .swipe-hint { display:block; }
    .col-hide{display:none;}
    .info-grid,.dgrid{grid-template-columns:1fr;}
    .status-flow{grid-template-columns:1fr 1fr;}
    .atbl { min-width: 720px; }
    .fpills {
        flex-wrap: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        padding-bottom: 4px;
    }
    .fpill {
        flex: 0 0 auto;
        white-space: nowrap;
    }
}

@media (max-width: 640px) {
    .atbl { min-width: 660px; }

    .fpills {
        flex-wrap: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        padding-bottom: 6px;
        scrollbar-width: none;
    }

    .fpills::-webkit-scrollbar { display: none; }

    .fpill { flex-shrink: 0; }

    .topbar { flex-direction: column; align-items: flex-start; }
    .btn-scan { width: 100%; justify-content: center; }

    .mbox { border-radius: 16px 16px 0 0; max-height: 85vh; width: 100%; }
    .moverlay { align-items: flex-end; padding: 0; }

    .status-flow { grid-template-columns: 1fr 1fr; }
    .info-grid, .dgrid { grid-template-columns: 1fr; }

    .svc-card { flex-direction: column; }
    .svc-card-price { align-self: flex-start; }

    .confirm-actions { flex-direction: column; }

    .s-banner { flex-wrap: wrap; }

    .acard-top { flex-direction: column; align-items: stretch; }
    .srch { max-width: 100%; }
}

@media(max-width:560px) { .topbar-left h1{font-size:1.35rem;} .svc-card{flex-direction:column;} .confirm-actions{flex-direction:column;} .status-flow{grid-template-columns:1fr;} }
</style>
</head>
<body class="allow-page-x-scroll">
<div class="dashboard-layout">
    <?php include '../includes/sidebar.php'; ?>
    <?php include '../includes/header.php'; ?>
    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <main class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <h1>Appointments</h1>
                <p>Manage bookings and track service status</p>
            </div>
            <a href="scanner.php" class="btn-scan">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/>
                    <rect x="3" y="14" width="7" height="7" rx="1.5"/>
                    <path d="M14 14h2v2h-2z"/><path d="M18 14h3"/><path d="M14 18h2"/><path d="M18 18v3"/><path d="M21 21h-3"/>
                </svg>
                Scan QR Code
            </a>
        </div>

        <div id="scanBanner"></div>

        <div class="fpills drag-scroll">
            <button class="fpill active" data-filter="all"         onclick="setFilter('all',this)">All <span class="fc"><?php echo $counts['all']; ?></span></button>
            <button class="fpill"        data-filter="pending"     onclick="setFilter('pending',this)">Pending <span class="fc"><?php echo $counts['pending']; ?></span></button>
            <button class="fpill"        data-filter="in_progress" onclick="setFilter('in_progress',this)">In Progress <span class="fc"><?php echo $counts['in_progress']; ?></span></button>
            <button class="fpill"        data-filter="completed"   onclick="setFilter('completed',this)">Completed <span class="fc"><?php echo $counts['completed']; ?></span></button>
            <button class="fpill"        data-filter="cancelled"   onclick="setFilter('cancelled',this)">Cancelled <span class="fc"><?php echo $counts['cancelled']; ?></span></button>
        </div>
        <div class="swipe-hint">Swipe for more →</div>

        <div class="acard">
            <div class="acard-top">
                <div class="srch">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="text" id="searchInput" placeholder="Search reference, customer, service…">
                </div>
                <span id="cntLabel" style="font-size:.82rem;color:var(--gray-text);font-family:'IBM Plex Mono',monospace;"><?php echo count($appointments); ?> records</span>
            </div>
            <div class="twrap drag-scroll">
                <table class="atbl">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Customer</th>
                            <th>Service</th>
                            <th>Date &amp; Time</th>
                            <th class="col-hide">Amount</th>
                            <th class="col-hide">Qty</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="tbody">
                    <?php if (empty($appointments)): ?>
                        <tr><td colspan="8">
                            <div class="empty-state">
                                <div class="eico">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                                    </svg>
                                </div>
                                <h4>No appointments yet</h4>
                                <p>Bookings will appear here once customers schedule online.</p>
                            </div>
                        </td></tr>
                    <?php else: ?>
                    <?php foreach ($appointments as $a):
                        $ref       = 'APT-' . str_pad($a['id'], 6, '0', STR_PAD_LEFT);
                        $isScanned = $scannedAppointmentId && $a['id'] == $scannedAppointmentId;
                        $payStatus = $a['payment_status'] ?? 'none';
                        // Use PHP-computed total (service × qty + addons × qty)
                        $displayTotal = $a['computed_total'];
                        if ($a['payment_id'] && !empty($a['payment_amount'])) {
                            $displayTotal = (float)$a['payment_amount'];
                        }
                    ?>
                        <tr data-id="<?php echo $a['id']; ?>"
                            data-status="<?php echo $a['status']; ?>"
                            class="<?php echo $isScanned ? 'highlighted' : ''; ?>">
                            <td><span class="ref"><?php echo $ref; ?></span></td>
                            <td>
                                <div class="cn"><?php echo htmlspecialchars($a['full_name']); ?></div>
                                <div class="cc"><?php echo htmlspecialchars($a['contact']); ?></div>
                            </td>
                            <td>
                                <div class="sl"><?php echo htmlspecialchars($a['service_label'] ?? ''); ?></div>
                                <div class="sn"><?php echo htmlspecialchars($a['service_name'] ?? 'N/A'); ?></div>
                            </td>
                            <td>
                                <div class="dd"><?php echo date('M j, Y', strtotime($a['appointment_date'])); ?></div>
                                <div class="dt"><?php echo date('g:i A', strtotime($a['appointment_time'])); ?></div>
                            </td>
                            <td class="col-hide">
                                <?php if ($a['payment_id'] && $payStatus === 'paid'): ?>
                                    <span class="pay-chip pay-paid">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg>
                                        Paid · ₱<?php echo number_format($displayTotal, 2); ?>
                                    </span>
                                <?php elseif ($a['payment_id']): ?>
                                    <span class="pay-chip pay-unpaid">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                        Unpaid · ₱<?php echo number_format($displayTotal, 2); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="pay-chip pay-none" style="font-family:'IBM Plex Mono',monospace;">
                                        ₱<?php echo number_format($displayTotal, 2); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="col-hide"><?php echo (int)$a['quantity']; ?>x</td>
                            <td><span class="badge badge-<?php echo $a['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $a['status'])); ?></span></td>
                            <td>
                                <button class="btn-upd" data-id="<?php echo $a['id']; ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4z"/>
                                    </svg>
                                    Update
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="swipe-hint">Swipe for more →</div>
            <div class="acard-foot" id="footBar"><?php echo count($appointments); ?> total appointment(s)</div>
        </div>
    </main>
</div>

<!-- ═══════ QR CONFIRM MODAL ═══════ -->
<div id="qrConfirmModal" class="moverlay">
    <div class="mbox" style="max-width:600px;">
        <div class="mhead">
            <div class="mhead-l">
                <div class="mhead-ico">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/>
                        <rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h2v2h-2z"/>
                        <path d="M18 14h3"/><path d="M14 18h2"/><path d="M18 18v3"/><path d="M21 21h-3"/>
                    </svg>
                </div>
                <div>
                    <h3>QR Code Scanned</h3>
                    <div class="mhead-sub" id="qcRef">Verifying appointment…</div>
                </div>
            </div>
            <button class="mclose" onclick="closeQRModal()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="mbody" id="qcBody"></div>
    </div>
</div>

<!-- ═══════ STATUS UPDATE MODAL ═══════ -->
<div id="statusModal" class="moverlay">
    <div class="mbox">
        <div class="mhead">
            <div class="mhead-l">
                <div class="mhead-ico">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                </div>
                <div>
                    <h3>Update Appointment</h3>
                    <div class="mhead-sub" id="mRef">—</div>
                </div>
            </div>
            <button class="mclose" onclick="closeModal()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="mbody">
            <div id="pendingAlert" class="mpending" style="display:none;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <div>QR approved — status set to <span>Pending</span>. Select the next step below.</div>
            </div>
            <div id="mgrid" class="dgrid"></div>
            <div class="spick-lbl">Change Status</div>
            <div class="status-flow">
                <button class="sf-btn sf-pending" onclick="doUpdate('pending')" data-status="pending">
                    <div class="sf-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
                    Pending
                </button>
                <button class="sf-btn sf-in_progress" onclick="doUpdate('in_progress')" data-status="in_progress">
                    <div class="sf-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg></div>
                    In Progress
                </button>
                <button class="sf-btn sf-completed" onclick="doUpdate('completed')" data-status="completed">
                    <div class="sf-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
                    Completed
                </button>
            </div>
            <div class="status-cancel-row">
                <button class="btn-cancel-apt" id="cancelAptBtn" onclick="doUpdate('cancelled')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    Cancel Appointment
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════ LOCKED MODAL ═══════ -->
<div id="lockedModal" class="moverlay">
    <div class="mbox" style="max-width:430px;">
        <div class="mhead">
            <div class="mhead-l">
                <div class="mhead-ico" style="background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.2);color:#f87171;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                </div>
                <div><h3>Cannot Re-scan</h3><div class="mhead-sub">QR code is locked</div></div>
            </div>
            <button class="mclose" onclick="closeLockedModal()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="locked-body">
            <div id="licoWrap" class="lico completed"></div>
            <p class="ltitle" id="ltitle">Already Completed</p>
            <p class="lmsg"   id="lmsg">This appointment cannot be modified.</p>
            <div><span class="lref" id="lref">APT-000000</span></div>
            <p class="lname"  id="lname">—</p>
            <div class="lbadge"><span id="lbadge" class="badge badge-completed">Completed</span></div>
            <button class="btn-got" onclick="closeLockedModal()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>
                Got it, dismiss
            </button>
        </div>
    </div>
</div>

<div id="toast"><div class="tdot"></div><span id="tmsg"></span></div>

<script>
const appointments = <?php echo json_encode($appointments); ?>;
const scannedId    = <?php echo $scannedAppointmentId ? (int)$scannedAppointmentId : 'null'; ?>;
const scannedRaw   = <?php echo $scannedRaw ? json_encode($scannedRaw) : 'null'; ?>;
let cur = null, activeFilter = 'all', toastTimer;

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.btn-upd').forEach(b =>
        b.addEventListener('click', () => openModal(+b.dataset.id, false))
    );
    document.getElementById('searchInput').addEventListener('input', applyFilters);
    if (scannedId) handleScan(scannedId, scannedRaw);
});

/* ── QR scan ─────────────────────────────────────────── */
async function handleScan(id, rawQrString) {
    window.history.replaceState({}, document.title, 'appointments.php');
    const fd = new FormData();
    fd.append('action', 'scan_check');
    fd.append('appointment_id', id);
    if (rawQrString) fd.append('qr_data', rawQrString);
    let data;
    try {
        const r = await fetch('appointments.php', { method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'} });
        data = await r.json();
    } catch {
        showBanner('err', 'Scan Error', 'Failed to process the QR code.'); return;
    }
    if (!data.success && data.code === 'not_found') { showBanner('err', 'QR Not Found', 'No appointment matches this QR code.'); return; }
    if (!data.success && data.code === 'locked')    { showLockedModal(data); return; }
    openQRConfirmModal(data.appointment);
}

/* ── QR Confirm Modal ────────────────────────────────── */
function openQRConfirmModal(appt) {
    const ref = 'APT-' + String(appt.id).padStart(6, '0');
    document.getElementById('qcRef').textContent = ref;

    const qty          = parseInt(appt.quantity || 1);
    const svcUnitPrice = parseFloat(appt.service_price || 0);
    const svcDur       = appt.service_duration ? appt.service_duration + ' min' : '—';

    /* Add-ons (fetched fresh from DB via scan_check) */
    const addonDetails  = appt.addon_details || [];
    const addonsUnitSum = parseFloat(appt.addons_unit_price || 0);
    let addonsHtml = '';
    if (addonDetails.length) {
        addonsHtml = `<p class="sec-label" style="margin-top:0">Add-ons</p><div class="addon-chips">`;
        addonDetails.forEach(ad => {
            addonsHtml += `<span class="addon-chip">${esc(ad.name)}<span class="acp">+₱${pf(ad.price)}</span></span>`;
        });
        addonsHtml += `</div>`;
    }

    /* Payment — computed values come from PHP scan_check */
    const hasPayRec    = !!appt.payment_id;
    const payStatus    = appt.payment_status || 'none';
    const computedSvc  = parseFloat(appt.computed_service  || svcUnitPrice * qty);
    const computedAdds = parseFloat(appt.computed_addons   || addonsUnitSum * qty);
    const computedTotal= parseFloat(appt.computed_total    || computedSvc + computedAdds);
    const payAmount    = hasPayRec ? parseFloat(appt.payment_amount || 0) : computedTotal;

    let payIconCls, payIconSVG, payStatusLabel, paySourceNote, payAmountCls;
    if (hasPayRec && payStatus === 'paid') {
        payIconCls = payAmountCls = 'paid';
        payStatusLabel = 'Payment Received';
        payIconSVG = `<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>`;
        paySourceNote = 'Payment confirmed via ' + cap(appt.payment_method || 'cash') + (appt.payment_reference ? ' · Ref: ' + appt.payment_reference : '');
    } else if (hasPayRec) {
        payIconCls = payAmountCls = 'unpaid';
        payStatusLabel = 'Awaiting Payment';
        payIconSVG = `<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`;
        paySourceNote = 'Payment record exists but not yet confirmed.';
    } else {
        payIconCls = payAmountCls = 'computed';
        payStatusLabel = 'Amount Due (from booking)';
        payIconSVG = `<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>`;
        paySourceNote = 'No payment record yet. Amount calculated: service + add-ons × quantity.';
    }
    const payMethodLine = (hasPayRec && appt.payment_method)
        ? `<div class="pay-status-meta">${cap(appt.payment_method)}${appt.payment_reference ? ' · ' + appt.payment_reference : ''}</div>` : '';

    /* Line items — always show the breakdown */
    let lineItems = '';
    if (svcUnitPrice > 0) {
        lineItems += `<div class="pay-line"><span class="pl-label">${esc(appt.service_label||'Service')}: ${esc(appt.service_name||'')} × ${qty}</span><span class="pl-amt">₱${pf(computedSvc)}</span></div>`;
    }
    if (computedAdds > 0) {
        lineItems += `<div class="pay-line"><span class="pl-label">Add-ons × ${qty}</span><span class="pl-amt">₱${pf(computedAdds)}</span></div>`;
    }
    if (hasPayRec && Math.abs(parseFloat(appt.payment_amount||0) - computedTotal) > 0.01) {
        lineItems += `<div class="pay-line"><span class="pl-label" style="color:var(--gray-text);font-size:.75rem;">Recorded payment</span><span class="pl-amt" style="color:${payStatus==='paid'?'#4ade80':'#fbbf24'};">₱${pf(appt.payment_amount)}</span></div>`;
    }
    lineItems += `<div class="pay-line total-line"><span class="pl-label">Total</span><span class="pl-amt">₱${pf(payAmount)}</span></div>`;

    document.getElementById('qcBody').innerHTML = `
        <p class="sec-label">Customer Info</p>
        <div class="info-grid" style="margin-bottom:20px;">
            <div class="ig-item"><label>Full Name</label><div class="ig-val">${esc(appt.full_name)}</div></div>
            <div class="ig-item"><label>Contact</label><div class="ig-val">${esc(appt.contact)}</div></div>
            <div class="ig-item"><label>Date</label><div class="ig-val">${fDate(appt.appointment_date)}</div></div>
            <div class="ig-item"><label>Time</label><div class="ig-val">${fTime(appt.appointment_time)}</div></div>
            <div class="ig-item"><label>Helmet Type</label><div class="ig-val">${esc(appt.helmet_type||'—')}</div></div>
            <div class="ig-item"><label>Quantity</label><div class="ig-val">${qty}x helmet(s)</div></div>
            ${appt.notes?`<div class="ig-item ig-full"><label>Notes</label><div class="ig-val" style="font-style:italic;">${esc(appt.notes)}</div></div>`:''}
        </div>
        <p class="sec-label">Service</p>
        <div class="svc-card">
            <div class="svc-card-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></div>
            <div class="svc-card-info">
                <div class="svc-card-label">${esc(appt.service_label||'Service')}</div>
                <div class="svc-card-name">${esc(appt.service_name||'N/A')}</div>
                <div class="svc-card-meta">Duration: ${svcDur} · Unit: ₱${pf(svcUnitPrice)}/helmet</div>
            </div>
            <div class="svc-card-price">₱${pf(svcUnitPrice)}<span style="font-size:.65rem;font-weight:500;color:var(--gray-text);margin-left:3px;">/unit</span></div>
        </div>
        ${addonsHtml}
        <p class="sec-label">Payment</p>
        <div class="pay-breakdown">
            <div class="pay-breakdown-header">
                <div class="pay-breakdown-header-left">
                    <div class="pay-icon ${payIconCls}">${payIconSVG}</div>
                    <div>
                        <div class="pay-status-label">${payStatusLabel}</div>
                        <div class="pay-status-val">${hasPayRec?(payStatus==='paid'?'Paid':'Unpaid'):'No payment record'}</div>
                        ${payMethodLine}
                    </div>
                </div>
                <div class="pay-total-amount ${payAmountCls}">₱${pf(payAmount)}</div>
            </div>
            <div class="pay-lines">${lineItems}</div>
            <div class="pay-source-note">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                ${paySourceNote}
            </div>
        </div>
        <div class="confirm-actions">
            <button class="btn-approve" onclick="approveQR(${appt.id})">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>
                Approve &amp; Check In
            </button>
            <button class="btn-decline" onclick="declineQR()">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                Decline / Dismiss
            </button>
        </div>`;
    document.getElementById('qrConfirmModal').classList.add('open');
}

async function approveQR(id) {
    const fd = new FormData();
    fd.append('action', 'confirm_scan'); fd.append('appointment_id', id);
    try {
        const r = await fetch('appointments.php', { method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'} });
        const d = await r.json();
        if (!d.success) { showToast('Failed to approve.', 'te'); return; }
    } catch { showToast('Network error.', 'te'); return; }
    closeQRModal();
    const appt = appointments.find(a => a.id == id);
    if (appt) appt.status = 'pending';
    updateRowStatus(id, 'pending');
    const row = document.querySelector(`tr[data-id="${id}"]`);
    if (row) { row.classList.add('highlighted'); row.scrollIntoView({ behavior:'smooth', block:'center' }); }
    showBanner('ok', 'Appointment Approved', `Status set to <strong style="color:#fbbf24">Pending</strong>. Opening status updater…`);
    setTimeout(() => openModal(id, true), 500);
}

function declineQR() { closeQRModal(); showToast('Scan dismissed — no changes made.', 'tw'); }
function closeQRModal() { document.getElementById('qrConfirmModal').classList.remove('open'); }

function showLockedModal(data) {
    const isComp = data.status === 'completed';
    const wrap = document.getElementById('licoWrap');
    wrap.className = 'lico ' + data.status;
    wrap.innerHTML = isComp
        ? `<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>`
        : `<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>`;
    document.getElementById('ltitle').textContent = isComp ? 'Appointment Already Completed' : 'Appointment is Cancelled';
    document.getElementById('lmsg').textContent   = isComp ? 'This appointment is locked and cannot be re-scanned.' : 'This appointment was cancelled.';
    document.getElementById('lref').textContent   = data.reference;
    document.getElementById('lname').textContent  = data.name + ' · ' + data.service;
    document.getElementById('lbadge').className   = 'badge badge-' + data.status;
    document.getElementById('lbadge').textContent = cap(data.status);
    document.getElementById('lockedModal').classList.add('open');
}
function closeLockedModal() { document.getElementById('lockedModal').classList.remove('open'); }

/* ─────────────────────────────────────────────────────────────────
   openModal — Status update modal
   Uses PHP-computed fields from the appointments array:
     cur.computed_total     = (service_price × qty) + (addon_unit_total × qty)
     cur.service_grand_total = service_price × qty
     cur.addon_grand_total   = addon_unit_total × qty
   If a payment record exists, use p.amount instead.
───────────────────────────────────────────────────────────────── */
function openModal(id, fromScan = false) {
    cur = appointments.find(a => a.id == id);
    if (!cur) return;
    const ref = 'APT-' + String(cur.id).padStart(6, '0');
    document.getElementById('mRef').textContent = ref;

    const qty              = parseInt(cur.quantity || 1);
    const hasPayRec        = !!cur.payment_id;
    const payStatus        = cur.payment_status || 'none';

    /* ── Authoritative totals ──────────────────────────────────────
     * service_grand_total and addon_grand_total are computed in PHP
     * for every appointment in the list. They correctly reflect
     * service_price × qty  and  sum(addon prices) × qty.
     * If a payment record exists, use payment_amount as the override.
     */
    const svcTotal    = parseFloat(cur.service_grand_total  || 0);
    const addTotal    = parseFloat(cur.addon_grand_total    || 0);
    const computed    = parseFloat(cur.computed_total       || svcTotal + addTotal);
    const grandTotal  = hasPayRec && cur.payment_amount
        ? parseFloat(cur.payment_amount)
        : computed;

    const amtColor    = hasPayRec
        ? (payStatus === 'paid' ? 'paid' : 'unpaid')
        : '';

    /* Build breakdown lines */
    let lines = '';
    if (svcTotal > 0) {
        lines += `<div class="amt-line"><span class="al">Service × ${qty}</span><span class="av">₱${pf(svcTotal)}</span></div>`;
    }
    if (addTotal > 0) {
        lines += `<div class="amt-line"><span class="al">Add-ons × ${qty}</span><span class="av">₱${pf(addTotal)}</span></div>`;
    }
    if (hasPayRec && Math.abs(parseFloat(cur.payment_amount||0) - computed) > 0.01) {
        lines += `<div class="amt-line"><span class="al" style="font-size:.75rem;">Recorded payment</span><span class="av" style="color:${payStatus==='paid'?'#4ade80':'#fbbf24'};">₱${pf(cur.payment_amount)}</span></div>`;
    }

    const payNote = hasPayRec
        ? (payStatus === 'paid' ? 'Paid' : 'Unpaid')
        : 'Amount due · from booking';

    document.getElementById('mgrid').innerHTML = `
        <div class="di"><label>Reference</label><div class="dv mono">${ref}</div></div>
        <div class="di"><label>Current Status</label><div class="dv" id="curSt">${badgeHTML(cur.status)}</div></div>
        <div class="di"><label>Customer</label><div class="dv">${esc(cur.full_name)}</div></div>
        <div class="di"><label>Contact</label><div class="dv">${esc(cur.contact)}</div></div>
        <div class="di"><label>Service</label><div class="dv">${esc((cur.service_label||'')+': '+(cur.service_name||'N/A'))}</div></div>
        <div class="di"><label>Date &amp; Time</label><div class="dv">${fDate(cur.appointment_date)} · ${fTime(cur.appointment_time)}</div></div>
        <div class="di"><label>Helmet</label><div class="dv">${esc(cur.helmet_type||'—')}</div></div>
        <div class="di"><label>Qty</label><div class="dv">${qty}x helmet(s)</div></div>

        <!-- Full amount breakdown spanning both columns -->
        <div class="di" style="grid-column:1/-1;">
            <label>Payment</label>
            <div class="amt-breakdown">
                <div class="amt-breakdown-lines">${lines}</div>
                <div class="amt-total">
                    <span class="atl">Total · <span style="font-weight:500;font-size:.8rem;color:var(--gray-text);">${payNote}</span></span>
                    <span class="ata ${amtColor}">₱${pf(grandTotal)}</span>
                </div>
            </div>
        </div>`;

    document.getElementById('pendingAlert').style.display = fromScan ? 'flex' : 'none';
    syncBtns(cur.status);
    document.getElementById('statusModal').classList.add('open');
}
function closeModal() { document.getElementById('statusModal').classList.remove('open'); cur = null; }

function syncBtns(status) {
    document.querySelectorAll('.sf-btn').forEach(b => b.disabled = false);
    const a = document.querySelector(`.sf-btn[data-status="${status}"]`);
    if (a) a.disabled = true;
    const cb = document.getElementById('cancelAptBtn');
    if (cb) cb.disabled = (status === 'cancelled');
}

async function doUpdate(newStatus) {
    if (!cur) return;
    const fd = new FormData();
    fd.append('action', 'update_status'); fd.append('appointment_id', cur.id); fd.append('status', newStatus);
    try {
        const r = await fetch('appointments.php', { method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'} });
        const d = await r.json();
        if (d.success) {
            cur.status = newStatus;
            const idx = appointments.findIndex(a => a.id == cur.id);
            if (idx > -1) appointments[idx].status = newStatus;
            updateRowStatus(cur.id, newStatus);
            const cs = document.getElementById('curSt');
            if (cs) cs.innerHTML = badgeHTML(newStatus);
            document.getElementById('pendingAlert').style.display = 'none';
            syncBtns(newStatus);
            showToast('Status → ' + fStatus(newStatus), 'ts');
            applyFilters();
        } else { showToast(d.message || 'Update failed', 'te'); }
    } catch { showToast('Network error', 'te'); }
}

function updateRowStatus(id, status) {
    const row = document.querySelector(`tr[data-id="${id}"]`);
    if (!row) return;
    row.dataset.status = status;
    const sc = row.querySelector('td:nth-child(7)');
    if (sc) sc.innerHTML = badgeHTML(status);
}
function setFilter(f, el) {
    activeFilter = f;
    document.querySelectorAll('.fpill').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
    applyFilters();
}
function applyFilters() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    let v = 0;
    document.querySelectorAll('#tbody tr[data-id]').forEach(row => {
        const ms = activeFilter === 'all' || row.dataset.status === activeFilter;
        const mq = !q || row.textContent.toLowerCase().includes(q);
        row.style.display = (ms && mq) ? '' : 'none';
        if (ms && mq) v++;
    });
    document.getElementById('cntLabel').textContent = v + ' records';
    document.getElementById('footBar').textContent  = v + ' appointment(s) shown';
}
function showBanner(type, title, msg) {
    const ico = type === 'ok'
        ? `<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>`
        : `<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>`;
    document.getElementById('scanBanner').innerHTML =
        `<div class="s-banner ${type}"><div class="bico">${ico}</div><div class="btxt"><strong>${title}</strong><p>${msg}</p></div><button class="bdismiss" onclick="this.closest('.s-banner').remove()">Dismiss</button></div>`;
}
function showToast(msg, cls = 'ts') {
    clearTimeout(toastTimer);
    const t = document.getElementById('toast');
    document.getElementById('tmsg').textContent = msg;
    t.className = 'show ' + cls;
    toastTimer = setTimeout(() => { t.className = ''; }, 3500);
}
const SM = { pending:'Pending', in_progress:'In Progress', completed:'Completed', cancelled:'Cancelled' };
function fStatus(s) { return SM[s] || s; }
function cap(s)     { return s ? s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g,' ') : '—'; }
function fDate(d)   { return new Date(d).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); }
function fTime(t)   { if(!t) return '—'; const[h,m]=t.split(':'),hr=+h; return `${hr%12||12}:${m} ${hr>=12?'PM':'AM'}`; }
function pf(n)      { return parseFloat(n||0).toLocaleString('en-US',{minimumFractionDigits:2}); }
function esc(s)     { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
function badgeHTML(s){ return `<span class="badge badge-${s}">${fStatus(s)}</span>`; }
document.getElementById('qrConfirmModal').addEventListener('click', e => { if(e.target===e.currentTarget) closeQRModal(); });
document.getElementById('statusModal').addEventListener('click',    e => { if(e.target===e.currentTarget) closeModal(); });
document.getElementById('lockedModal').addEventListener('click',    e => { if(e.target===e.currentTarget) closeLockedModal(); });
document.addEventListener('keydown', e => { if (e.key==='Escape') { closeQRModal(); closeModal(); closeLockedModal(); } });
</script>
</body>
</html>