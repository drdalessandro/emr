<?php

/**
 * Contains all of the Jitsi TeleHealth global settings and configuration.
 *
 * @package   openemr
 * @link      http://www.open-emr.org
 * @author    EPA Bienestar
 * @copyright Copyright (c) 2024 EPA Bienestar
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace EPA\OpenEMR\Modules\JitsiTeleHealth;

use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Services\Globals\GlobalSetting;
use OpenEMR\Services\Globals\GlobalsService;

class JitsiGlobalConfig
{
    public const MODULE_INSTALLATION_PATH = "/interface/modules/custom_modules/";

    // Global setting keys
    public const JITSI_SERVER_DOMAIN = 'jitsi_telehealth_server_domain';
    public const JITSI_JWT_APP_ID = 'jitsi_telehealth_jwt_app_id';
    public const JITSI_JWT_APP_SECRET = 'jitsi_telehealth_jwt_app_secret';
    public const JITSI_ROOM_PREFIX = 'jitsi_telehealth_room_prefix';
    public const JITSI_ENABLE_JWT = 'jitsi_telehealth_enable_jwt';
    public const JITSI_ENABLE_LOBBY = 'jitsi_telehealth_enable_lobby';
    public const JITSI_ENABLE_RECORDING = 'jitsi_telehealth_enable_recording';
    public const JITSI_ENABLE_SCREEN_SHARING = 'jitsi_telehealth_enable_screenshare';
    public const JITSI_DEFAULT_LANGUAGE = 'jitsi_telehealth_default_language';
    public const JITSI_REQUIRE_DISPLAY_NAME = 'jitsi_telehealth_require_display_name';
    public const JITSI_ENABLE_CHAT = 'jitsi_telehealth_enable_chat';
    public const JITSI_ENABLE_PATIENT_PORTAL = 'jitsi_telehealth_enable_patient_portal';

    /**
     * @var CryptoGen
     */
    private $cryptoGen;

    /**
     * @var string
     */
    private $publicWebPath;

    public function __construct($publicWebPath, $moduleDirectoryName)
    {
        $this->cryptoGen = new CryptoGen();
        $this->publicWebPath = $publicWebPath;
    }

    public function getJitsiServerDomain(): string
    {
        return $this->getGlobalSetting(self::JITSI_SERVER_DOMAIN) ?? 'meet.epa-bienestar.com.ar';
    }

    public function getJwtAppId(): string
    {
        return $this->getGlobalSetting(self::JITSI_JWT_APP_ID) ?? '';
    }

    public function getJwtAppSecret(): string
    {
        $encryptedValue = $this->getGlobalSetting(self::JITSI_JWT_APP_SECRET);
        if (empty($encryptedValue)) {
            return '';
        }
        return $this->cryptoGen->decryptStandard($encryptedValue) ?: '';
    }

    public function getRoomPrefix(): string
    {
        return $this->getGlobalSetting(self::JITSI_ROOM_PREFIX) ?? 'openemr';
    }

    public function isJwtEnabled(): bool
    {
        return $this->getGlobalSetting(self::JITSI_ENABLE_JWT) == '1';
    }

    public function isLobbyEnabled(): bool
    {
        return $this->getGlobalSetting(self::JITSI_ENABLE_LOBBY) == '1';
    }

    public function isRecordingEnabled(): bool
    {
        return $this->getGlobalSetting(self::JITSI_ENABLE_RECORDING) == '1';
    }

    public function isScreenSharingEnabled(): bool
    {
        $setting = $this->getGlobalSetting(self::JITSI_ENABLE_SCREEN_SHARING);
        return $setting === null || $setting == '1';
    }

    public function getDefaultLanguage(): string
    {
        return $this->getGlobalSetting(self::JITSI_DEFAULT_LANGUAGE) ?? 'es';
    }

    public function isDisplayNameRequired(): bool
    {
        $setting = $this->getGlobalSetting(self::JITSI_REQUIRE_DISPLAY_NAME);
        return $setting === null || $setting == '1';
    }

    public function isChatEnabled(): bool
    {
        $setting = $this->getGlobalSetting(self::JITSI_ENABLE_CHAT);
        return $setting === null || $setting == '1';
    }

    public function isPatientPortalEnabled(): bool
    {
        $setting = $this->getGlobalSetting(self::JITSI_ENABLE_PATIENT_PORTAL);
        return $setting === null || $setting == '1';
    }

    public function getPublicWebPath(): string
    {
        return $this->publicWebPath;
    }

    /**
     * Checks if the core Jitsi configuration is properly set up.
     */
    public function isJitsiConfigured(): bool
    {
        $domain = $this->getGlobalSetting(self::JITSI_SERVER_DOMAIN);
        if (empty($domain)) {
            (new SystemLogger())->debug("Jitsi TeleHealth is missing server domain configuration");
            return false;
        }

        if ($this->isJwtEnabled()) {
            $appId = $this->getGlobalSetting(self::JITSI_JWT_APP_ID);
            $appSecret = $this->getGlobalSetting(self::JITSI_JWT_APP_SECRET);
            if (empty($appId) || empty($appSecret)) {
                (new SystemLogger())->debug("Jitsi TeleHealth JWT is enabled but app ID or secret is missing");
                return false;
            }
        }

        return true;
    }

    public function getGlobalSetting($settingKey)
    {
        global $GLOBALS;
        return $GLOBALS[$settingKey] ?? null;
    }

    public function getGlobalSettingSectionConfiguration(): array
    {
        $settings = [
            self::JITSI_SERVER_DOMAIN => [
                'title' => 'Jitsi Server Domain'
                ,'description' => 'The domain of your Jitsi Meet server (e.g. meet.epa-bienestar.com.ar)'
                ,'type' => GlobalSetting::DATA_TYPE_TEXT
                ,'default' => 'meet.epa-bienestar.com.ar'
            ]
            ,self::JITSI_ROOM_PREFIX => [
                'title' => 'Room Name Prefix'
                ,'description' => 'Prefix for Jitsi room names to avoid collisions (e.g. openemr)'
                ,'type' => GlobalSetting::DATA_TYPE_TEXT
                ,'default' => 'epa-bienestar'
            ]
            ,self::JITSI_ENABLE_JWT => [
                'title' => 'Enable JWT Authentication'
                ,'description' => 'Enable JWT token authentication for Jitsi rooms (requires Jitsi JWT plugin configured on server)'
                ,'type' => GlobalSetting::DATA_TYPE_BOOL
                ,'default' => ''
            ]
            ,self::JITSI_JWT_APP_ID => [
                'title' => 'JWT Application ID'
                ,'description' => 'The application ID configured in your Jitsi server for JWT authentication'
                ,'type' => GlobalSetting::DATA_TYPE_TEXT
                ,'default' => ''
            ]
            ,self::JITSI_JWT_APP_SECRET => [
                'title' => 'JWT Application Secret (Encrypted)'
                ,'description' => 'The shared secret configured in your Jitsi server for JWT token generation'
                ,'type' => GlobalSetting::DATA_TYPE_ENCRYPTED
                ,'default' => ''
            ]
            ,self::JITSI_DEFAULT_LANGUAGE => [
                'title' => 'Default Language'
                ,'description' => 'Default language for the Jitsi Meet interface'
                ,'default' => 'es'
                ,'type' => [
                    'es' => xl('Spanish')
                    ,'en' => xl('English')
                    ,'pt' => xl('Portuguese')
                    ,'fr' => xl('French')
                    ,'de' => xl('German')
                    ,'it' => xl('Italian')
                ]
            ]
            ,self::JITSI_ENABLE_LOBBY => [
                'title' => 'Enable Lobby / Waiting Room'
                ,'description' => 'Patients wait in a lobby until the provider admits them'
                ,'type' => GlobalSetting::DATA_TYPE_BOOL
                ,'default' => '1'
            ]
            ,self::JITSI_ENABLE_CHAT => [
                'title' => 'Enable In-Session Chat'
                ,'description' => 'Allow text chat during video sessions'
                ,'type' => GlobalSetting::DATA_TYPE_BOOL
                ,'default' => '1'
            ]
            ,self::JITSI_ENABLE_SCREEN_SHARING => [
                'title' => 'Enable Screen Sharing'
                ,'description' => 'Allow screen sharing during video sessions'
                ,'type' => GlobalSetting::DATA_TYPE_BOOL
                ,'default' => '1'
            ]
            ,self::JITSI_ENABLE_RECORDING => [
                'title' => 'Enable Recording'
                ,'description' => 'Allow session recording (requires Jibri configured on server)'
                ,'type' => GlobalSetting::DATA_TYPE_BOOL
                ,'default' => ''
            ]
            ,self::JITSI_REQUIRE_DISPLAY_NAME => [
                'title' => 'Require Display Name'
                ,'description' => 'Require participants to set a display name before joining'
                ,'type' => GlobalSetting::DATA_TYPE_BOOL
                ,'default' => '1'
            ]
            ,self::JITSI_ENABLE_PATIENT_PORTAL => [
                'title' => 'Enable Patient Portal Telehealth'
                ,'description' => 'Allow patients to join telehealth sessions from the patient portal'
                ,'type' => GlobalSetting::DATA_TYPE_BOOL
                ,'default' => '1'
            ]
        ];
        return $settings;
    }

    public function setupConfiguration(GlobalsService $service): void
    {
        global $GLOBALS;
        $section = xlt("Jitsi TeleHealth");
        $service->createSection($section, 'Portal');

        $settings = $this->getGlobalSettingSectionConfiguration();

        foreach ($settings as $key => $config) {
            $value = $GLOBALS[$key] ?? $config['default'];
            $setting = new GlobalSetting(
                xlt($config['title']),
                $config['type'],
                $value,
                xlt($config['description']),
                true
            );
            $service->appendToSection(
                $section,
                $key,
                $setting
            );
        }
    }
}
