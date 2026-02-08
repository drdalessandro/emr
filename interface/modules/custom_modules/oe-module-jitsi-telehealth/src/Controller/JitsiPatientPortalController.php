<?php

/**
 * Responsible for rendering Jitsi TeleHealth features on the patient portal.
 *
 * @package   openemr
 * @link      http://www.open-emr.org
 * @author    EPA Bienestar
 * @copyright Copyright (c) 2024 EPA Bienestar
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace EPA\OpenEMR\Modules\JitsiTeleHealth\Controller;

use EPA\OpenEMR\Modules\JitsiTeleHealth\JitsiGlobalConfig;
use EPA\OpenEMR\Modules\JitsiTeleHealth\Util\CalendarUtils;
use OpenEMR\Events\PatientPortal\AppointmentFilterEvent;
use OpenEMR\Events\PatientPortal\RenderEvent;
use OpenEMR\Services\AppointmentService;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\GenericEvent;
use Twig\Environment;

class JitsiPatientPortalController
{
    public function __construct(
        private readonly Environment $twig,
        private $assetPath,
        private readonly JitsiGlobalConfig $config
    ) {
    }

    public function subscribeToEvents(EventDispatcher $eventDispatcher): void
    {
        if (!$this->config->isPatientPortalEnabled()) {
            return;
        }
        $eventDispatcher->addListener(AppointmentFilterEvent::EVENT_NAME, $this->filterPatientAppointment(...));
        $eventDispatcher->addListener(RenderEvent::EVENT_SECTION_RENDER_POST, $this->renderTeleHealthPatientVideo(...));
    }

    public function renderTeleHealthPatientVideo(GenericEvent $event): void
    {
        $data = [
            'assetPath' => $this->assetPath,
            'jitsiDomain' => $this->config->getJitsiServerDomain(),
        ];
        echo $this->twig->render('jitsi/patient-portal.twig', $data);
    }

    public function filterPatientAppointment(AppointmentFilterEvent $event): void
    {
        $dbRecord = $event->getDbRecord();
        $appointment = $event->getAppointment();

        $dateTime = \DateTime::createFromFormat(
            "Y-m-d H:i:s",
            $dbRecord['pc_eventDate'] . " " . $dbRecord['pc_startTime']
        );

        $apptService = new AppointmentService();

        $appointment['showTelehealth'] = false;
        if (
            $dateTime !== false && CalendarUtils::isAppointmentDateTimeInSafeRange($dateTime)
        ) {
            if (
                $apptService->isCheckOutStatus($dbRecord['pc_apptstatus'])
                || $apptService->isPendingStatus($dbRecord['pc_apptstatus'])
            ) {
                $appointment['showTelehealth'] = false;
            } else {
                $appointment['showTelehealth'] = true;
            }
        }
        $event->setAppointment($appointment);
    }
}
