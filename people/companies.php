<?php
/**
 * People — the companies this analyst can see (#153 step 2).
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
require_once '../includes/people.php';
require_once 'includes/render.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('people');

$conn = connectToDatabase();
$analystId = (int)$_SESSION['analyst_id'];
$companies = peopleCompanies($conn, $analystId);
$multi = isMultiTenant($conn);
$current_page = 'companies';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="<?php echo pplE(I18n::getLocale()); ?>" data-theme="<?php echo pplE(Theme::active()); ?>" data-theme-mode="<?php echo pplE(Theme::mode()); ?>">
<head>
    <?php pplHead(t('people.nav.companies')); ?>
</head>
<body data-mobile-module="people" data-mobile-page="people-companies">
    <?php include 'includes/header.php'; ?>

    <main class="ppl-page">
        <p class="ppl-dim ppl-lead"><?php echo pplE(t($multi ? 'people.companies.intro' : 'people.companies.single')); ?></p>
        <div class="ppl-companies">
        <?php foreach ($companies as $co): ?>
            <a class="ppl-company" href="company.php?id=<?php echo $co['id']; ?>">
                <span class="ppl-avatar company small">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"></path><path d="M5 21V7l8-4v18"></path><path d="M19 21V11l-6-4"></path></svg>
                </span>
                <span class="ppl-company-main">
                    <span class="n"><?php echo pplE($co['name']); ?>
                        <?php if ($co['is_default'] && $multi): ?><?php echo pplPill(t('people.companies.default'), null, 'muted'); ?><?php endif; ?>
                        <?php if (!$co['is_active']): ?><?php echo pplPill(t('people.companies.inactive'), null, 'bad'); ?><?php endif; ?>
                    </span>
                    <span class="c"><?php echo pplE($co['people'] === 1 ? t('people.companies.person') : t('people.companies.people', ['n' => $co['people']])); ?></span>
                </span>
            </a>
        <?php endforeach; ?>
        </div>
    </main>
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=67"></script>
</body>
</html>
