<?php

/**
 * Handles calendar event rendering for Jitsi telehealth appointments.
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
use EPA\OpenEMR\Modules\JitsiTeleHealth\Util\CalendarUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Utils\CacheUtils;
use OpenEMR\Events\Appointments\AppointmentRenderEvent;
use OpenEMR\Events\Appointments\CalendarUserGetEventsFilter;
use OpenEMR\Events\Core\ScriptFilterEvent;
use OpenEMR\Events\Core\StyleFilterEvent;
use OpenEMR\Services\AppointmentService;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Twig\Environment;

class JitsiCalendarController
{
    private $loggedInUserId;
    private JitsiCalendarEventCategoryRepository $calendarEventCategoryRepository;
    private ?AppointmentService $apptService = null;

    public function __construct(
        private readonly JitsiGlobalConfig $config,
        private readonly Environment $twig,
        private readonly SystemLogger $logger,
        private $assetPath,
        $loggedInUserId
    ) {
        $this->loggedInUserId = $loggedInUserId;
        $this->calendarEventCategoryRepository = new JitsiCalendarEventCategoryRepository();
    }

    public function subscribeToEvents(EventDispatcher $eventDispatcher): void
    {
        $eventDispatcher->addListener(CalendarUserGetEventsFilter::EVENT_NAME, $this->filterTelehealthCalendarEvents(...));
        $eventDispatcher->addListener(ScriptFilterEvent::EVENT_NAME, $this->addCalendarJavascript(...));
        $eventDispatcher->addListener(StyleFilterEvent::EVENT_NAME, $this->addCalendarStylesheet(...));
        $eventDispatcher->addListener(AppointmentRenderEvent::RENDER_BELOW_PATIENT, $this->renderAppointmentsLaunchSessionButton(...));
    }

    public function getAppointmentService(): AppointmentService
    {
        if (!isset($this->apptService)) {
            $this->apptService = new AppointmentService();
        }
        return $this->apptService;
    }

    public function filterTelehealthCalendarEvents(CalendarUserGetEventsFilter $event): void
    {
        $eventsByDay = $event->getEventsByDays();
        $keys = array_keys($eventsByDay);
        $apptService = $this->getAppointmentService();

        foreach ($keys as $key) {
            $eventCount = count($eventsByDay[$key]);
            for ($i = 0; $i < $eventCount; $i++) {
                $catId = $eventsByDay[$key][$i]['catid'];
                if (!empty($this->calendarEventCategoryRepository->getEventCategoryForId($catId))) {
                    $eventViewClasses = ["event_appointment", "event_telehealth", "event_jitsi_telehealth"];
                    $dateTime = \DateTime::createFromFormat(
                        "Y-m-d H:i:s",
                        $eventsByDay[$key][$i]['eventDate'] . " " . $eventsByDay[$key][$i]['startTime']
                    );

                    if ($eventsByDay[$key][$i]['aid'] != $this->loggedInUserId) {
                        $eventViewClasses[] = "event_user_different";
                    }

                    if ($apptService->isCheckOutStatus($eventsByDay[$key][$i]['apptstatus'])) {
                        $eventViewClasses[] = "event_telehealth_completed";
                    } else if ($dateTime !== false && CalendarUtils::isAppointmentDateTimeInSafeRange($dateTime)) {
                        $eventViewClasses[] = "event_telehealth_active";
                    }

                    $eventsByDay[$key][$i]['eventViewClass'] = implode(" ", $eventViewClasses);
                }
            }
        }
        $event->setEventsByDays($eventsByDay);
    }

    public function addCalendarStylesheet(StyleFilterEvent $event): void
    {
        if ($this->isCalendarPageInclude($event->getPageName())) {
            $styles = $event->getStyles();
            $styles[] = $this->assetPath . CacheUtils::addAssetCacheParamToPath("css/jitsi-telehealth.css");
            $event->setStyles($styles);
        }
    }

    public function addCalendarJavascript(ScriptFilterEvent $event): void
    {
        $pageName = $event->getPageName();

        if ($this->isCalendarPageInclude($pageName) || $this->isAppointmentPageInclude($pageName)) {
            $scripts = $event->getScripts();
            $scripts[] = $this->assetPath . "../index.php?action=get_telehealth_settings";
            $scripts[] = $this->assetPath . "js/jitsi-telehealth.js";
            $event->setScripts($scripts);
        }
    }

    public function renderAppointmentsLaunchSessionButton(AppointmentRenderEvent $event): void
    {
        $row = $event->getAppt();
        if (empty($row['pc_eid'])) {
            return;
        }
        if (empty($this->calendarEventCategoryRepository->getEventCategoryForId($row['pc_catid']))) {
            return;
        }

        // Don't show launch button for completed status
        if ($this->getAppointmentService()->isCheckOutStatus($row['pc_apptstatus'])) {
            echo "<button class='mt-2 btn btn-disabled' disabled><i class='fa fa-video m-2'></i>"
                . xlt("Jitsi TeleHealth Session Ended") . "</button>";
            echo "<p>" . xlt("Session has been completed.") . " "
                . xlt("Change the appointment status in order to launch this session again.") . "</p>";
            return;
        }

        $eventDateTimeString = $row['pc_eventDate'] . " " . $row['pc_startTime'];
        $dateTime = \DateTime::createFromFormat("Y-m-d H:i:s", $eventDateTimeString);
        if ($dateTime === false) {
            (new SystemLogger())->errorLogCaller(
                "appointment date time string was invalid",
                ['pc_eid' => $row['pc_eid'], 'dateTime' => $eventDateTimeString]
            );
            return;
        }

        if (CalendarUtils::isAppointmentDateTimeInSafeRange($dateTime)) {
            echo "<button data-eid='" . attr($row['pc_eid']) . "' data-pid='" . attr($row['pc_pid'])
                . "' class='mt-2 btn btn-primary btn-jitsi-launch-telehealth'><i class='fa fa-video m-2'></i>"
                . xlt("Launch Jitsi TeleHealth") . "</button>";
        } else {
            echo "<button class='mt-2 btn btn-disabled' disabled><i class='fa fa-video m-2'></i>"
                . xlt("TeleHealth Session Expired") . "</button>";
            echo "<p>" . xlt("Session can only be launched two hours before or after an appointment") . "</p>";
        }
    }

    private function isCalendarPageInclude(string $pageName): bool
    {
        return $pageName == 'pnuserapi.php' || $pageName == 'pnadmin.php';
    }

    private function isAppointmentPageInclude(string $pageName): bool
    {
        return $pageName == "add_edit_event.php";
    }
}
