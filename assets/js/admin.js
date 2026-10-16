document.addEventListener('DOMContentLoaded', function () {
    // Submenu Toggle
    document.querySelectorAll('.has-submenu').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            const submenu = this.nextElementSibling;
            if (submenu && submenu.classList.contains('submenu')) {
                submenu.classList.toggle('open');
                this.classList.toggle('submenu-open');
            }
        });
    });
});

function toggleSidebar() {
    document.querySelector('.admin-sidebar').classList.toggle('collapsed');
}

// Global Filter Table Function (Used in companies.php, etc.)
function filterTable(input) {
    if (!input) return;
    let filter = input.value.toLowerCase();

    // We check for vendor-row specifically as used in companies.php
    let rows = document.querySelectorAll('.vendor-row');
    if (rows.length > 0) {
        rows.forEach(row => {
            let text = row.innerText.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    }
}
