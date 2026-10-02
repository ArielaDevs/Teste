<?php
/** Pretty URL: /problem-management/new/ → open the module with the editor showing. */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/base_url.php';   // BASE_URL if config.php lacks it (GH #129)
header('Location: ' . BASE_URL . 'problem-management/?new=1');
exit;
