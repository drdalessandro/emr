<?php

/**
 * Bootstrap connects the Jitsi TeleHealth module to the OpenEMR event system.
 *
 * @package   openemr
 * @link      http://www.open-emr.org
 * @author    EPA Bienestar
 * @copyright Copyright (c) 2024 EPA Bienestar
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace EPA\OpenEMR\Modules\JitsiTeleHealth;

use EPA\OpenEMR\Modules\JitsiTeleHealth\Controller\JitsiCalendarController;
use EPA\OpenEMR\Modules\JitsiTeleHealth\Controller\JitsiConferenceRoomController;
use EPA\OpenEMR\Modules\JitsiTeleHealth\Controller\JitsiPatientPortalController;
use EPA\OpenEMR\Modules\JitsiTeleHealth\Repository\JitsiCalendarEventCategoryRepository;
use EPA\OpenEMR\Modules\JitsiTeleHealth\Repository\JitsiSessionRepository;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Common\Utils\CacheUtils;
use OpenEMR\Core\Kernel;
use OpenEMR\Events\Appointments\AppointmentSetEvent;
use OpenEMR\Events\Core\TwigEnvironmentEvent;
use OpenEMR\Events\Globals\GlobalsInitializedEvent;
use OpenEMR\Events\Main\Tabs\RenderEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class Bootstrap
{
    const MODULE_INSTALLATION_PATH = "/interface/modules/custom_modules/";
    const MODULE_NAME = "oe-module-jitsi-telehealth";
    const MODULE_MENU_NAME = "Jitsi TeleHealth";

    private $moduleDirectoryName;

    /**
     * @var Environment
     */
    private $twig;

    /**
     * @var JitsiGlobalConfig
     */
    private $globalsConfig;

    /**
     * @var JitsiCalendarController
     */
    private $calendarController;

    /**
     * @var JitsiPatientPortalController
     */
    private $patientPortalController;

    /**
     * @var SystemLogger
     */
    private $logger;

    public function __construct(
        private readonly EventDispatcher $eventDispatcher,
        ?Kernel $kernel = null
    ) {
        if (empty($kernel)) {
            $kernel = new Kernel();
        }
        $twig = new TwigContainer($this->getTemplatePath(), $kernel);
        $twigEnv = $twig->getTwig();
        $this->twig = $twigEnv;

        $this->moduleDirectoryName = basename(dirname(__DIR__));
        $this->logger = new SystemLogger();
        $this->globalsConfig = new JitsiGlobalConfig($this->getURLPath(), $this->moduleDirectoryName);
    }

    public function getGlobalConfig(): JitsiGlobalConfig
    {
        return $this->globalsConfig;
    }

    public function getTemplatePath(): string
    {
        return \dirname(__DIR__) . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR;
    }

    public function getURLPath(): string
    {
        return $GLOBALS['webroot'] . self::MODULE_INSTALLATION_PATH . $this->moduleDirectoryName . "/public/";
    }

    public function getTwig(): Environment
    {
        return $this->twig;
    }

    public function subscribeToEvents(): void
    {
        $this->addGlobalSettings();

        if ($this->globalsConfig->isJitsiConfigured()) {
            $this->subscribeToTemplateEvents();
            $this->subscribeToAppointmentEvents();
            $this->getCalendarController()->subscribeToEvents($this->eventDispatcher);
            $this->getPatientPortalController()->subscribeToEvents($this->eventDispatcher);
        }
    }

    public function subscribeToTemplateEvents(): void
    {
        $this->eventDispatcher->addListener(TwigEnvironmentEvent::EVENT_CREATED, $this->addTemplateOverrideLoader(...));
        $this->eventDispatcher->addListener(RenderEvent::EVENT_BODY_RENDER_POST, $this->renderMainBodyScripts(...));
    }

    public function subscribeToAppointmentEvents(): void
    {
        $this->eventDispatcher->addListener(AppointmentSetEvent::EVENT_HANDLE, $this->createSessionRecord(...), 10);
    }

    public function addTemplateOverrideLoader(TwigEnvironmentEvent $event): void
    {
        $twig = $event->getTwigEnvironment();
        if ($twig === $this->twig) {
            return;
        }
        $loader = $twig->getLoader();
        if ($loader instanceof FilesystemLoader) {
            $loader->prependPath($this->getTemplatePath());
        }
    }

    public function renderMainBodyScripts(): void
    {
        // Render the conference room HTML containers first
        echo $this->twig->render('jitsi/conference-room.twig', []);
        ?>
        <script src="https://<?php echo attr($this->globalsConfig->getJitsiServerDomain()); ?>/external_api.js"></script>
        <script src="<?php echo $this->getAssetPath(); ?>../<?php echo CacheUtils::addAssetCacheParamToPath("index.php"); ?>&action=get_telehealth_settings"></script>
        <link rel="stylesheet" href="<?php echo $this->getAssetPath(); ?>css/<?php echo CacheUtils::addAssetCacheParamToPath("jitsi-telehealth.css"); ?>">
        <script src="<?php echo $this->getAssetPath(); ?>js/<?php echo CacheUtils::addAssetCacheParamToPath("jitsi-telehealth.js"); ?>"></script>
        <?php
    }

    public function createSessionRecord(AppointmentSetEvent $event): void
    {
        $pc_catid = $event->givenAppointmentData()['pc_catid'] ?? null;
        $calCatRepo = new JitsiCalendarEventCategoryRepository();
        if (empty($calCatRepo->getEventCategoryForId($pc_catid))) {
            return;
        }

        $sessionRepo = new JitsiSessionRepository();
        $sessionRepo->getOrCreateSessionByAppointmentId($event->eid);
    }

    public function addGlobalSettings(): void
    {
        $this->eventDispatcher->addListener(GlobalsInitializedEvent::EVENT_HANDLE, $this->addGlobalJitsiSettings(...));
    }

    public function addGlobalJitsiSettings(GlobalsInitializedEvent $event): void
    {
        $service = $event->getGlobalsService();
        $this->globalsConfig->setupConfiguration($service);
    }

    public function getConferenceRoomController(bool $isPatient): JitsiConferenceRoomController
    {
        return new JitsiConferenceRoomController(
            $this->getTwig(),
            new SystemLogger(),
            $this->globalsConfig,
            $this->getAssetPath(),
            $isPatient
        );
    }

    public function getCalendarController(): JitsiCalendarController
    {
        if (empty($this->calendarController)) {
            $this->calendarController = new JitsiCalendarController(
                $this->globalsConfig,
                $this->getTwig(),
                $this->logger,
                $this->getAssetPath(),
                $this->getCurrentLoggedInUser()
            );
        }
        return $this->calendarController;
    }

    public function getPatientPortalController(): JitsiPatientPortalController
    {
        if (empty($this->patientPortalController)) {
            $this->patientPortalController = new JitsiPatientPortalController(
                $this->twig,
                $this->getAssetPath(),
                $this->globalsConfig
            );
        }
        return $this->patientPortalController;
    }

    public function getCurrentLoggedInUser()
    {
        return $_SESSION['authUserID'] ?? null;
    }

    private function getAssetPath(): string
    {
        return $this->getURLPath() . 'assets' . '/';
    }
}
