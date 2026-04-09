<?php
require_once '../dp.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HMS — Premium Student Accommodation in KTM</title>
    <link rel="stylesheet" href="../css/landing.css">
</head>
<body>

    <!-- NAVIGATION -->
    <nav class="nav-container" id="navbar">
        <div class="logo">HMS STUDENT PORTAL</div>
        <div class="nav-links">
            <a href="#about">Facilities</a>
            <a href="#activities">Student Life</a>
            <a href="#faq">FAQ</a>
            <a href="../login.php" class="btn-login">Login</a>
            <a href="#enquire" class="btn-enquire">Enquire about Fees</a>
        </div>
    </nav>

    <!-- HERO SECTION -->
    <header class="hero">
        <div class="hero-content">
            <p class="hero-subtitle">Established for Academic Excellence</p>
            <h1 class="hero-title">Your home for success in Kathmandu.</h1>
            <p class="hero-desc">Experience a safe, quiet, and premium living environment designed specifically for dedicated students and young professionals. Focus on your studies while we handle the rest.</p>
            <div class="hero-btns">
                <a href="#about" class="hero-btn hero-btn-primary">GET STARTED</a>
            </div>
        </div>
    </header>

    <!-- ABOUT / FACILITIES -->
    <section class="section" id="about">
        <div class="section-header">
            <p style="color: var(--primary); font-weight: 700; margin-bottom: 0.5rem;">FACILITIES OVERVIEW</p>
            <h2 class="section-title">Built for Focus, Safety, and Comfort</h2>
            <div class="section-line"></div>
        </div>
        <div style="max-width: 900px; margin: 0 auto; text-align: center; color: #475569;">
            <p>Dedicated study zones, healthy meal planning, and round-the-clock supervision. Everything is structured to support academic goals while maintaining a welcoming, home-like environment.</p>
        </div>
    </section>

    <!-- STUDENT LIFE SECTION -->
    <section class="section" id="activities">
        <div class="section-header">
            <p style="color: var(--primary); font-weight: 700; margin-bottom: 0.5rem;">STUDENT LIFE AT HMS</p>
            <h2 class="section-title">Designed for Achievement</h2>
            <div class="section-line"></div>
        </div>

        <div class="activity-grid">
            <div class="activity-card">
                <img src="../assets/img/student_study.png" class="activity-img" alt="Study Group">
                <div class="activity-body">
                    <h3>Focus & Study Groups</h3>
                    <p>Dedicated high-speed WiFi and quiet study zones to ensure you excel in your academic journey.</p>
                    <a href="#" style="color: var(--primary); font-weight: 700; text-decoration: none;">VIEW FACILITIES →</a>
                </div>
            </div>

            <div class="activity-card">
                <img src="https://images.unsplash.com/photo-1547573854-74d2a71d0826?auto=format&fit=crop&q=80&w=600" class="activity-img" alt="Healthy Meals">
                <div class="activity-body">
                    <h3>Nutritious Home Meals</h3>
                    <p>Balanced, hygienic, and brain-boosting meals served three times a day to keep you healthy and energized.</p>
                    <a href="#" style="color: var(--primary); font-weight: 700; text-decoration: none;">MENU DETAILS →</a>
                </div>
            </div>

            <div class="activity-card">
                <img src="../assets/img/student_room.png" class="activity-img" alt="Modern Room">
                <div class="activity-body">
                    <h3>Modern Living Spaces</h3>
                    <p>Spacious, clean, and well-furnished twin-sharing or private rooms with ergonomic study desks.</p>
                    <a href="#" style="color: var(--primary); font-weight: 700; text-decoration: none;">ROOM TOUR →</a>
                </div>
            </div>

            <div class="activity-card">
                <img src="https://images.unsplash.com/photo-1558002038-1055907df827?auto=format&fit=crop&q=80&w=600" class="activity-img" alt="Security">
                <div class="activity-body">
                    <h3>24/7 Safety & Security</h3>
                    <p>Full CCTV coverage, biometric entry, and resident warden to ensure a safe and secure environment for all students.</p>
                    <a href="#" style="color: var(--primary); font-weight: 700; text-decoration: none;">LEARN MORE →</a>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ & NEWSLETTER SECTION -->
    <section class="section" id="faq" style="background: #f1f5f9;">
        <div style="max-width: 800px; margin: 0 auto; text-align: center;">
            <h2 class="section-title">Common Questions</h2>
            <p style="margin-bottom: 3rem;">Everything parents and students need to know before joining.</p>

            <div style="display: grid; gap: 1.5rem; text-align: left;">
                <div style="background: #fff; padding: 2rem; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                    <h4 style="font-weight: 800; margin-bottom: 0.5rem;">What is the curfew time for students?</h4>
                    <p style="color: #64748b;">For safety, the main gates close at 8:00 PM. Exceptions are made for college classes.</p>
                </div>
                <div style="background: #fff; padding: 2rem; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                    <h4 style="font-weight: 800; margin-bottom: 0.5rem;">Is laundry service included in the fees?</h4>
                    <p style="color: #64748b;">Yes, laundry services are provided twice a week at no extra cost.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ENQUIRY SECTION -->
    <section class="section" id="enquire">
        <div class="section-header">
            <h2 class="section-title">Schedule a Visit / General Enquiry</h2>
            <p>Tell us what you're looking for, and our team will get back to you shortly.</p>
        </div>
        <div class="enquiry-card">
            <form id="enquiry-form" class="enquiry-form">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div class="form-field">
                    <label class="form-label">Full Name *</label>
                    <input type="text" name="name" class="form-input" placeholder="Enter your full name" required>
                </div>
                <div class="form-field">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-input" placeholder="you@example.com">
                </div>
                <div class="form-field">
                    <label class="form-label">Your Message *</label>
                    <textarea name="message" class="form-textarea" rows="4" placeholder="Tell us how we can help" required></textarea>
                </div>
                <button type="submit" class="hero-btn hero-btn-primary enquiry-submit">SUBMIT ENQUIRY</button>
            </form>
            <div id="enquiry-success" class="form-status success" style="display: none;">
                ✓ Enquiry submitted successfully! Our team will contact you soon.
            </div>
            <div id="enquiry-error" class="form-status error" style="display: none;">
                ❌ Error submitting enquiry. Please try again later.
            </div>
        </div>
    </section>

    <footer style="background: #0f172a; color: #fff; padding: 4rem 10%; text-align: center;">
        <h2 style="font-size: 2rem; margin-bottom: 1rem;">HMS STUDENT ACCOMMODATION</h2>
        <p style="color: #94a3b8; margin-bottom: 2rem;">Thamel, Kathmandu, Nepal | Providing Premium Student Housing Since 1994</p>
        <p style="font-size: 0.8rem; color: #475569;">&copy; 2024 HMS KTM Student Management System. All rights reserved.</p>
    </footer>

    <script>
        // Navbar scroll effect
        const nav = document.getElementById('navbar');
        window.onscroll = () => {
            if (window.scrollY > 50) nav.classList.add('scrolled');
            else nav.classList.remove('scrolled');
        };

        // Enquiry Form Script
        const enqForm = document.getElementById('enquiry-form');
        const enqSuccess = document.getElementById('enquiry-success');
        const enqError = document.getElementById('enquiry-error');
        if (enqForm) {
            enqForm.onsubmit = async (e) => {
                e.preventDefault();
                const data = new FormData(enqForm);
                try {
                    const res = await fetch('../api/submit_enquiry.php', { method: 'POST', body: data });
                    const result = await res.json();
                    if (res.ok && result.success) {
                        enqForm.style.display = 'none';
                        enqSuccess.style.display = 'block';
                        enqError.style.display = 'none';
                    } else {
                        enqError.style.display = 'block';
                    }
                } catch (err) {
                    enqError.style.display = 'block';
                }
            };
        }

    </script>
</body>
</html>
