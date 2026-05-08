// Admin Panel JavaScript

document.addEventListener('DOMContentLoaded', function() {
    console.log('Admin panel loaded');

    initAdminMenuToggle();

    if (document.querySelector('.stat-card')) {
        loadDashboardStats();
    }
});

function initAdminMenuToggle() {
    const toggleButton = document.querySelector('.admin-menu-toggle');
    const overlay = document.querySelector('.admin-overlay');
    const menuLinks = document.querySelectorAll('.menu a');

    if (!toggleButton || !overlay) {
        return;
    }

    function setOpenState(isOpen) {
        document.body.classList.toggle('admin-menu-open', isOpen);
        toggleButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    toggleButton.addEventListener('click', function() {
        const isOpen = document.body.classList.contains('admin-menu-open');
        setOpenState(!isOpen);
    });

    overlay.addEventListener('click', function() {
        setOpenState(false);
    });

    menuLinks.forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                setOpenState(false);
            }
        });
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            setOpenState(false);
        }
    });
}

function loadDashboardStats() {
    // Load statistics
    fetch('api/get_stats.php')
        .then(response => response.json())
        .then(data => {
            displayStats(data);
        })
        .catch(error => console.error('Error:', error));
}

function displayStats(stats) {
    // Update dashboard statistics
    const cards = document.querySelectorAll('.stat-card');
    if (cards.length >= 4) {
        cards[0].querySelector('.stat-number').textContent = stats.total_products || 0;
        cards[1].querySelector('.stat-number').textContent = stats.total_users || 0;
        cards[2].querySelector('.stat-number').textContent = stats.total_orders || 0;
        cards[3].querySelector('.stat-number').textContent = '৳ ' + (stats.total_revenue || 0);
    }
}

function logout() {
    if (confirm('Are you sure you want to logout?')) {
        window.location.href = 'logout.php';
    }
}
