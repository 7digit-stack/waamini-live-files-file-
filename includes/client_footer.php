</div><!-- .main-content -->

<!-- Mobile Menu Toggle Handler -->
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
document.addEventListener('DOMContentLoaded', function() {
    // Hamburger menu toggle for mobile
    var hamburger = document.getElementById('hamburger') || document.querySelector('.hamburger-menu');
    var sidebar = document.getElementById('sidebar') || document.querySelector('.sidebar');
    
    if (hamburger && sidebar) {
        var overlay = document.getElementById('sidebarOverlay');
        
        hamburger.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.toggle('open');
            if (overlay) overlay.classList.toggle('show');
        });
        
        if (overlay) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('open');
                overlay.classList.remove('show');
            });
        }
        
        // Close sidebar when clicking outside
        document.addEventListener('click', function(e) {
            if (!sidebar.contains(e.target) && !hamburger.contains(e.target)) {
                sidebar.classList.remove('open');
                if (overlay) overlay.classList.remove('show');
            }
        });
    }
    
    // User dropdown toggle
    var topbarUser = document.querySelector('.topbar-user');
    if (topbarUser) {
        topbarUser.addEventListener('click', function(e) {
            var dropdown = this.querySelector('.topbar-dropdown');
            if (dropdown) {
                dropdown.classList.toggle('show');
            }
        });
    }

    // Background Queue Processor (Poor Man's Cron for local/limited environments)
    // Runs once every 60 seconds to process pending drips, campaigns, and automation.
    (function() {
        const cronKey = <?php echo json_encode(\Waamini\Config\Env::get('CRON_SECRET_KEY', 'local_dev_cron_key_32_chars_abcdef')); ?>;
        const companyId = <?php echo (int)($companyId ?? 0); ?>;
        const appBase = <?php echo json_encode(app_url('')); ?>;
        const LAST_SYNC_KEY = 'waamini_last_queue_sync';
        const now = Date.now();
        const lastSync = parseInt(localStorage.getItem(LAST_SYNC_KEY) || '0');
        
        if (now - lastSync > 60000) {
            localStorage.setItem(LAST_SYNC_KEY, now.toString());
            
            // Normalize appBase to ensure it ends with slash or we add it correctly
            const base = appBase.endsWith('/') ? appBase : appBase + '/';
            
            // 1. Process Automations
            fetch(base + 'api/process_automation_queue.php', {
                method: 'GET',
                credentials: 'same-origin'
            }).catch(() => {});
            
            // 2. Process Campaigns
            if (companyId > 0) {
                fetch(base + 'api/campaign_tick.php?company_id=' + companyId, {
                    method: 'GET',
                    credentials: 'same-origin'
                }).catch(() => {});
                fetch(base + 'api/drip_tick.php?company_id=' + companyId, {
                    method: 'GET',
                    credentials: 'same-origin'
                }).catch(() => {});
            }
        }
    })();
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.topbar-user')) {
            var dropdowns = document.querySelectorAll('.topbar-dropdown');
            dropdowns.forEach(function(d) {
                d.classList.remove('show');
            });
        }
    });
});
</script>

<script src="<?php echo app_url('assets/js/mobile-nav.js?v=1'); ?>"></script>

<?php if (isset($conn)) $conn->close(); ?>
</body>
</html>