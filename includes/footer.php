<?php
/**
 * Global Footer Component
 * Mess & Hostel Management System
 */
$isLoggedIn = !empty($_SESSION['user_id']);
?>
    </main>
<?php if ($isLoggedIn): ?>
</div> <!-- End .app-wrapper -->
<?php endif; ?>

<footer class="text-center py-3 text-muted mt-auto" style="font-size: 0.85rem; background: rgba(255, 255, 255, 0.92); backdrop-filter: blur(16px); border-top: 1px solid rgba(226, 232, 240, 0.8);">
    <div class="container">
        <span>&copy; <?= date('Y') ?> Mess & Hostel Management System. All rights reserved.</span>
    </div>
</footer>

<!-- Bootstrap 5 Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Chart.js for Admin Analytics -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<!-- Custom App JS -->
<script src="/assets/js/app.js"></script>
</body>
</html>
