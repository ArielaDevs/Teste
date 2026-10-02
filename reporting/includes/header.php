<?php
/**
 * Reporting Module Header Component
 */

$path_prefix = $path_prefix ?? '../';
$current_module = 'reporting';
$module_title = function_exists('t') ? t('reporting.title') : 'Reporting';

// Ensure user is logged in
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = $current_page ?? '';

// Include the shared waffle menu component
require_once $path_prefix . 'includes/waffle-menu.php';
?>

<div class="header reporting-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <span class="module-title"><?php echo $module_title; ?></span>
    </div>
    <nav class="header-nav">
        <a href="<?php echo BASE_URL; ?>reporting/packs/" class="nav-btn <?php echo $current_page === 'packs' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('reporting.nav.packs_title') : 'Report Packs'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M8 3h9l4 4v12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"></path>
                <path d="M3 7v13a2 2 0 0 0 2 2h11"></path>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('reporting.nav.packs') : 'Packs'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>reporting/logs/" class="nav-btn <?php echo $current_page === 'logs' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('reporting.nav.logs_title') : 'System Logs'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
                <polyline points="10 9 9 9 8 9"></polyline>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('reporting.nav.logs') : 'Logs'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>reporting/tickets/" class="nav-btn <?php echo $current_page === 'tickets' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('reporting.nav.tickets_title') : 'Ticket Dashboards'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="20" x2="18" y2="10"></line>
                <line x1="12" y1="20" x2="12" y2="4"></line>
                <line x1="6" y1="20" x2="6" y2="14"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('reporting.nav.tickets') : 'Tickets'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>reporting/intune/" class="nav-btn <?php echo $current_page === 'intune' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('reporting.nav.intune_title') : 'Intune Dashboard'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                <line x1="8" y1="21" x2="16" y2="21"></line>
                <line x1="12" y1="17" x2="12" y2="21"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('reporting.nav.intune') : 'Intune'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>reporting/help.php" class="nav-btn <?php echo $current_page === 'help' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('reporting.nav.help_title') : 'Help'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('reporting.nav.help') : 'Help'); ?></span>
        </a>
    </nav>
    <?php renderHeaderRight($analyst_name, $path_prefix); ?>
</div>

<?php renderWaffleMenuJS(); ?>
