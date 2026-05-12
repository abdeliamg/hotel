<?php
require_once __DIR__ . '/check.php';
require_once __DIR__ . '/includes/root_nav.php';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نظام إدارة الفنادق</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #2563eb;
            --secondary-color: #7c3aed;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --dark-color: #1e293b;
            --light-bg: #f8fafc;
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Tajawal', 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        /* Animated Background */
        body::before {
            content: '';
            position: absolute;
            background: url('data:image/svg+xml,%3Csvg width="60" height="60" viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg"%3E%3Cg fill="none" fill-rule="evenodd"%3E%3Cg fill="%23ffffff" fill-opacity="0.05"%3E%3Cpath d="M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z"/%3E%3C/g%3E%3C/g%3E%3C/svg%3E');
            animation: drift 20s infinite linear;
        }

        @keyframes drift {
            from { transform: translate(0, 0); }
            to { transform: translate(-60px, -60px); }
        }

        /* Navbar Styling */
        .navbar {
            background: rgba(30, 41, 59, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            padding: 20px 0;
            position: relative;
            z-index: 1000;
        }

        .navbar-brand {
            font-size: 28px;
            font-weight: 700;
            color: #fff !important;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
        }

        .navbar-brand:hover {
            transform: translateY(-2px);
        }

        .navbar-brand i {
            font-size: 32px;
            color: #fbbf24;
        }

        /* Main Container */
        .main-container {
            max-width: 1200px;
            margin: 50px auto;
            padding: 0 20px;
            position: relative;
            z-index: 10;
        }

        /* Hero Section */
        .hero-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 60px 40px;
            box-shadow: var(--card-shadow);
            text-align: center;
            margin-bottom: 50px;
            position: relative;
            overflow: hidden;
        }

        .hero-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.1) 0%, transparent 70%);
            animation: pulse 4s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(0.8); opacity: 0.5; }
            50% { transform: scale(1.2); opacity: 0.8; }
        }

        .hero-title {
            font-size: 48px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }

        .hero-subtitle {
            font-size: 20px;
            color: #64748b;
            font-weight: 400;
            position: relative;
            z-index: 1;
        }

        /* Cards Section */
        .cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 50px;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 40px 30px;
            text-align: center;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, transparent 0%, rgba(255, 255, 255, 0.5) 100%);
            transform: translateX(-100%) translateY(-100%);
            transition: transform 0.6s ease;
        }

        .feature-card:hover::before {
            transform: translateX(0) translateY(0);
        }

        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .feature-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 20px;
            font-size: 36px;
            color: #fff;
            position: relative;
            z-index: 1;
        }

        .icon-primary { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .icon-success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .icon-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .icon-info { background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); }

        .feature-title {
            font-size: 24px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 15px;
            position: relative;
            z-index: 1;
        }

        .feature-description {
            font-size: 16px;
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 25px;
            position: relative;
            z-index: 1;
        }

        .feature-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 30px;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 500;
            text-decoration: none;
            color: #fff;
            transition: all 0.3s ease;
            position: relative;
            z-index: 1;
            overflow: hidden;
        }

        .feature-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .feature-btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .feature-btn:hover {
            transform: translateX(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }

        .btn-primary-custom { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .btn-success-custom { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .btn-warning-custom { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .btn-info-custom { background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); }

        /* Reports Section */
        .reports-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 50px 40px;
            box-shadow: var(--card-shadow);
            margin-bottom: 50px;
        }

        .reports-title {
            font-size: 36px;
            font-weight: 700;
            text-align: center;
            margin-bottom: 40px;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .report-card {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .report-card:hover {
            transform: translateY(-5px);
            border-color: #7c3aed;
            box-shadow: 0 10px 25px rgba(124, 58, 237, 0.15);
        }

        .report-icon {
            font-size: 32px;
            color: #7c3aed;
            margin-bottom: 15px;
        }

        .report-link {
            color: #1e293b;
            text-decoration: none;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s ease;
        }

        .report-link:hover {
            color: #7c3aed;
        }

        /* Footer */
        .footer {
            text-align: center;
            padding: 30px 0;
            color: rgba(255, 255, 255, 0.8);
            font-size: 14px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .hero-title { font-size: 36px; }
            .hero-card { padding: 40px 20px; }
            .feature-card { padding: 30px 20px; }
            .reports-section { padding: 40px 20px; }
            .cards-container { gap: 20px; }
        }

        /* Loading Animation */
        .fade-in {
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <div class="container-fluid pt-2">
        <?php render_root_navbar('home'); ?>
    </div>

    

    <!-- Main Container -->
    <div class="main-container">
        
        <!-- Hero Section -->
        <div class="hero-card fade-in">
            <h1 class="hero-title">مرحباً بك في نظام إدارة الفنادق</h1>
            <p class="hero-subtitle">نظام متكامل لإدارة جميع عمليات الفندق بكفاءة وسهولة</p>
        </div>

        <!-- Main Cards -->
        <div class="cards-container">
            <!-- Hotels Card -->
            <div class="feature-card fade-in" style="animation-delay: 0.1s;">
                <div class="feature-icon icon-primary">
                    <i class="bi bi-building"></i>
                </div>
                <h3 class="feature-title">إدارة الفنادق</h3>
                <p class="feature-description">قم بإدارة جميع فنادقك من مكان واحد، مع إمكانية إضافة وتعديل وحذف بيانات الفنادق بسهولة</p>
                <a href="hotel.php" class="feature-btn btn-primary-custom" target="_blank">
                    الذهاب إلى الفنادق
                    <i class="bi bi-arrow-left"></i>
                </a>
            </div>

            <!-- Rooms Card -->
            <div class="feature-card fade-in" style="animation-delay: 0.2s;">
                <div class="feature-icon icon-success">
                    <i class="bi bi-door-closed"></i>
                </div>
                <h3 class="feature-title">إدارة الغرف</h3>
                <p class="feature-description">تحكم كامل في غرف الفندق، مع إمكانية تحديد الأسعار والمواصفات وحالة الإشغال</p>
                <a href="room.php" class="feature-btn btn-success-custom" target="_blank">
                    الذهاب إلى الغرف
                    <i class="bi bi-arrow-left"></i>
                </a>
            </div>

            <!-- Reservations Card -->
            <div class="feature-card fade-in" style="animation-delay: 0.3s;">
                <div class="feature-icon icon-warning">
                    <i class="bi bi-calendar-check"></i>
                </div>
                <h3 class="feature-title">إدارة الحجوزات</h3>
                <p class="feature-description">نظام حجوزات متطور يساعدك على تتبع جميع الحجوزات وإدارتها بفعالية</p>
                <a href="res.php" class="feature-btn btn-warning-custom" target="_blank">
                    الذهاب إلى الحجوزات
                    <i class="bi bi-arrow-left"></i>
                </a>
            </div>

            <!-- Pilgrim Departure Card -->
            <div class="feature-card fade-in" style="animation-delay: 0.4s;">
                <div class="feature-icon icon-info">
                    <i class="bi bi-send-check"></i>
                </div>
                <h3 class="feature-title">ترحيل الحجاج</h3>
                <p class="feature-description">قم بترحيل الحجاج فردياً أو حسب المجموعة أو حسب الفندق وتاريخ نهاية الحجز مع مراجعة الأسماء قبل التأكيد</p>
                <a href="pilgrim_flight.php" class="feature-btn btn-info-custom" target="_blank">
                    الذهاب إلى ترحيل الحجاج
                    <i class="bi bi-arrow-left"></i>
                </a>
            </div>
        </div>

        <!-- Reports Section -->
        <div class="reports-section fade-in" style="animation-delay: 0.4s;">
            <h2 class="reports-title">
                <i class="bi bi-graph-up"></i>
                التقارير والإحصائيات
            </h2>
            <div class="reports-grid">
            <div class="report-card">
                    <i class="bi bi-layers report-icon"></i>
                    <a href="hotel_floor.php" class="report-link" target="_blank">
                        تقرير حجز الطوابق
                        <i class="bi bi-box-arrow-up-left"></i>
                    </a>
                </div>
                <div class="report-card">
                    <i class="bi bi-layers report-icon"></i>
                    <a href="/hotel_pilgrim/hotel_all_pilgrim.php" class="report-link" target="_blank">
                            تقرير توزيع الغرف على الحجاج
                        <i class="bi bi-box-arrow-up-left"></i>
                    </a>
                </div>
                <div class="report-card">
                    <i class="bi bi-door-open report-icon"></i>
                    <a href="hotel_room.php" class="report-link" target="_blank">
                        تقرير حجز الغرف
                        <i class="bi bi-box-arrow-up-left"></i>
                    </a>
                </div>
                <div class="report-card">
                    <i class="bi bi-people report-icon"></i>
                    <a href="hotel_mg.php" class="report-link" target="_blank">
                        تقرير حجز التكتلات
                        <i class="bi bi-box-arrow-up-left"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>© 2024 نظام إدارة الفنادق. جميع الحقوق محفوظة.</p>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        // Add interactive effects
        document.addEventListener('DOMContentLoaded', function() {
            // Smooth scroll for anchor links
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth' });
                    }
                });
            });

            // Add ripple effect on cards
            document.querySelectorAll('.feature-card, .report-card').forEach(card => {
                card.addEventListener('click', function(e) {
                    const ripple = document.createElement('span');
                    ripple.classList.add('ripple');
                    this.appendChild(ripple);

                    const rect = this.getBoundingClientRect();
                    const size = Math.max(rect.width, rect.height);
                    const x = e.clientX - rect.left - size / 2;
                    const y = e.clientY - rect.top - size / 2;

                    ripple.style.width = ripple.style.height = size + 'px';
                    ripple.style.left = x + 'px';
                    ripple.style.top = y + 'px';

                    setTimeout(() => ripple.remove(), 600);
                });
            });
        });
    </script>

    <style>
        /* Ripple Effect */
        .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            transform: scale(0);
            animation: ripple 0.6s ease-out;
            pointer-events: none;
        }

        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }
    </style>
</body>
</html>