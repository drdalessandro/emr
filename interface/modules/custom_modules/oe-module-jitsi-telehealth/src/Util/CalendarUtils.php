<?php

/**
 * Calendar utility methods for Jitsi telehealth appointment handling.
 *
 * @package   openemr
 * @link      http://www.open-emr.org
 * @author    EPA Bienestar
 * @copyright Copyright (c) 2024 EPA Bienestar
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace EPA\OpenEMR\Modules\JitsiTeleHealth\Util;

use OpenEMR\Common\Logging\SystemLogger;

class CalendarUtils
{
    /**
     * Checks if the given date is within the two-hour safe range for a TeleHealth appointment.
     */
    public static function isAppointmentDateTimeInSafeRange(\DateTime $dateTime): bool
    {
        $beforeTime = (new \DateTime())->sub(new \DateInterval("PT2H"));
        $afterTime = (new \DateTime())->add(new \DateInterval("PT2H"));
        return $dateTime >= $beforeTime && $dateTime <= $afterTime;
    }

    /**
     * Checks if a telehealth session is currently in active time range (provider has been seen recently).
     */
    public static function isTelehealthSessionInActiveTimeRange(array $session): bool
    {
        if (empty($session['provider_last_update'])) {
            return false;
        }
        $dateTime = \DateTime::createFromFormat("Y-m-d H:i:s", $session['provider_last_update']);
        if ($dateTime === false) {
            return false;
        }
        return self::isUserLastSeenTimeInActiveRange($dateTime);
    }

    /**
     * Checks if a user's last seen timestamp is within the active range (15 seconds).
     */
    public static function isUserLastSeenTimeInActiveRange(\DateTime $dateTime): bool
    {
        $currentDateTime = new \DateTime();
        return $currentDateTime < $dateTime->add(new \DateInterval("PT15S"));
    }
}
