<?php


if (!defined('ABSPATH')) exit; // Exit if accessed directly


/**
 * @var $router FluentAffiliate\Framework\Http\Router
 */

use FluentAffiliate\App\Http\Controllers\MigrationController;
use FluentAffiliate\App\Http\Controllers\Portal\PortalController;
use FluentAffiliate\App\Http\Controllers\DashboardController;
use FluentAffiliate\App\Http\Controllers\AffiliateController;
use FluentAffiliate\App\Http\Controllers\ReferralController;
use FluentAffiliate\App\Http\Controllers\SettingController;
use FluentAffiliate\App\Http\Controllers\ReportsController;
use FluentAffiliate\App\Http\Controllers\PayoutController;
use FluentAffiliate\App\Http\Controllers\VisitController;
use FluentAffiliate\App\Http\Controllers\IntegrationController;

$router->prefix('reports')->withPolicy('UserPolicy')->group(function ($router) {
    $router->get('advanced-providers', [ReportsController::class, 'getAdvancedReportProviders']);
    $router->get('commerce-reports/{provider}', [ReportsController::class, 'getReports'])->alphaNumDash('provider');
    $router->get('commerce-reports/{provider}/report', [ReportsController::class, 'getReport'])->alphaNumDash('provider');
    $router->get('dashboard-stats', [DashboardController::class, 'getStats']);
    $router->get('dashboard-chart-stats', [DashboardController::class, 'getChartStats']);
});

$router->prefix('affiliates')->withPolicy('AffiliatePolicy')->group(function ($router) {
    $router->get('/', [AffiliateController::class, 'index']);
    $router->post('/', [AffiliateController::class, 'createAffiliate']);

    $router->get('/{id}', [AffiliateController::class, 'getAffiliate'])->int('id');
    $router->delete('/{id}', [AffiliateController::class, 'deleteAffiliate'])->int('id');
    $router->patch('/{id}', [AffiliateController::class, 'updateAffiliate'])->int('id');
    $router->patch('/{id}/status', [AffiliateController::class, 'updateAffiliateStatus'])->int('id');

    $router->get('/{id}/transactions', [AffiliateController::class, 'getTransactions'])->int('id');
    $router->get('/{id}/visits', [AffiliateController::class, 'getVisits'])->int('id');
    $router->get('/{id}/referrals', [AffiliateController::class, 'getReferrals'])->int('id');
    $router->get('/{id}/stats', [AffiliateController::class, 'getOverviewStats'])->int('id');
    $router->get('/{id}/statistics', [AffiliateController::class, 'statistics'])->int('id');
});

$router->prefix('referrals')->withPolicy('ReferralPolicy')->group(function ($router) {
    $router->get('/', [ReferralController::class, 'index']);
    $router->post('/', [ReferralController::class, 'createReferral']);
    $router->get('/{id}', [ReferralController::class, 'show'])->int('id');
    $router->patch('/{id}', [ReferralController::class, 'update'])->int('id');
    $router->delete('/{id}', [ReferralController::class, 'destroy'])->int('id');
});

$router->prefix('payouts')->withPolicy('PayoutPolicy')->group(function ($router) {
    $router->get('/', [PayoutController::class, 'index']);
    $router->post('/validate-payout-config', [PayoutController::class, 'validatePayoutConfig']);
    $router->post('/process-payout', [PayoutController::class, 'processPayout']);
    $router->get('/{id}', [PayoutController::class, 'show'])->int('id');

    $router->get('/{id}/referrals', [PayoutController::class, 'getReferrals'])->int('id');

    $router->get('/{id}/transactions', [PayoutController::class, 'getTransactions'])->int('id');
    $router->get('/{id}/transactions-export', [PayoutController::class, 'getExportableTransactions'])->int('id');
    $router->delete('/{id}/transactions/{transaction_id}', [PayoutController::class, 'deleteTransaction'])->int('id')->int('transaction_id');
    $router->patch('/{id}/transactions/{transaction_id}', [PayoutController::class, 'patchTransaction'])->int('id')->int('transaction_id');
    $router->patch('/{id}/transactions/bulk-action', [PayoutController::class, 'bulkPatchTransactions'])->int('id');

    $router->patch('/{id}', [PayoutController::class, 'updatePayout'])->int('id');
});

$router->prefix('visits')->withPolicy('VisitPolicy')->group(function ($router) {
    $router->get('/', [VisitController::class, 'index']);
});

$router->prefix('settings')->withPolicy('AdminPolicy')->group(function ($router) {
    $router->get('/email-config', [SettingController::class, 'getEmailConfig']);
    $router->post('/email-config', [SettingController::class, 'updateEmailConfig']);

    $router->get('/email-config/emails', [SettingController::class, 'getNotificationEmails']);
    $router->post('/email-config/emails', [SettingController::class, 'updateNotificationEmails']);
    $router->patch('/email-config/emails', [SettingController::class, 'patchSingleNotificationEmail']);

    $router->get('/integrations', [IntegrationController::class, 'index']);
    $router->get('/integration/config', [IntegrationController::class, 'getConfig']);
    $router->post('/integration/config', [IntegrationController::class, 'saveConfig']);
    $router->post('/integration/update-status', [IntegrationController::class, 'updateIntegrationStatus']);
    $router->get('/integration/product_cat_options', [IntegrationController::class, 'getCustomAffiliateOptions']);

    $router->get('/pages', [SettingController::class, 'getPagesOptions']);

    $router->post('/create-page', [SettingController::class, 'createPage']);

    $router->get('/referral-config', [SettingController::class, 'getReferralConfig']);
    $router->post('/referral-config', [SettingController::class, 'saveReferralConfig']);

    $router->get('/migrations', [MigrationController::class, 'getAvailableMigrations']);
    $router->post('/migrations/start', [MigrationController::class, 'startMigration']);
    $router->get('/migrations/status', [MigrationController::class, 'getPollingStatus']);
    $router->post('/migrations/wipe', [MigrationController::class, 'wipeCurrentData']);
    $router->get('/migrations/statistics', [MigrationController::class, 'getMigrationStatistics']);

    $router->post('/migrate/affiliates', [MigrationController::class, 'migrateAffiliates']);
    $router->post('/migrate/referrals', [MigrationController::class, 'migrateReferrals']);
    $router->post('/migrate/customers', [MigrationController::class, 'migrateCustomers']);
    $router->post('/migrate/payouts', [MigrationController::class, 'migratePayouts']);
    $router->post('/migrate/visits', [MigrationController::class, 'migrateVisits']);

    $router->get('/options/affiliates', [SettingController::class, 'getAffiliatesOptions']);
    $router->get('/options/users', [SettingController::class, 'getUsersOptions']);

    $router->get('/registration-fields', [SettingController::class, 'getRegistrationFields']);
});

// Frontend User Routes
$router->prefix('portal')->withPolicy('UserPolicy')->group(function ($router) {
    $router->get('stats', [PortalController::class, 'getStats']);
    $router->get('referrals', [PortalController::class, 'getReferrals']);
    $router->get('transactions', [PortalController::class, 'getTransactions']);
    $router->get('visits', [PortalController::class, 'getVisits']);
    $router->get('settings', [PortalController::class, 'getSettings']);
    $router->post('settings', [PortalController::class, 'updateSettings']);
});
