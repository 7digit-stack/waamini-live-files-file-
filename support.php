<?php 
$page_title = "Support & Help Center - Contact Waamini CRM";
$meta_desc = "Get expert support for your WhatsApp Business API. FAQs, contact information, and technical help for Waamini WhatsApp CRM users.";
$meta_keywords = "WhatsApp CRM Support, WhatsApp API Help, Contact Waamini, WhatsApp Automation FAQ";
$asyncStylesheets = [
  'assets/css/support.css?v=4',
  'assets/css/landing-neo.css?v=15',
];
$bodyClass = 'lp-neo';
$pageCriticalCss = <<<'CSS'
.support-hero{padding:140px 0 100px;text-align:center;position:relative;overflow:hidden;background:#ffffff}
.support-hero .badge-label{display:inline-block;padding:10px 24px;background:rgba(37,211,102,0.08);border:1px solid rgba(37,211,102,0.2);border-radius:50px;color:var(--primary);font-size:13px;font-weight:700;margin-bottom:25px;text-transform:uppercase;letter-spacing:1px}
.support-hero h1{font-size:46px;font-weight:900;letter-spacing:-2px;color:#fff;margin-bottom:20px}
CSS;
include 'includes/header.php'; 
?>

<!-- FAQ SCHEMA (JSON-LD) -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [{
    "@type": "Question",
    "name": "What is Waamini CRM?",
    "acceptedAnswer": {
      "@type": "Answer",
      "text": "Waamini is a WhatsApp Business API platform that helps businesses automate their customer communication, sales, and support on WhatsApp."
    }
  }, {
    "@type": "Question",
    "name": "How long does API approval take?",
    "acceptedAnswer": {
      "@type": "Answer",
      "text": "Most businesses get approved within a few minutes using our signup process. Full business verification by Meta may take 1-2 days."
    }
  }, {
    "@type": "Question",
    "name": "Can I use my existing number?",
    "acceptedAnswer": {
      "@type": "Answer",
      "text": "Yes, you can use your current phone number with the WhatsApp API, provided it is not currently active on a regular WhatsApp app."
    }
  }, {
    "@type": "Question",
    "name": "Is technical support included?",
    "acceptedAnswer": {
      "@type": "Answer",
      "text": "Yes, we provide technical support for all our plans to help you set up and manage your WhatsApp automation effectively."
    }
  }]
}
</script>

<!-- BACKGROUND BLOBS -->
<div class="auth-bg-blobs">
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="blob blob-3"></div>
</div>

<!-- HERO -->
<section class="support-hero">
    <div class="container">
        <div class="badge-label"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/></svg> Support Center</div>
        <div class="title-wrap"><h1>How can we help you?</h1></div>
        <p>Get the help you need for your WhatsApp Business account. Our team is here to assist you with any questions or issues.</p>
    </div>
</section>

<!-- CONTACT CARDS -->
<div class="contact-cards">
    <div class="contact-card">
        <div class="card-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg></div>
        <h3>Call Us</h3>
        <p>
            <a href="tel:+919150802906">+91 9150802906</a><br>
            <a href="tel:+919003876516">+91 9003876516</a>
        </p>
        <p style="font-size: 12px; margin-top: 10px;">Mon-Sat: 9 AM – 7 PM IST</p>
    </div>
    <div class="contact-card" style="border-top: 2px solid var(--sup-primary);">
        <div class="card-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg></div>
        <h3>Email Us</h3>
        <p><a href="mailto:info@7digit.in">info@7digit.in</a></p>
        <p style="font-size: 12px; margin-top: 10px;">We usually reply within 24 hours</p>
    </div>
    <div class="contact-card">
        <div class="card-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/></svg></div>
        <h3>Our Office</h3>
        <p style="font-size: 13px;">2nd Floor, Dolphin Super Market,<br>Kalanivasal, Karaikudi - 630001</p>
    </div>
</div>

<!-- SUPPORT LAYOUT -->
<div class="support-layout">

    <!-- FAQ -->
    <div class="faq-container">
        <div class="title-wrap"><h2>Frequently Asked Questions</h2></div>

        <div class="faq-item open">
            <div class="faq-question" onclick="toggleFaq(this)">
                What is Waamini CRM?
                <span class="faq-arrow">▾</span>
            </div>
            <div class="faq-answer">
                <p>Waamini is a WhatsApp Business API platform that helps businesses automate their customer communication, sales, and support on WhatsApp.</p>
            </div>
        </div>

        <div class="faq-item">
            <div class="faq-question" onclick="toggleFaq(this)">
                How long does API approval take?
                <span class="faq-arrow">▾</span>
            </div>
            <div class="faq-answer">
                <p>Most businesses get approved within a few minutes using our signup process. Full business verification by Meta may take 1-2 days.</p>
            </div>
        </div>

        <div class="faq-item">
            <div class="faq-question" onclick="toggleFaq(this)">
                Can I use my existing number?
                <span class="faq-arrow">▾</span>
            </div>
            <div class="faq-answer">
                <p>Yes, you can use your current phone number with the WhatsApp API, provided it is not currently active on a regular WhatsApp app.</p>
            </div>
        </div>

        <div class="faq-item">
            <div class="faq-question" onclick="toggleFaq(this)">
                Is technical support included?
                <span class="faq-arrow">▾</span>
            </div>
            <div class="faq-answer">
                <p>Yes, we provide technical support for all our plans to help you set up and manage your WhatsApp automation effectively.</p>
            </div>
        </div>
    </div>

    <!-- CONTACT FORM -->
    <div class="contact-form-wrapper">
        <div class="title-wrap"><h2>Send a Message</h2></div>
        <div class="contact-form">
            <form method="POST" action="#">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" placeholder="Enter your name" required>
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="name@email.com" required>
                </div>
                <div class="form-group">
                    <label>Subject</label>
                    <select name="subject" class="auth-input" style="background: rgba(255,255,255,0.03); border: 1.5px solid rgba(255,255,255,0.1); border-radius: 16px; width: 100%; color: #fff; padding: 16px;">
                        <option value="general">General Question</option>
                        <option value="sales">Sales Inquiry</option>
                        <option value="technical" selected>Technical Help</option>
                        <option value="api">API Setup</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Message</label>
                    <textarea name="message" placeholder="How can we help you today?" required style="min-height: 120px;"></textarea>
                </div>
                <button type="submit" class="submit-btn">Submit Message</button>
            </form>
        </div>
    </div>
</div>

<!-- MAP SECTION -->
<div class="map-section container animate-fade-in" style="margin-top: 80px; margin-bottom: 100px;">
    <div class="map-wrapper" style="border-radius: 40px; overflow: hidden; border: 1px solid rgba(37,211,102, 0.2); box-shadow: 0 40px 100px rgba(0,0,0,0.5); position: relative;">
        <!-- Embedded Google Map -->
        <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3928.173874312!2d78.774!3d10.085!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3b00678de0011ce1%3A0xbab48a457c1c8bd5!2s7digit!5e0!3m2!1sen!2sin!4v1774790137756!5m2!1sen!2sin" width="100%" height="550" style="border:0; filter: invert(90%) hue-rotate(180deg) brightness(95%) contrast(90%);" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
        
        <!-- Premium Map Overlay (Matched to Screenshot) -->
        <div class="map-info-bar" style="position: absolute; bottom: 30px; left: 30px; right: 30px; background: #f8fafc; border-radius: 20px; padding: 20px 30px; border: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.6);">
            <div style="display: flex; align-items: center; gap: 15px;">
                <div style="width: 38px; height: 38px; background: rgba(37,211,102, 0.15); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                    <span style="font-size: 18px; filter: drop-shadow(0 0 5px rgba(255,0,0,0.5));"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/></svg></span>
                </div>
                <span style="color:#fff; font-weight:700; font-size:14px; letter-spacing: -0.2px;">2nd Floor, Dolphin Super Market, Karaikudi - 630001</span>
            </div>
            <a href="https://maps.app.goo.gl/sagyQzVW6EcuqQW27" target="_blank" style="background: #fff; color: #000; padding: 12px 28px; border-radius: 12px; font-weight: 800; font-size: 13px; text-decoration: none; transition: 0.3s; display: inline-flex; align-items: center;">
                Get Directions
            </a>
        </div>
    </div>
</div>

<!-- 7digit-system port: same Lenis + GSAP ScrollTrigger reveal as the homepage -->
<script src="https://cdn.jsdelivr.net/npm/lenis@1.1.16/dist/lenis.min.js" nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js" nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js" nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>"></script>
<script src="assets/js/lp-theme.js?v=4" nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>"></script>

<?php include 'includes/footer.php'; ?>

<script>
function toggleFaq(el) {
    const item = el.parentElement;
    const allItems = document.querySelectorAll('.faq-item');
    allItems.forEach(i => {
        if (i !== item) i.classList.remove('open');
    });
    item.classList.toggle('open');
}
</script>
