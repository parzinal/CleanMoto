<?php
require_once __DIR__ . '/config/config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    $role = getUserRole();
    if (!empty($role)) {
        redirect("$role/pages/dashboard.php");
    } else {
        // Invalid session, logout
        session_destroy();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Professional Helmet Cleaning Service</title>
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Landing Page Specific Styles */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 120px 20px 80px;
            position: relative;
            background: linear-gradient(135deg, rgba(13, 13, 13, 0.95) 0%, rgba(26, 26, 26, 0.9) 100%);
        }

        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('<?php echo APP_URL; ?>/assets/image/CleanMoto_Logo.png') center center no-repeat;
            background-size: 400px;
            opacity: 0.05;
            z-index: 0;
        }

        .hero-content {
            position: relative;
            z-index: 1;
            max-width: 800px;
        }

        .hero-badge {
            display: inline-block;
            background: rgba(230, 57, 70, 0.15);
            border: 1px solid var(--primary-red);
            color: var(--primary-red);
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            animation: fadeInUp 0.6s ease-out;
        }

        .section-title {
            font-size: 2.5rem;
            font-weight: 700;
            text-align: center;
            margin-bottom: 1rem;
            color: var(--cream);
        }

        .section-subtitle {
            text-align: center;
            color: var(--gray-text);
            max-width: 600px;
            margin: 0 auto 3rem;
            font-size: 1.1rem;
            line-height: 1.7;
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
        }

        .service-card {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
        }

        .service-card:hover {
            transform: translateY(-8px);
            border-color: var(--primary-red);
            box-shadow: 0 20px 40px rgba(230, 57, 70, 0.15);
        }

        .service-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary-red), #a61c28);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.5rem;
        }

        .service-card h3 {
            font-size: 1.4rem;
            margin-bottom: 0.75rem;
            color: var(--cream);
        }

        .service-card p {
            color: var(--gray-text);
            line-height: 1.6;
        }

        .booking-section {
            background: rgba(26, 26, 26, 0.6);
        }

        .booking-container {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 3rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        @media (max-width: 768px) {
            .booking-container {
                grid-template-columns: 1fr;
            }
        }

        .booking-form-card {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 2.5rem;
        }

        .booking-form-card h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--cream);
        }

        .booking-form-card .subtitle {
            color: var(--gray-text);
            margin-bottom: 2rem;
            font-size: 0.95rem;
        }

        .contact-card {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .contact-card h4 {
            font-size: 1.1rem;
            margin-bottom: 1rem;
            color: var(--cream);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border-color);
        }

        .contact-item:last-child {
            border-bottom: none;
        }

        .contact-item a {
            color: var(--primary-red);
            text-decoration: none;
            font-weight: 500;
            transition: opacity 0.3s;
        }

        .contact-item a:hover {
            opacity: 0.8;
        }

        .map-container {
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .map-container iframe {
            width: 100%;
            height: 450px;
            border: 0;
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
        }

        @media (max-width: 768px) {
            .gallery-grid {
                grid-template-columns: 1fr;
            }
        }

        .gallery-item {
            position: relative;
            border-radius: 16px;
            overflow: hidden;
            aspect-ratio: 4/3;
            cursor: pointer;
        }

        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .gallery-item:hover img {
            transform: scale(1.1);
        }

        .gallery-item .overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.9) 0%, transparent 100%);
            padding: 2rem 1.5rem 1.5rem;
        }

        .gallery-item h4 {
            color: var(--cream);
            font-size: 1.1rem;
            margin-bottom: 0.25rem;
        }

        .gallery-item p {
            color: var(--gray-text);
            font-size: 0.9rem;
            margin: 0;
        }

        .stats-section {
            background: linear-gradient(135deg, var(--primary-red), #a61c28);
            padding: 4rem 0;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 2rem;
            text-align: center;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .stat-item h3 {
            font-size: 3rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
        }

        .stat-item p {
            font-size: 1rem;
            opacity: 0.9;
        }

        .cta-section {
            text-align: center;
            padding: 5rem 0;
        }

        .cta-section h2 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .cta-section p {
            color: var(--gray-text);
            font-size: 1.1rem;
            margin-bottom: 2rem;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Theme Toggle Button Styles */
        .theme-toggle-btn {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            color: var(--cream);
            width: 44px;
            height: 44px;
            border-radius: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .theme-toggle-btn:hover {
            background: var(--dark-hover);
            border-color: var(--primary-red);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(230, 57, 70, 0.2);
        }

        .theme-toggle-btn svg {
            position: absolute;
            transition: all 0.3s ease;
        }

        .theme-toggle-btn .sun-icon {
            opacity: 0;
            transform: rotate(-90deg) scale(0.5);
        }

        .theme-toggle-btn .moon-icon {
            opacity: 1;
            transform: rotate(0deg) scale(1);
        }

        [data-theme="light"] .theme-toggle-btn .sun-icon {
            opacity: 1;
            transform: rotate(0deg) scale(1);
        }

        [data-theme="light"] .theme-toggle-btn .moon-icon {
            opacity: 0;
            transform: rotate(90deg) scale(0.5);
        }

        /* Light theme adjustments for landing page */
        [data-theme="light"] {
            --animated-bg-light: #FAFAFA;
        }

        [data-theme="light"] .animated-bg {
            background: var(--animated-bg-light);
        }

        [data-theme="light"] .hero {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.98) 0%, rgba(250, 250, 250, 0.95) 100%);
            border-bottom: 1px solid rgba(230, 57, 70, 0.08);
        }

        [data-theme="light"] .hero h1 {
            color: var(--text-primary);
        }

        [data-theme="light"] .hero p {
            color: var(--text-secondary);
        }

        [data-theme="light"] .navbar {
            background: linear-gradient(135deg, var(--primary-red), #a61c28);
            border-bottom: none;
            box-shadow: 0 4px 16px rgba(230, 57, 70, 0.25);
        }

        [data-theme="light"] .navbar .logo-text {
            color: white !important;
            background: none !important;
            -webkit-background-clip: unset !important;
            -webkit-text-fill-color: white !important;
            background-clip: unset !important;
        }

        [data-theme="light"] .navbar .nav-links a {
            color: white;
        }

        [data-theme="light"] .navbar .nav-links a:hover {
            color: rgba(255, 255, 255, 0.8);
        }

        [data-theme="light"] .navbar .btn-outline {
            border-color: white;
            color: white;
        }

        [data-theme="light"] .navbar .btn-outline:hover {
            background: white;
            color: var(--primary-red);
        }

        [data-theme="light"] .theme-toggle-btn {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.3);
            color: white;
        }

        [data-theme="light"] .theme-toggle-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            border-color: white;
        }

        /* Section separators and spacing */
        [data-theme="light"] .features {
            border-bottom: 1px solid rgba(230, 57, 70, 0.08);
            position: relative;
        }

        [data-theme="light"] .features::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--primary-red), transparent);
        }

        [data-theme="light"] .section-title {
            color: var(--text-primary);
            position: relative;
            padding-bottom: 1rem;
        }

        [data-theme="light"] .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 3px;
            background: var(--primary-red);
            border-radius: 2px;
        }

        [data-theme="light"] .section-subtitle {
            color: var(--text-secondary);
        }

        /* Modernized cards with better shadows */
        [data-theme="light"] .service-card {
            background: #FFFFFF;
            border: 1px solid rgba(230, 57, 70, 0.1);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
        }

        [data-theme="light"] .service-card:hover {
            box-shadow: 0 12px 32px rgba(230, 57, 70, 0.15);
            border-color: rgba(230, 57, 70, 0.3);
        }

        [data-theme="light"] .service-card h3 {
            color: var(--text-primary);
        }

        [data-theme="light"] .service-card p {
            color: var(--text-secondary);
        }

        /* Contact cards */
        [data-theme="light"] .contact-card {
            background: #FFFFFF;
            border: 1px solid rgba(230, 57, 70, 0.1);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
        }

        [data-theme="light"] .contact-card h4 {
            color: var(--text-primary);
        }

        [data-theme="light"] .contact-item {
            border-bottom-color: rgba(230, 57, 70, 0.08);
        }

        [data-theme="light"] .contact-item small {
            color: var(--text-secondary);
        }

        /* Gallery with better contrast */
        [data-theme="light"] .gallery-item {
            border: 1px solid rgba(230, 57, 70, 0.1);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
        }

        [data-theme="light"] .gallery-item:hover {
            box-shadow: 0 12px 32px rgba(230, 57, 70, 0.15);
        }

        [data-theme="light"] .gallery-item h4 {
            color: #F1FAEE;
        }

        [data-theme="light"] .gallery-item p {
            color: #A8A8A8;
        }

        /* Better button visibility */
        [data-theme="light"] .btn-outline {
            border-color: var(--primary-red);
            color: var(--primary-red);
            background: transparent;
        }

        [data-theme="light"] .btn-outline:hover {
            background: var(--primary-red);
            color: white;
        }

        /* Footer with better separation */
        [data-theme="light"] .footer {
            background: #FFFFFF;
            color: var(--text-primary);
            border-top: 1px solid rgba(230, 57, 70, 0.1);
            box-shadow: 0 -4px 16px rgba(0, 0, 0, 0.03);
        }

        [data-theme="light"] .footer p {
            color: var(--text-secondary);
        }

        [data-theme="light"] .footer-links a {
            color: var(--text-secondary);
        }

        [data-theme="light"] .footer-links a:hover {
            color: var(--primary-red);
        }

        /* Booking section with better background */
        [data-theme="light"] .booking-section {
            background: #F8F9FA;
            border-top: 1px solid rgba(230, 57, 70, 0.08);
            border-bottom: 1px solid rgba(230, 57, 70, 0.08);
        }

        /* Stats section remains red gradient */
        [data-theme="light"] .stats-section {
            background: linear-gradient(135deg, var(--primary-red), #a61c28);
            box-shadow: 0 8px 32px rgba(230, 57, 70, 0.25);
        }

        /* CTA section */
        [data-theme="light"] .cta-section {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.98), rgba(255, 248, 240, 0.95));
            border-top: 1px solid rgba(230, 57, 70, 0.08);
        }

        [data-theme="light"] .cta-section h2 {
            color: var(--text-primary);
        }

        [data-theme="light"] .cta-section p {
            color: var(--text-secondary);
        }

        /* Pricing badge visibility */
        [data-theme="light"] .service-card > div[style*="linear-gradient"] {
            box-shadow: 0 2px 8px rgba(230, 57, 70, 0.2);
        }

        /* Map container better shadow */
        [data-theme="light"] .map-container {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
        }

        /* Hero badge in light mode */
        [data-theme="light"] .hero-badge {
            background: rgba(230, 57, 70, 0.08);
            border-color: var(--primary-red);
        }

        /* Better section spacing */
        [data-theme="light"] section {
            position: relative;
        }

        /* Subtle animated background */
        [data-theme="light"] .animated-bg::before {
            background: radial-gradient(circle at 20% 50%, rgba(230, 57, 70, 0.03) 0%, transparent 50%);
        }

        [data-theme="light"] .animated-bg::after {
            background: radial-gradient(circle at 80% 50%, rgba(230, 57, 70, 0.03) 0%, transparent 50%);
        }
    </style>
</head>
<body>
    <div class="animated-bg"></div>

    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <a href="<?php echo APP_URL; ?>" class="logo">
                <img src="<?php echo APP_URL; ?>/assets/image/CleanMoto_Logo.png" alt="CleanMoto Logo">
                <span class="logo-text">CleanMoto</span>
            </a>
            <ul class="nav-links">
                <li><a href="#services">Services</a></li>
                <li><a href="#gallery">Gallery</a></li>
                <li><a href="#booking">Book Now</a></li>
                <li>
                    <button id="themeToggle" class="theme-toggle-btn" aria-label="Toggle theme">
                        <svg class="sun-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="5"></circle>
                            <line x1="12" y1="1" x2="12" y2="3"></line>
                            <line x1="12" y1="21" x2="12" y2="23"></line>
                            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                            <line x1="1" y1="12" x2="3" y2="12"></line>
                            <line x1="21" y1="12" x2="23" y2="12"></line>
                            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                        </svg>
                        <svg class="moon-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                        </svg>
                    </button>
                </li>
                <li><a href="<?php echo APP_URL; ?>/login.php" class="btn btn-outline">Login</a></li>
            </ul>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-content">
            <span class="hero-badge">🏍️ Professional Helmet Care</span>
            <h1 style="font-size: 3.5rem; font-weight: 800; margin-bottom: 1.5rem; line-height: 1.2;">
                Keep Your Helmet<br>
                <span style="background: var(--gradient-red); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Fresh & Clean</span>
            </h1>
            <p style="font-size: 1.25rem; color: var(--gray-text); margin-bottom: 2.5rem; max-width: 600px; margin-left: auto; margin-right: auto; line-height: 1.7;">
                Professional helmet cleaning and sanitization service in Imus, Cavite. Book an appointment today and give your helmet the care it deserves.
            </p>
            <div class="hero-buttons">
                <a href="<?php echo APP_URL; ?>/login.php" class="btn btn-primary" style="padding: 16px 32px; font-size: 1.1rem;">
                    Book Appointment
                </a>
                <a href="#services" class="btn btn-outline" style="padding: 16px 32px; font-size: 1.1rem;">
                    Our Services
                </a>
            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section class="features" id="services" style="padding: 5rem 0;">
        <div class="container">
            <h2 class="section-title">Our Services & Pricing</h2>
            <p class="section-subtitle">
                Professional helmet care services tailored to your needs. From quick cleaning to complete restoration.
            </p>
            
            <div style="max-width: 1100px; margin: 0 auto;">
                <!-- Cleaning Services -->
                <div style="margin-bottom: 3rem;">
                    <h3 style="font-size: 1.8rem; margin-bottom: 2rem; text-align: center; color: var(--primary-red);">Cleaning Packages</h3>
                    <div class="services-grid">
                        <div class="service-card">
                            <div style="background: linear-gradient(135deg, var(--primary-red), #a61c28); color: white; padding: 0.5rem 1rem; border-radius: 8px; display: inline-block; margin-bottom: 1rem; font-size: 0.85rem; font-weight: 600;">15 min</div>
                            <h3>X-1 Light Clean</h3>
                            <div style="font-size: 2rem; font-weight: 700; color: var(--primary-red); margin: 1rem 0;">₱50</div>
                            <p>Quick exterior cleaning for light dirt and dust removal.</p>
                        </div>
                        
                        <div class="service-card">
                            <div style="background: linear-gradient(135deg, var(--primary-red), #a61c28); color: white; padding: 0.5rem 1rem; border-radius: 8px; display: inline-block; margin-bottom: 1rem; font-size: 0.85rem; font-weight: 600;">20-25 min</div>
                            <h3>X-2 Standard Clean</h3>
                            <div style="font-size: 2rem; font-weight: 700; color: var(--primary-red); margin: 1rem 0;">₱100</div>
                            <p>Standard interior and exterior cleaning for regular maintenance.</p>
                        </div>
                        
                        <div class="service-card">
                            <div style="background: linear-gradient(135deg, var(--primary-red), #a61c28); color: white; padding: 0.5rem 1rem; border-radius: 8px; display: inline-block; margin-bottom: 1rem; font-size: 0.85rem; font-weight: 600;">30-40 min</div>
                            <h3>X-3 Interior Clean</h3>
                            <div style="font-size: 2rem; font-weight: 700; color: var(--primary-red); margin: 1rem 0;">₱120</div>
                            <p>Deep interior cleaning with sanitization and deodorizing.</p>
                        </div>
                        
                        <div class="service-card">
                            <div style="background: linear-gradient(135deg, var(--primary-red), #a61c28); color: white; padding: 0.5rem 1rem; border-radius: 8px; display: inline-block; margin-bottom: 1rem; font-size: 0.85rem; font-weight: 600;">45-60 min</div>
                            <h3>X-4 Full Combo</h3>
                            <div style="font-size: 2rem; font-weight: 700; color: var(--primary-red); margin: 1rem 0;">₱170</div>
                            <p>Complete interior and exterior cleaning combo package.</p>
                        </div>
                        
                        <div class="service-card">
                            <div style="background: linear-gradient(135deg, var(--primary-red), #a61c28); color: white; padding: 0.5rem 1rem; border-radius: 8px; display: inline-block; margin-bottom: 1rem; font-size: 0.85rem; font-weight: 600;">20-30 min</div>
                            <h3>X-5 Full Buffing</h3>
                            <div style="font-size: 2rem; font-weight: 700; color: var(--primary-red); margin: 1rem 0;">₱230</div>
                            <p>Professional shell buffing for scratch removal and shine restoration.</p>
                        </div>
                        
                        <div class="service-card" style="border-color: var(--primary-red);">
                            <div style="background: linear-gradient(135deg, var(--primary-red), #a61c28); color: white; padding: 0.5rem 1rem; border-radius: 8px; display: inline-block; margin-bottom: 1rem; font-size: 0.85rem; font-weight: 600;">1h 30min+</div>
                            <h3>X-6 Deep Cleaning + Buff</h3>
                            <div style="font-size: 2rem; font-weight: 700; color: var(--primary-red); margin: 1rem 0;">₱500</div>
                            <p>Ultimate deep cleaning with full buffing treatment.</p>
                        </div>
                        
                        <div class="service-card" style="border-color: var(--primary-red); background: linear-gradient(135deg, rgba(230, 57, 70, 0.05), rgba(166, 28, 40, 0.05));">
                            <div style="background: linear-gradient(135deg, var(--primary-red), #a61c28); color: white; padding: 0.5rem 1rem; border-radius: 8px; display: inline-block; margin-bottom: 1rem; font-size: 0.85rem; font-weight: 600;">⭐ PREMIUM</div>
                            <h3>X-7 Deep Clean + Ceramic</h3>
                            <div style="font-size: 2rem; font-weight: 700; color: var(--primary-red); margin: 1rem 0;">₱650</div>
                            <p>Deep cleaning with buffing and ceramic coating protection.</p>
                        </div>
                    </div>
                </div>
                
                <!-- Additional Services -->
                <div>
                    <h3 style="font-size: 1.8rem; margin-bottom: 2rem; text-align: center; color: var(--primary-red);">Additional Services</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">
                        <div class="service-card">
                            <h3>🔩 Rubber Lining + Metal</h3>
                            <div style="font-size: 1.8rem; font-weight: 700; color: var(--primary-red); margin: 0.75rem 0;">₱400</div>
                            <p style="color: var(--gray-text); font-size: 0.9rem;">30-40 minutes</p>
                        </div>
                        
                        <div class="service-card">
                            <h3>🧽 Refoaming</h3>
                            <div style="font-size: 1.8rem; font-weight: 700; color: var(--primary-red); margin: 0.75rem 0;">₱500-₱1000</div>
                            <p style="color: var(--gray-text); font-size: 0.9rem;">5-7 days turnaround</p>
                        </div>
                        
                        <div class="service-card">
                            <h3>🔧 Reglue</h3>
                            <div style="font-size: 1.8rem; font-weight: 700; color: var(--primary-red); margin: 0.75rem 0;">₱100</div>
                            <p style="color: var(--success); font-size: 0.9rem; font-weight: 600;">FREE with X-6 service</p>
                        </div>
                        
                        <div class="service-card">
                            <h3>👓 Visor Replacement</h3>
                            <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary-red); margin: 0.75rem 0;">Price Varies</div>
                            <p style="color: var(--gray-text); font-size: 0.9rem;">Depends on helmet model</p>
                        </div>
                        
                        <div class="service-card">
                            <h3>🎨 Repaint</h3>
                            <div style="font-size: 1.8rem; font-weight: 700; color: var(--primary-red); margin: 0.75rem 0;">₱2,500</div>
                            <p style="color: var(--gray-text); font-size: 0.9rem;">2-4 weeks completion</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Gallery Section -->
    <section class="features" id="gallery" style="padding: 5rem 0; background: transparent;">
        <div class="container">
            <h2 class="section-title">Our Work</h2>
            <p class="section-subtitle">
                See the difference our professional cleaning makes. Before and after transformations that speak for themselves.
            </p>
            
            <div class="gallery-grid">
                <div class="gallery-item">
                    <img src="<?php echo APP_URL; ?>/assets/image/helmet2.jpg" alt="Helmet Collection">
                    <div class="overlay">
                        <h4>Premium Collection</h4>
                        <p>Ready for cleaning</p>
                    </div>
                </div>
                
                <div class="gallery-item">
                    <img src="<?php echo APP_URL; ?>/assets/image/helmet1.jpg" alt="Cleaned Helmets">
                    <div class="overlay">
                        <h4>Fresh & Clean</h4>
                        <p>After our service</p>
                    </div>
                </div>
                
                <div class="gallery-item">
                    <img src="<?php echo APP_URL; ?>/assets/image/customer.jpg" alt="Happy Customer">
                    <div class="overlay">
                        <h4>Happy Riders</h4>
                        <p>Satisfied customers</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Booking Section -->
    <section class="features booking-section" id="booking" style="padding: 5rem 0;">
        <div class="container">
            <h2 class="section-title">Book Your Appointment</h2>
            <p class="section-subtitle">
                Schedule a helmet cleaning appointment today. We'll take care of the rest!
            </p>
            
            <div class="booking-container">
                <!-- Left column: Map -->
                <div>
                    <div class="contact-card">
                        <h4>📍 Location</h4>
                        <div class="map-container">
                            <iframe 
                                src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d30907.919765472894!2d120.9318558!3d14.4226792!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3397d25886a71127%3A0xe9e27c89c8c2208b!2sImus%2C%204103%20Cavite!5e0!3m2!1sen!2sph!4v1234567890" 
                                allowfullscreen="" 
                                loading="lazy">
                            </iframe>
                        </div>
                        <p style="color: var(--gray-text); margin-bottom: 1rem;">Imus, Cavite, Philippines</p>
                        <a href="https://www.google.com/maps/place/Imus,+4103+Cavite/@14.4226792,120.9318558,15z" target="_blank" class="btn btn-outline" style="width: 100%;">
                            Get Directions
                        </a>
                    </div>
                </div>

                <!-- Right column: Contact & Hours -->
                <div>
                    <div class="contact-card">
                        <h4>📞 Contact Us</h4>
                        <div class="contact-item">
                            <span>📱</span>
                            <div>
                                <small style="color: var(--gray-text);">Phone / WhatsApp</small><br>
                                <a href="tel:09956302553">0995 630 2553</a>
                            </div>
                        </div>
                        <div class="contact-item">
                            <span>✉️</span>
                            <div>
                                <small style="color: var(--gray-text);">Email</small><br>
                                <a href="mailto:xeroclaro.ph@gmail.com">xeroclaro.ph@gmail.com</a>
                            </div>
                        </div>
                        <div class="contact-item">
                            <span>📘</span>
                            <div>
                                <small style="color: var(--gray-text);">Facebook</small><br>
                                <a href="https://www.facebook.com/xeroclaro.official" target="_blank">@xeroclaro.official</a>
                            </div>
                        </div>
                    </div>

                    <div class="contact-card">
                        <h4>🕐 Business Hours</h4>
                        <div style="color: var(--gray-text); line-height: 1.8;">
                            <div style="display: flex; justify-content: space-between;">
                                <span>Tuesday - Sunday</span>
                                <span style="color: var(--cream);">10:00 AM - 8:00 PM</span>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span>Monday</span>
                                <span style="color: var(--primary-red);">Closed</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section">
        <div class="container">
            <h2>Ready for a Fresh Helmet?</h2>
            <p>Book your helmet cleaning appointment today and ride with confidence!</p>
            <a href="<?php echo APP_URL; ?>/login.php" class="btn btn-primary" style="padding: 16px 40px; font-size: 1.1rem;">
                Book Now
            </a>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content" style="text-align:center;">
                <img src="<?php echo APP_URL; ?>/assets/image/CleanMoto_Logo.png" alt="CleanMoto" style="height: 64px; margin-bottom: 0.75rem; display:block; margin-left:auto; margin-right:auto;">
                <div style="margin-top:0.5rem; margin-bottom:0.75rem;">
                    <span class="logo-text" style="display:block; font-size:1.25rem; font-weight:700;">CleanMoto</span>
                    <p style="margin:0.5rem 0 0; color:var(--gray-text);">Professional Helmet Cleaning Service</p>
                </div>

                <div class="footer-links" style="margin:1rem 0; display:flex; gap:1.25rem; justify-content:center;">
                    <a href="https://www.facebook.com/xeroclaro.official" target="_blank">Facebook</a>
                    <a href="mailto:xeroclaro.ph@gmail.com">Email</a>
                    <a href="tel:09956302553">Call Us</a>
                </div>

                <p style="margin-top:1rem; color:var(--gray-text);">&copy; <?php echo date('Y'); ?> CleanMoto. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script>
        // Theme Toggle Functionality
        const themeToggle = document.getElementById('themeToggle');
        const htmlElement = document.documentElement;
        
        // Check for saved theme preference or default to 'dark'
        const currentTheme = localStorage.getItem('theme') || 'dark';
        htmlElement.setAttribute('data-theme', currentTheme);
        
        themeToggle.addEventListener('click', () => {
            const currentTheme = htmlElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            htmlElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
        });

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    </script>
</body>
</html>
