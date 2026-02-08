<?php

/**
 * API endpoint for clinician-side Jitsi telehealth requests.
 *
 * @package   openemr
 * @link      http://www.open-emr.org
 * @author    EPA Bienestar
 * @copyright Copyright (c) 2024 EPA Bienestar
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once "../../../../globals.php";

use EPA\OpenEMR\Modules\JitsiTeleHealth\Bootstrap;

$kernel = $GLOBALS['kernel'];
$bootstrap = new Bootstrap($kernel->getEventDispatcher(), $kernel);
$roomController = $bootstrap->getConferenceRoomController(false);

$action = $_REQUEST['action'] ?? '';
$queryVars = $_REQUEST ?? [];
$queryVars['pid'] = $_SESSION['pid'] ?? null;
$queryVars['authUser'] = $_SESSION['authUser'] ?? null;
if (!empty($_SERVER['HTTP_APICSRFTOKEN'])) {
    $queryVars['csrf_token'] = $_SERVER['HTTP_APICSRFTOKEN'];
}
$roomController->dispatch($action, $queryVars);
exit;
