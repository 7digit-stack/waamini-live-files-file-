/**
 * WAAMINI Admin Sidebar - Submenu Handler
 * Compliant with Content Security Policy (CSP)
 */

document.addEventListener('DOMContentLoaded', function() {
    
    // Select all menu items that have submenus
    const submenuTogglers = document.querySelectorAll('.has-submenu');
    
    submenuTogglers.forEach(toggler => {
        toggler.addEventListener('click', function(e) {
            e.preventDefault();
            
            const submenu = this.nextElementSibling;
            
            if (submenu && submenu.classList.contains('submenu')) {
                // Toggle the 'open' class on the submenu
                submenu.classList.toggle('open');
                
                // Toggle the 'submenu-open' class on the trigger link (for arrow rotation)
                this.classList.toggle('submenu-open');
                
                // Close other submenus (Optional - User didn't ask for it, but it's cleaner)
                // document.querySelectorAll('.submenu.open').forEach(other => {
                //     if (other !== submenu) other.classList.remove('open');
                // });
            }
        });
    });

    // Mobile Sidebar Toggle (off-canvas)
    const sidebarToggle = document.querySelector('.sidebar-toggle');
    const sidebar = document.querySelector('.admin-sidebar');

    // Backdrop for mobile
    let backdrop = document.querySelector('.admin-sidebar-backdrop');
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.className = 'admin-sidebar-backdrop';
        document.body.appendChild(backdrop);
    }

    function setOpen(isOpen) {
        if (!sidebar) return;
        sidebar.classList.toggle('open', isOpen);
        document.body.classList.toggle('admin-sidebar-open', isOpen);
    }

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function() {
            setOpen(!sidebar.classList.contains('open'));
        });

        backdrop.addEventListener('click', function() {
            setOpen(false);
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') setOpen(false);
        });
    }
});
