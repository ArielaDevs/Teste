<?php
/**
 * Domains module header — the waffle, the module title, the nav, the avatar.
 *
 * Every URL here is built from BASE_URL, never a relative "../": the pages sit
 * at two depths (domains/ and domains/<page>/), and a relative link that works
 * from one is broken from the other.
 */

$path_prefix = $path_prefix ?? '../';
$current_module = 'domains';
$module_title = t('domains.title');

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = $current_page ?? '';

require_once $path_prefix . 'includes/waffle-menu.php';

$domNav = [
    'register'  => ['domains/',            t('domains.nav.register'),  '<path d="M20.9 13.5A10 10 0 1 0 12 22"></path><path d="M2 12h20"></path><path d="M12 2a15.3 15.3 0 0 1 4 10"></path><path d="M12 2a15.3 15.3 0 0 0-4 10 15.3 15.3 0 0 0 4 10"></path><rect x="15" y="17" width="7" height="5" rx="1"></rect><path d="M16.5 17v-1.5a2 2 0 0 1 4 0V17"></path>'],
    'table'     => ['domains/table/',      t('domains.nav.table'),     '<rect x="3" y="3" width="18" height="18" rx="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="3" y1="15" x2="21" y2="15"></line><line x1="9" y1="3" x2="9" y2="21"></line>'],
    'dashboard' => ['domains/dashboard/',  t('domains.nav.dashboard'), '<line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line>'],
    'accounts'  => ['domains/accounts/',   t('domains.nav.accounts'),  '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>'],
    'settings'  => ['domains/settings/',   t('domains.nav.settings'),  '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>'],
    'help'      => ['domains/help.php',    t('domains.nav.help'),      '<circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line>'],
];
?>

<div class="header domains-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <span class="module-title"><?php echo htmlspecialchars($module_title); ?></span>
    </div>
    <nav class="header-nav">
        <?php foreach ($domNav as $key => [$href, $label, $icon]): ?>
        <a href="<?php echo BASE_URL . $href; ?>" class="nav-btn <?php echo $current_page === $key ? 'active' : ''; ?>" title="<?php echo htmlspecialchars($label); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $icon; ?></svg>
            <span><?php echo htmlspecialchars($label); ?></span>
        </a>
        <?php endforeach; ?>
    </nav>
    <?php renderHeaderRight($analyst_name, $path_prefix); ?>
</div>

<?php renderWaffleMenuJS(); ?>
<script>window.DOM_API = <?php echo json_encode(BASE_URL . 'api/domains/'); ?>; window.DOM_BASE = <?php echo json_encode(BASE_URL); ?>; window.DOM_ME = <?php echo (int)($_SESSION['analyst_id'] ?? 0); ?>;</script>
