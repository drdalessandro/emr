<?php

/**
 * API endpoint for patient portal Jitsi telehealth requests.
 *
 * @package   openemr
 * @link      http://www.open-emr.org
 * @author    EPA Bienestar
 * @copyright Copyright (c) 2024 EPA Bienestar
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$originalPath = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = dirname($originalPath, 6);
$query = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_QUERY);
$redirect = $originalPath . "?";
if (!empty($query)) {
    $redirect .= $query;
}
$landingpage = $basePath . "portal/index.php?site=" . urlencode($_GET['site_id'] ?? '') . "&redirect=" . urlencode($redirect);
$skipLandingPageError = true;

// Use portal session verification
require_once "../../../../../portal/verify_session.php";

use EPA\OpenEMR\Modules\JitsiTeleHealth\Bootstrap;

$kernel = $GLOBALS['kernel'];
$bootstrap = new Bootstrap($kernel->getEventDispatcher(), $kernel);
$roomController = $bootstrap->getConferenceRoomController(true);
if (!empty($_SERVER['HTTP_APICSRFTOKEN'])) {
    $queryVars['csrf_token'] = $_SERVER['HTTP_APICSRFTOKEN'];
}
$action = $_GET['action'] ?? '';
$queryVars = $_GET ?? [];
$queryVars['pid'] = $_SESSION['pid'];
$roomController->dispatch($action, $queryVars);
exit;
