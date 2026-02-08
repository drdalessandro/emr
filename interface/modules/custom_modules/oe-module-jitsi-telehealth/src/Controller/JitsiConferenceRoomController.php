<?php

/**
 * Handles conference room page rendering and API actions for Jitsi telehealth sessions.
 *
 * @package   openemr
 * @link      http://www.open-emr.org
 * @author    EPA Bienestar
 * @copyright Copyright (c) 2024 EPA Bienestar
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace EPA\OpenEMR\Modules\JitsiTeleHealth\Controller;

use EPA\OpenEMR\Modules\JitsiTeleHealth\JitsiGlobalConfig;
use EPA\OpenEMR\Modules\JitsiTeleHealth\Repository\JitsiCalendarEventCategoryRepository;
use EPA\OpenEMR\Modules\JitsiTeleHealth\Repository\JitsiSessionRepository;
use EPA\OpenEMR\Modules\JitsiTeleHealth\Util\JitsiJwtHelper;
use EPA\OpenEMR\Modules\JitsiTeleHealth\Util\CalendarUtils;
use Exception;
use InvalidArgumentException;
use OpenEMR\Common\Acl\AccessDeniedException;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Session\EncounterSessionUtil;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Services\AppointmentService;
use OpenEMR\Services\EncounterService;
use OpenEMR\Services\ListService;
use OpenEMR\Services\PatientService;
use OpenEMR\Services\UserService;
use Psr\Log\LoggerInterface;
use Twig\Environment;

class JitsiConferenceRoomController
{
    private AppointmentService $appointmentService;
    private EncounterService $encounterService;
    private JitsiSessionRepository $sessionRepository;

    public function __construct(
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly JitsiGlobalConfig $config,
        private readonly string $assetPath,
        private readonly bool $isPatient = false
    ) {
        $this->appointmentService = new AppointmentService();
        $this->encounterService = new EncounterService();
        $this->sessionRepository = new JitsiSessionRepository();
    }

    public function dispatch(string $action, array $queryVars): void
    {
        $this->logger->debug(
            "JitsiConferenceRoomController->dispatch()",
            ['action' => $action, 'queryVars' => $queryVars, 'isPatient' => $this->isPatient]
        );

        match ($action) {
            'get_telehealth_launch_data' => $this->getTeleHealthLaunchDataAction($queryVars),
            'set_appointment_status' => $this->setAppointmentStatusAction($queryVars),
            'set_current_appt_encounter' => $this->setCurrentAppointmentEncounter($queryVars),
            'conference_session_update' => $this->conferenceSessionUpdateAction($queryVars),
            'patient_appointment_ready' => $this->patientAppointmentReadyAction($queryVars),
            'get_telehealth_settings' => $this->getTeleHealthFrontendSettingsAction($queryVars),
            default => $this->handleInvalidAction($action)
        };
    }

    private function getTeleHealthLaunchDataAction(array $queryVars): void
    {
        try {
            $pc_eid = $queryVars['pc_eid'] ?? null;
            if (empty($pc_eid)) {
                throw new InvalidArgumentException("pc_eid is required");
            }

            $appt = $this->appointmentService->getAppointment($pc_eid);
            if (empty($appt)) {
                throw new InvalidArgumentException("Appointment not found for pc_eid: " . $pc_eid);
            }
            $appt = $appt[0];

            // Verify access
            if ($this->isPatient) {
                $pid = $queryVars['pid'] ?? null;
                if (intval($appt['pc_pid']) !== intval($pid)) {
                    throw new AccessDeniedException('patient', 'demo', 'Patient cannot access this appointment');
                }
                $displayName = $this->getPatientDisplayName($pid);
                $email = $this->getPatientEmail($pid);
                $role = 'patient';
                $isModerator = false;
            } else {
                $authUser = $queryVars['authUser'] ?? null;
                $userService = new UserService();
                $user = $userService->getUserByUsername($authUser);
                if (empty($user)) {
                    throw new AccessDeniedException('user', 'demo', 'User not found');
                }
                $displayName = ($user['fname'] ?? '') . ' ' . ($user['lname'] ?? '');
                $displayName = trim($displayName) ?: $authUser;
                $email = $user['email'] ?? '';
                $role = 'provider';
                $isModerator = true;
            }

            // Get or create session
            $session = $this->sessionRepository->getOrCreateSessionByAppointmentId(
                $pc_eid,
                $appt['pc_aid'] ?? null,
                0,
                $appt['pc_pid'] ?? null
            );

            // Generate room name from appointment
            $roomName = $this->generateRoomName($pc_eid, $session);

            // Build Jitsi URL
            $jitsiDomain = $this->config->getJitsiServerDomain();

            // Generate JWT if enabled
            $jwt = null;
            if ($this->config->isJwtEnabled()) {
                $jwt = JitsiJwtHelper::generateToken(
                    $this->config->getJwtAppId(),
                    $this->config->getJwtAppSecret(),
                    $roomName,
                    $displayName,
                    $email,
                    $isModerator
                );
            }

            // Update session timestamps
            if ($this->isPatient) {
                $this->sessionRepository->updateStartTimestamp($pc_eid, 'patient');
            } else {
                $this->sessionRepository->updateStartTimestamp($pc_eid, 'provider');
                // Set the encounter and patient in session for the provider
                if (!empty($appt['pc_pid'])) {
                    PatientSessionUtil::setSessionPatientId($appt['pc_pid']);
                }
                $encounter = $this->getOrCreateEncounter($appt);
                if (!empty($encounter)) {
                    EncounterSessionUtil::setSessionEncounterValue($encounter);
                }
            }

            // Build configuration for the frontend
            $configData = [
                'jitsiDomain' => $jitsiDomain,
                'roomName' => $roomName,
                'jwt' => $jwt,
                'displayName' => $displayName,
                'email' => $email,
                'role' => $role,
                'isModerator' => $isModerator,
                'pc_eid' => $pc_eid,
                'pid' => $appt['pc_pid'],
                'enableLobby' => $this->config->isLobbyEnabled(),
                'enableChat' => $this->config->isChatEnabled(),
                'enableScreenSharing' => $this->config->isScreenSharingEnabled(),
                'enableRecording' => $this->config->isRecordingEnabled(),
                'defaultLanguage' => $this->config->getDefaultLanguage(),
                'requireDisplayName' => $this->config->isDisplayNameRequired(),
            ];

            header('Content-Type: application/json');
            echo json_encode($configData);
        } catch (AccessDeniedException $exception) {
            $this->logger->error($exception->getMessage());
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
        } catch (InvalidArgumentException $exception) {
            $this->logger->error($exception->getMessage());
            http_response_code(400);
            echo json_encode(['error' => $exception->getMessage()]);
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage(), ['trace' => $exception->getTraceAsString()]);
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }

    private function setAppointmentStatusAction(array $queryVars): void
    {
        try {
            if (!$this->verifyCsrf($queryVars)) {
                throw new AccessDeniedException('user', 'demo', 'CSRF validation failed');
            }

            $pc_eid = $queryVars['pc_eid'] ?? null;
            $status = $queryVars['status'] ?? null;

            if (empty($pc_eid) || empty($status)) {
                throw new InvalidArgumentException("pc_eid and status are required");
            }

            $appt = $this->appointmentService->getAppointment($pc_eid);
            if (empty($appt)) {
                throw new InvalidArgumentException("Appointment not found");
            }

            $this->appointmentService->updateAppointmentStatus($pc_eid, $status);

            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'status' => $status]);
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());
            http_response_code(400);
            echo json_encode(['error' => $exception->getMessage()]);
        }
    }

    private function setCurrentAppointmentEncounter(array $queryVars): void
    {
        try {
            $pc_eid = $queryVars['pc_eid'] ?? null;
            if (empty($pc_eid)) {
                throw new InvalidArgumentException("pc_eid is required");
            }

            $appt = $this->appointmentService->getAppointment($pc_eid);
            if (empty($appt)) {
                throw new InvalidArgumentException("Appointment not found");
            }
            $appt = $appt[0];

            if (!empty($appt['pc_pid'])) {
                PatientSessionUtil::setSessionPatientId($appt['pc_pid']);
            }

            $encounter = $this->getOrCreateEncounter($appt);
            if (!empty($encounter)) {
                EncounterSessionUtil::setSessionEncounterValue($encounter);
            }

            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'encounter' => $encounter]);
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());
            http_response_code(400);
            echo json_encode(['error' => $exception->getMessage()]);
        }
    }

    private function conferenceSessionUpdateAction(array $queryVars): void
    {
        try {
            $pc_eid = $queryVars['pc_eid'] ?? null;
            if (empty($pc_eid)) {
                throw new InvalidArgumentException("pc_eid is required");
            }

            $role = $this->isPatient ? 'patient' : 'provider';
            $this->sessionRepository->updateLastSeenTimestamp($pc_eid, $role);

            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());
            http_response_code(400);
            echo json_encode(['error' => $exception->getMessage()]);
        }
    }

    private function patientAppointmentReadyAction(array $queryVars): void
    {
        try {
            $pc_eid = $queryVars['pc_eid'] ?? null;
            $pid = $queryVars['pid'] ?? null;

            if (empty($pc_eid)) {
                throw new InvalidArgumentException("pc_eid is required");
            }

            $session = $this->sessionRepository->getSessionByAppointmentId($pc_eid);
            if (empty($session)) {
                header('Content-Type: application/json');
                echo json_encode(['providerReady' => false]);
                return;
            }

            // Verify patient can access
            if ($this->isPatient && intval($session['pid']) !== intval($pid)) {
                throw new AccessDeniedException('patient', 'demo', 'Access denied');
            }

            $providerReady = CalendarUtils::isTelehealthSessionInActiveTimeRange($session);

            header('Content-Type: application/json');
            echo json_encode(['providerReady' => $providerReady]);
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());
            http_response_code(400);
            echo json_encode(['error' => $exception->getMessage()]);
        }
    }

    private function getTeleHealthFrontendSettingsAction(array $queryVars): void
    {
        $settings = [
            'jitsiDomain' => $this->config->getJitsiServerDomain(),
            'enableLobby' => $this->config->isLobbyEnabled(),
            'enableChat' => $this->config->isChatEnabled(),
            'enableScreenSharing' => $this->config->isScreenSharingEnabled(),
            'enableRecording' => $this->config->isRecordingEnabled(),
            'defaultLanguage' => $this->config->getDefaultLanguage(),
            'requireDisplayName' => $this->config->isDisplayNameRequired(),
            'isPatientPortalEnabled' => $this->config->isPatientPortalEnabled(),
        ];

        // Serve as JavaScript so it can be loaded as a script tag
        header('Content-Type: application/javascript');
        echo "window.jitsiTelehealthSettings = " . json_encode($settings) . ";\n";
    }

    private function handleInvalidAction(string $action): void
    {
        $this->logger->error("Invalid action received: " . $action);
        http_response_code(404);
        echo json_encode(['error' => 'Unknown action: ' . $action]);
    }

    private function generateRoomName(string $pc_eid, ?array $session): string
    {
        $prefix = $this->config->getRoomPrefix();
        $hash = substr(hash('sha256', $pc_eid . ($session['date_created'] ?? '') . 'jitsi-telehealth'), 0, 12);
        return $prefix . '-appt-' . $pc_eid . '-' . $hash;
    }

    private function getPatientDisplayName($pid): string
    {
        $patientService = new PatientService();
        $patient = $patientService->findByPid($pid);
        if (!empty($patient)) {
            $name = ($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? '');
            return trim($name) ?: 'Patient';
        }
        return 'Patient';
    }

    private function getPatientEmail($pid): string
    {
        $patientService = new PatientService();
        $patient = $patientService->findByPid($pid);
        return $patient['email'] ?? '';
    }

    private function getOrCreateEncounter(array $appt): ?int
    {
        $pc_eid = $appt['pc_eid'];
        $pid = $appt['pc_pid'];
        $providerId = $appt['pc_aid'];
        $dateStr = $appt['pc_eventDate'];

        // Check for existing encounter on same day
        $encounterService = new EncounterService();
        $existingEncounters = $encounterService->getEncountersForPatientByPid($pid);
        if (!empty($existingEncounters)) {
            foreach ($existingEncounters as $enc) {
                $encDate = date('Y-m-d', strtotime($enc['date']));
                if ($encDate == $dateStr) {
                    return intval($enc['encounter']);
                }
            }
        }

        // Create new encounter for telehealth
        $encounterData = [
            'date' => $dateStr,
            'reason' => 'Telehealth Visit - Jitsi',
            'facility_id' => $appt['pc_facility'] ?? '',
            'pc_catid' => $appt['pc_catid'] ?? '',
            'billing_facility' => $appt['pc_billing_location'] ?? '',
            'sensitivity' => 'normal',
            'pid' => $pid,
            'provider_id' => $providerId,
        ];

        $result = $encounterService->insertEncounter($pid, $encounterData);
        if ($result->hasData()) {
            $data = $result->getData();
            return intval($data[0]['encounter'] ?? 0) ?: null;
        }

        return null;
    }

    private function verifyCsrf(array $queryVars): bool
    {
        $csrfToken = $queryVars['csrf_token'] ?? '';
        return CsrfUtils::verifyCsrfToken($csrfToken);
    }
}
