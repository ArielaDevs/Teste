<?php
/**
 * POST — the page-load run for installs with no cron. The Domains pages call
 * this in the background, never waiting on it; it does a short batch of
 * lookups, checks and alerts at most once an hour
 * (see domainOpportunisticRun()) and otherwise returns at once.
 *
 * ⚠️ session was opened read-and-close by the bootstrap, so a slow run here
 * never holds the session lock against the page the analyst is looking at.
 */
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';
require_once __DIR__ . '/../../includes/domains/scheduler.php';

ignore_user_abort(true);
set_time_limit(60);
domainApiRun(function () use ($conn) {
    $r = domainOpportunisticRun($conn);
    domainApiOk(['ran' => $r !== null]);
});
