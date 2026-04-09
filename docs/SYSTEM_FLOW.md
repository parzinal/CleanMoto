# CleanMoto — System Flow & Feature Overview

## Overview
This document describes the high-level flow and main features of the CleanMoto application for each role: **admin**, **staff**, and **user**. It also maps key files, APIs, database tables, and common troubleshooting items.

---

## Architecture & Routing
- PHP app served from `APP_URL` (configured in `config/config.php`).
- Role-specific pages live under `/admin/pages/`, `/staff/pages/`, `/user/pages/`.
- Shared UI components are in `includes/shared/` (e.g., `header.php`, `sidebar.php`).
- Sidebar builds links using `APP_URL` + `/<role>/pages/<file>.php`.
- `redirect($url)` helper in `config/config.php` builds absolute redirect URLs.

---

## Authentication & Session
- Authentication check helper functions: `isLoggedIn()`, `getUserRole()`, `isAdmin()`, `isStaff()`, `isUser()` in `config/config.php`.
- Login/logout flow uses `auth.php` to set `$_SESSION['user_id']`, `$_SESSION['user_role']`, `$_SESSION['user_name']`, `$_SESSION['user_avatar']`, etc.
- Protected pages require `isLoggedIn()` and role-specific checks (redirect to login on failure).

---

## Shared Features (All Roles)
- Topbar and Sidebar: `includes/shared/header.php`, `includes/shared/sidebar.php`.
- Notifications dropdown: pulls from `notifications` table or falls back to appointment-based summaries.
- Profile and avatar support: avatars stored as relative paths (e.g., `assets/uploads/avatars/...`) and rendered as absolute URLs using `APP_URL`.
- Activity logging: `activity_logs` table used for important user actions.

---

## Admin Features
Primary pages: `admin/pages/dashboard.php`, `admin/pages/services.php`, `admin/pages/user-management.php`, `admin/pages/appointments.php`, etc.

- Dashboard: system overview, recent activity, aggregates.
- Services management: create/update/delete services, image upload stored in `assets/image/services/`.
- User management: create staff users, set roles, change status.
- Appointments: view all appointments, change status, manage bookings.
- Notifications: view and manage notifications targeted at `admin` role.
- Database migrations: pages may create tables if missing (defensive CREATE TABLE IF NOT EXISTS usage seen in files).

---

## Staff Features
Primary pages: `staff/pages/dashboard.php`, `staff/pages/scanner.php`, `staff/pages/appointments.php`, `staff/pages/walkin-calendar.php`, `staff/pages/profile.php`.

- Dashboard: staff-specific overview of check-ins and pending items.
- QR Scanner: `scanner.php` — scans QR codes to lookup appointment and update status (AJAX POST `action=lookup` and `action=update_status`).
- Appointments: view and update appointment statuses; creating user notifications when statuses change.
- Walk-in Calendar: create walk-in appointments (user_id = NULL), checks availability before insert.
- Profile: update profile, upload avatar saved to `assets/uploads/avatars/` and session updated.

---

## User Features
Primary pages: `user/pages/dashboard.php`, `user/pages/appointment.php`, `user/pages/my-appointments.php`, `user/pages/profile.php`.

- Appointment booking: users can book appointments, select services/addons, and receive QR code for booking.
- My Appointments: view personal bookings and statuses.
- Profile management: update personal data and avatar.
- Notifications: receive user-targeted notifications (stored in `notifications` table with `user_id`).

---

## APIs
- `api/notifications.php` — used by the header to fetch and mark notifications. Uses `APP_URL` to build the fetch URL.
- Some pages expose AJAX endpoints via their own PHP files (e.g., `staff/pages/appointments.php` and `staff/pages/scanner.php` handle `X-Requested-With` AJAX POSTs for status updates/lookup).

---

## Database (key tables)
- `users` — id, name, email, password, role, avatar, status
- `services` — id, label, name, price, duration, image, status
- `appointments` — id, user_id (nullable for walk-ins), service_id, appointment_date, appointment_time, full_name, contact, addons (JSON), status, created_at
- `notifications` — id, user_id (nullable), role (nullable), type, title, body, url, is_read, created_at
- `addons` — id, name, price, description, status
- `activity_logs` — user_id, action, description, ip_address, user_agent

---

## Assets & Uploads
- Service images: `assets/image/services/`
- App logo: `assets/image/CleanMoto_Logo.png`
- Avatars uploaded to: `assets/uploads/avatars/`

Rendering rules:
- Template code should build absolute asset URLs using `APP_URL` (e.g., `APP_URL . '/assets/uploads/avatars/...'`) to ensure correct loading from nested pages.

---

## Navigation / File Mapping
- Sidebar (shared) generates links for each role: e.g., `APP_URL` + `/staff/pages/scanner.php`.
- Header uses `APP_URL . '/assets/image/CleanMoto_Logo.png'` for the brand logo.
- Profile links in dropdown are relative (e.g., `../pages/profile.php`), header scripts use `APP_URL` for API calls (e.g., `APP_URL + '/api/notifications.php'`).

---

## Common Troubleshooting
- Avatar image not displaying:
  - Confirm `$_SESSION['user_avatar']` contains a relative path like `assets/uploads/avatars/filename.jpg`.
  - Confirm file exists at project path `assets/uploads/avatars/filename.jpg`.
  - Ensure templates build absolute URLs using `APP_URL` (header and sidebar now use helpers to do this).
- Redirects creating malformed URLs (e.g., `http://localhost/xero_cloroadmin/...`) — solved by `redirect()` change in `config/config.php` to insert exactly one slash.
- Missing tables: many pages defensively attempt `CREATE TABLE IF NOT EXISTS`; run `database/schema.sql` or run the seeder scripts in `database/` if DB empty.

---

## Deployment & Local Testing Tips
- Ensure `APP_URL` in `config/config.php` matches your local base URL (e.g., `http://localhost/xero_claro`).
- Use Laragon or local Apache/PHP to serve the project root as `http://localhost/xero_claro`.
- File permissions: ensure PHP can write to `assets/uploads/avatars/` and `assets/image/services/` when uploading.
- Hard refresh (Ctrl+F5) after changing header/sidebar templates to clear cached assets.

---

## Next Steps / Optional Enhancements
- Add architecture diagram (Mermaid) to this doc to visualize role flows.
- Add an endpoint map (table) with each API route, method, params, and sample responses.
- Add automated integration tests for critical flows (login, booking, scanner flow).

---

If you want, I can:
- Add a Mermaid sequence/flow diagram to this file,
- Expand the APIs section with exact parameter lists and example responses,
- Or scan the codebase and insert direct links to each key file under the relevant feature sections.



<!-- EOF -->