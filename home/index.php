<?php
require_once '../db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HMS - Premium Student Accommodation in Kathmandu</title>
    <link rel="stylesheet" href="../css/landing.css">
</head>
<body>

<!-- NAVIGATION -->
<nav class="nav-container" id="navbar">
    <a href="#" class="logo">HMS</a>
    <button class="nav-toggle" id="nav-toggle" aria-label="Menu">
        <span></span><span></span><span></span>
    </button>
    <div class="nav-links" id="nav-links">
        <a href="#facilities">Facilities</a>
        <a href="#menu">Menu</a>
        <a href="#rooms">Rooms</a>
        <a href="#faq">FAQ</a>
        <a href="../auth/login.php" class="btn-login">Login</a>
        <a href="#enquire" class="btn-enquire">Enquire</a>
    </div>
</nav>

<!-- HERO -->
<header class="hero">
    <div class="hero-content">
        <p class="hero-subtitle">Established for Academic Excellence</p>
        <h1 class="hero-title">Your home for<br>success in<br>Kathmandu.</h1>
        <p class="hero-desc">A safe, quiet, and premium living environment designed for dedicated students. Focus on your studies - we handle the rest.</p>
        <div class="hero-btns">
            <a href="#rooms" class="hero-btn hero-btn-primary">View Rooms</a>
            <a href="#enquire" class="hero-btn hero-btn-secondary">Schedule a Visit</a>
        </div>
    </div>
    <div class="hero-stats">
        <div class="hero-stat"><span class="hero-stat-num">200+</span><span class="hero-stat-lbl">Students</span></div>
        <div class="hero-stat-sep"></div>
        <div class="hero-stat"><span class="hero-stat-num">3</span><span class="hero-stat-lbl">Meals/day</span></div>
        <div class="hero-stat-sep"></div>
        <div class="hero-stat"><span class="hero-stat-num">24/7</span><span class="hero-stat-lbl">Security</span></div>
        <div class="hero-stat-sep"></div>
        <div class="hero-stat"><span class="hero-stat-num">100Mbps</span><span class="hero-stat-lbl">WiFi</span></div>
    </div>
</header>

<!-- STUDENT LIFE CARDS -->
<section class="section" id="about">
    <div class="section-header">
        <p class="section-kicker">STUDENT LIFE AT HMS</p>
        <h2 class="section-title">Designed for Achievement</h2>
        <div class="section-line"></div>
    </div>
    <div class="activity-grid">
        <div class="activity-card">
            <img src="https://images.unsplash.com/photo-1522202176988-66273c2fd55f?auto=format&fit=crop&q=80&w=600" class="activity-img" alt="Study Groups">
            <div class="activity-body">
                <h3>Focus &amp; Study Zones</h3>
                <p>Dedicated high-speed WiFi and quiet study zones to ensure you excel in your academic journey.</p>
                <a href="#facilities" class="activity-link">VIEW FACILITIES &rarr;</a>
            </div>
        </div>
        <div class="activity-card">
            <img src="https://images.unsplash.com/photo-1547573854-74d2a71d0826?auto=format&fit=crop&q=80&w=600" class="activity-img" alt="Healthy Meals">
            <div class="activity-body">
                <h3>Nutritious Home Meals</h3>
                <p>Balanced, hygienic, and brain-boosting meals served three times a day to keep you healthy and energized.</p>
                <a href="#menu" class="activity-link">MENU DETAILS &rarr;</a>
            </div>
        </div>
        <div class="activity-card">
            <img src="https://images.unsplash.com/photo-1555854877-bab0e564b8d5?auto=format&fit=crop&q=80&w=600" class="activity-img" alt="Modern Room">
            <div class="activity-body">
                <h3>Modern Living Spaces</h3>
                <p>Spacious, clean, and well-furnished single, double, or triple rooms with ergonomic study desks.</p>
                <a href="#rooms" class="activity-link">ROOM TOUR &rarr;</a>
            </div>
        </div>
        <div class="activity-card">
            <img src="https://images.unsplash.com/photo-1558002038-1055907df827?auto=format&fit=crop&q=80&w=600" class="activity-img" alt="Security">
            <div class="activity-body">
                <h3>24/7 Safety &amp; Security</h3>
                <p>Full CCTV coverage, biometric entry, and resident warden to ensure a safe and secure environment.</p>
                <a href="#security" class="activity-link">LEARN MORE &rarr;</a>
            </div>
        </div>
    </div>
</section>

<!-- FACILITIES SECTION -->
<section class="section section-alt" id="facilities">
    <div class="section-header">
        <p class="section-kicker">WHAT WE OFFER</p>
        <h2 class="section-title">World-Class Facilities</h2>
        <div class="section-line"></div>
    </div>
    <div class="facility-grid">
        <div class="facility-card">
            <div class="facility-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><circle cx="12" cy="20" r="1" fill="currentColor"/></svg>
            </div>
            <h4>100 Mbps WiFi</h4>
            <p>Fiber internet across all floors with no data cap. Individual access points on every floor for stable connectivity.</p>
        </div>
        <div class="facility-card">
            <div class="facility-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
            </div>
            <h4>Quiet Study Zones</h4>
            <p>Designated silent study areas on every floor, open 24 hours with individual desk lamps and power outlets.</p>
        </div>
        <div class="facility-card">
            <div class="facility-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
            </div>
            <h4>Laundry Service</h4>
            <p>Complimentary laundry twice a week - Mondays and Thursdays. Drop bags at the canteen counter, pick up next day.</p>
        </div>
        <div class="facility-card">
            <div class="facility-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
            </div>
            <h4>Canteen All Day</h4>
            <p>Hot meals served three times daily. Snacks, tea, and coffee available throughout the day at the canteen.</p>
        </div>
        <div class="facility-card">
            <div class="facility-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
            </div>
            <h4>Furnished Rooms</h4>
            <p>Every room includes a bed, mattress, wardrobe, ergonomic study chair, reading lamp, and storage shelves.</p>
        </div>
        <div class="facility-card">
            <div class="facility-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
            </div>
            <h4>Power Backup</h4>
            <p>24-hour generator backup ensures zero downtime. Study without interruption during load-shedding hours.</p>
        </div>
    </div>
</section>

<!-- MENU SECTION -->
<section class="section" id="menu">
    <div class="section-header">
        <p class="section-kicker">DINING AT HMS</p>
        <h2 class="section-title">Fresh, Nutritious Meals Every Day</h2>
        <div class="section-line"></div>
    </div>
    <p class="section-sub">Three balanced home-cooked meals daily. Both vegetarian and non-vegetarian options. Special menu on weekends.</p>

    <div class="menu-grid">
        <div class="menu-card">
            <div class="menu-time-badge">7:00 AM - 9:00 AM</div>
            <h3 class="menu-meal-title">Breakfast</h3>
            <ul class="menu-list">
                <li>Dal bhat with sabzi</li>
                <li>Boiled egg / omelette</li>
                <li>Roti with butter</li>
                <li>Seasonal fruit</li>
                <li>Milk tea / coffee</li>
            </ul>
        </div>
        <div class="menu-card">
            <div class="menu-time-badge">12:00 PM - 2:00 PM</div>
            <h3 class="menu-meal-title">Lunch</h3>
            <ul class="menu-list">
                <li>Steamed rice with dal</li>
                <li>Mixed vegetable curry</li>
                <li>Chicken / paneer (alt. days)</li>
                <li>Fresh curd</li>
                <li>Pickle and papad</li>
            </ul>
        </div>
        <div class="menu-card">
            <div class="menu-time-badge">7:00 PM - 9:00 PM</div>
            <h3 class="menu-meal-title">Dinner</h3>
            <ul class="menu-list">
                <li>Rice or roti (choice)</li>
                <li>Dal tadka / curry</li>
                <li>Seasonal sabzi</li>
                <li>Soup on weekdays</li>
                <li>Dessert on weekends</li>
            </ul>
        </div>
    </div>

    <div class="menu-note">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Snacks and beverages are available throughout the day. Dietary requirements can be discussed with the warden.
    </div>
</section>

<!-- ROOM TOUR SECTION -->
<section class="section section-alt" id="rooms">
    <div class="section-header">
        <p class="section-kicker">ACCOMMODATION</p>
        <h2 class="section-title">Choose Your Space</h2>
        <div class="section-line"></div>
    </div>
    <p class="section-sub">All rooms include WiFi, furniture, daily cleaning, and three meals. Prices are per student per month.</p>

    <div class="room-grid">
        <div class="room-card">
            <div class="room-img-wrap">
                <img src="https://images.unsplash.com/photo-1631049307264-da0ec9d70304?auto=format&fit=crop&q=80&w=600" alt="Single Room">
                <span class="room-badge">Single</span>
            </div>
            <div class="room-body">
                <div class="room-price">NPR 5,000 <span>/month</span></div>
                <h3>Private Room</h3>
                <ul class="room-features">
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> 1 student only</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Single bed + wardrobe</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Personal study desk</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Maximum privacy</li>
                </ul>
                <a href="#enquire" class="room-btn">Enquire Now</a>
            </div>
        </div>
        <div class="room-card room-card-featured">
            <div class="room-img-wrap">
                <img src="https://images.unsplash.com/photo-1555854877-bab0e564b8d5?auto=format&fit=crop&q=80&w=600" alt="Double Room">
                <span class="room-badge room-badge-popular">Most Popular</span>
            </div>
            <div class="room-body">
                <div class="room-price">NPR 4,000 <span>/month</span></div>
                <h3>Double Sharing</h3>
                <ul class="room-features">
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> 2 students</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> 2 beds + wardrobes</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Individual study desks</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Best value</li>
                </ul>
                <a href="#enquire" class="room-btn room-btn-primary">Enquire Now</a>
            </div>
        </div>
        <div class="room-card">
            <div class="room-img-wrap">
                <img src="https://images.unsplash.com/photo-1484154218962-a197022b5858?auto=format&fit=crop&q=80&w=600" alt="Triple Room">
                <span class="room-badge">Triple</span>
            </div>
            <div class="room-body">
                <div class="room-price">NPR 3,000 <span>/month</span></div>
                <h3>Triple Sharing</h3>
                <ul class="room-features">
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> 3 students</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> 3 beds + wardrobes</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Shared study area</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Community living</li>
                </ul>
                <a href="#enquire" class="room-btn">Enquire Now</a>
            </div>
        </div>
    </div>

    <div class="rooms-include">
        <p class="rooms-include-title">All rooms include</p>
        <div class="rooms-include-grid">
            <span>Three meals/day</span>
            <span>100 Mbps WiFi</span>
            <span>Electricity &amp; water</span>
            <span>Laundry 2x/week</span>
            <span>Daily housekeeping</span>
            <span>Power backup</span>
        </div>
    </div>
</section>

<!-- SECURITY SECTION -->
<section class="section" id="security">
    <div class="section-header">
        <p class="section-kicker">SAFETY FIRST</p>
        <h2 class="section-title">24/7 Safety &amp; Security</h2>
        <div class="section-line"></div>
    </div>
    <p class="section-sub">Every measure is in place so students and parents can have complete peace of mind.</p>

    <div class="security-layout">
        <div class="security-img-wrap">
            <img src="https://images.unsplash.com/photo-1558002038-1055907df827?auto=format&fit=crop&q=80&w=700" alt="Security System">
        </div>
        <div class="security-features">
            <div class="security-item">
                <div class="security-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </div>
                <div>
                    <h4>Full CCTV Coverage</h4>
                    <p>Cameras at all entrances, corridors, common areas, and the perimeter. 24-hour monitoring and 30-day recording retention.</p>
                </div>
            </div>
            <div class="security-item">
                <div class="security-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </div>
                <div>
                    <h4>Biometric Entry</h4>
                    <p>Fingerprint and card-based access control at all entry points. Only registered residents and staff can enter.</p>
                </div>
            </div>
            <div class="security-item">
                <div class="security-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div>
                    <h4>Resident Warden On-Site</h4>
                    <p>A dedicated warden lives on the premises. Available day and night for emergencies, guidance, and support.</p>
                </div>
            </div>
            <div class="security-item">
                <div class="security-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div>
                    <h4>Curfew &amp; Gate Policy</h4>
                    <p>Main gate closes at 8:00 PM. Late entry requires prior warden permission. Visitor access in common areas until 8 PM only.</p>
                </div>
            </div>
            <div class="security-item">
                <div class="security-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.6 3.44 2 2 0 0 1 3.58 1h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 8.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                </div>
                <div>
                    <h4>Emergency Response</h4>
                    <p>Direct contact with hostel management via the HMS app. Fire safety equipment installed on every floor. Medical assistance protocol in place.</p>
                </div>
            </div>
            <div class="security-item">
                <div class="security-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                </div>
                <div>
                    <h4>Separate Floors</h4>
                    <p>Male and female students are housed on separate, independently secured floors with their own common areas.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FAQ -->
<section class="section section-alt" id="faq">
    <div class="section-header">
        <p class="section-kicker">COMMON QUESTIONS</p>
        <h2 class="section-title">Everything You Need to Know</h2>
        <div class="section-line"></div>
    </div>
    <div class="faq-grid">
        <div class="faq-item">
            <h4>What is the curfew time?</h4>
            <p>Main gates close at 8:00 PM. Exceptions are made with prior warden permission for college classes or emergencies.</p>
        </div>
        <div class="faq-item">
            <h4>Is laundry included?</h4>
            <p>Yes, complimentary laundry is provided twice a week (Monday and Thursday) at no extra charge.</p>
        </div>
        <div class="faq-item">
            <h4>How do I apply?</h4>
            <p>Click Login, then Register. Fill the form, upload a photo, verify your email with the OTP. The warden allocates a room within 24 hours.</p>
        </div>
        <div class="faq-item">
            <h4>What is included in the fee?</h4>
            <p>Three meals a day, 100 Mbps WiFi, electricity, water, laundry, daily cleaning, and 24/7 security. No hidden charges.</p>
        </div>
        <div class="faq-item">
            <h4>Can parents visit?</h4>
            <p>Yes, visitors are welcome in the common lounge between 9 AM and 8 PM with prior warden notice.</p>
        </div>
        <div class="faq-item">
            <h4>What is the refund policy?</h4>
            <p>Cancel 30+ days early for 90% refund, 7-30 days for 50%, under 7 days no refund. Security deposit fully refunded on exit if no damage.</p>
        </div>
    </div>
</section>

<!-- ENQUIRY -->
<section class="section" id="enquire">
    <div class="section-header">
        <p class="section-kicker">GET IN TOUCH</p>
        <h2 class="section-title">Schedule a Visit</h2>
        <div class="section-line"></div>
    </div>
    <p class="section-sub">Walk in any day 9 AM to 6 PM, or fill the form and our team will call to schedule a slot.</p>
    <div class="enquiry-card">
        <form id="enquiry-form" class="enquiry-form">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="name" class="form-input" placeholder="Your full name" required>
                </div>
                <div class="form-field">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-input" placeholder="you@example.com">
                </div>
            </div>
            <div class="form-field">
                <label class="form-label">Your Message</label>
                <textarea name="message" class="form-textarea" rows="4" placeholder="What would you like to know?" required></textarea>
            </div>
            <button type="submit" class="hero-btn hero-btn-primary enquiry-submit">SUBMIT ENQUIRY</button>
        </form>
        <div id="enquiry-success" class="form-status success" style="display:none;">Enquiry submitted. Our team will contact you within 24 hours.</div>
        <div id="enquiry-error"   class="form-status error"   style="display:none;">Could not submit. Please try again or contact us directly.</div>
    </div>
</section>

<!-- FOOTER -->
<footer class="footer">
    <div class="footer-inner">
        <div class="footer-brand">
            <div class="footer-logo">HMS</div>
            <p>Premium student accommodation in Thamel, Kathmandu. Providing a safe, focused, and comfortable home for students since 1994.</p>
        </div>
        <div class="footer-links">
            <h5>Quick Links</h5>
            <a href="#facilities">Facilities</a>
            <a href="#menu">Meal Menu</a>
            <a href="#rooms">Rooms &amp; Pricing</a>
            <a href="#security">Safety</a>
            <a href="#faq">FAQ</a>
            <a href="#enquire">Enquire</a>
        </div>
        <div class="footer-links">
            <h5>Contact</h5>
            <a href="tel:+97714XXXXXX">+977-1-XXXXXXX</a>
            <a href="mailto:hello@hms-hostel.com">hello@hms-hostel.com</a>
            <p style="color:#64748b;font-size:0.85rem;margin-top:0.5rem;">Thamel, Kathmandu<br>Open 9 AM - 6 PM daily</p>
        </div>
        <div class="footer-cta">
            <a href="../auth/login.php" class="hero-btn hero-btn-primary" style="display:inline-block;margin-bottom:0.75rem;">Student Login</a>
            <a href="#enquire" class="hero-btn hero-btn-outline" style="display:inline-block;">Schedule a Visit</a>
        </div>
    </div>
    <div class="footer-bottom">
        <p>&copy; <?= date('Y') ?> HMS Student Residence, Kathmandu. All rights reserved.</p>
    </div>
</footer>

<script>
// Navbar scroll effect
const nav = document.getElementById('navbar');
window.addEventListener('scroll', () => {
    nav.classList.toggle('scrolled', window.scrollY > 50);
});

// Mobile menu toggle
const toggle = document.getElementById('nav-toggle');
const links  = document.getElementById('nav-links');
toggle.addEventListener('click', () => {
    links.classList.toggle('open');
    toggle.classList.toggle('open');
});
// Close nav when a link is clicked
links.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', () => {
        links.classList.remove('open');
        toggle.classList.remove('open');
    });
});

// Enquiry form
const enqForm    = document.getElementById('enquiry-form');
const enqSuccess = document.getElementById('enquiry-success');
const enqError   = document.getElementById('enquiry-error');
if (enqForm) {
    enqForm.onsubmit = async (e) => {
        e.preventDefault();
        const btn = enqForm.querySelector('button[type="submit"]');
        btn.textContent = 'Sending...'; btn.disabled = true;
        try {
            const res = await fetch('../api/submit_enquiry.php', { method: 'POST', body: new FormData(enqForm) });
            const result = await res.json();
            if (res.ok && result.success) {
                enqForm.style.display = 'none'; enqSuccess.style.display = 'block';
            } else { enqError.style.display = 'block'; }
        } catch (err) { enqError.style.display = 'block'; }
        btn.textContent = 'SUBMIT ENQUIRY'; btn.disabled = false;
    };
}

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        const target = document.querySelector(a.getAttribute('href'));
        if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth' }); }
    });
});
</script>

<?php include __DIR__ . '/../chatbot/widget.php'; ?>
</body>
</html>
