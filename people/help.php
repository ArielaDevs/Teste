<?php
/**
 * People — help.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
require_once 'includes/render.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('people');

$current_page = 'help';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="<?php echo pplE(I18n::getLocale()); ?>" data-theme="<?php echo pplE(Theme::active()); ?>" data-theme-mode="<?php echo pplE(Theme::mode()); ?>">
<head>
    <?php pplHead(t('people.help.title')); ?>
</head>
<body data-mobile-module="people" data-mobile-page="people-help">
    <?php include 'includes/header.php'; ?>

    <main class="ppl-page ppl-help">
        <h1><?php echo pplE(t('people.help.title')); ?></h1>
        <p class="ppl-lead"><?php echo pplE(t('people.help.intro')); ?></p>
        <?php foreach (['what', 'company', 'access', 'edit'] as $k): ?>
        <section class="ppl-card">
            <div class="ppl-card-h"><h3><?php echo pplE(t('people.help.' . $k . '_h')); ?></h3></div>
            <p><?php echo pplE(t('people.help.' . $k)); ?></p>
        </section>
        <?php endforeach; ?>
    </main>
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=70"></script>
</body>
</html>
