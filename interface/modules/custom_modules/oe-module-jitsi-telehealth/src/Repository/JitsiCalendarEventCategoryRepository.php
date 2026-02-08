<?php

/**
 * Handles retrieval of calendar event categories specific to Jitsi TeleHealth.
 *
 * @package   openemr
 * @link      http://www.open-emr.org
 * @author    EPA Bienestar
 * @copyright Copyright (c) 2024 EPA Bienestar
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace EPA\OpenEMR\Modules\JitsiTeleHealth\Repository;

use OpenEMR\Services\AppointmentService;

class JitsiCalendarEventCategoryRepository
{
    const TELEHEALTH_EVENT_CATEGORY_CONSTANT_IDS = [
        'jitsi_telehealth_new_patient',
        'jitsi_telehealth_established_patient'
    ];

    private array $categoryEvents = [];

    public function getEventCategoryForId($id)
    {
        $categoryEvents = $this->getEventCategories();
        return $categoryEvents[$id] ?? null;
    }

    public function getEventCategories(bool $skipCache = false): array
    {
        if (!$skipCache && !empty($this->categoryEvents)) {
            return $this->categoryEvents;
        }

        $apptRepo = new AppointmentService();
        $categories = $apptRepo->getCalendarCategories();
        $filteredCategories = [];
        foreach ($categories as $category) {
            if (in_array($category['pc_constant_id'], self::TELEHEALTH_EVENT_CATEGORY_CONSTANT_IDS)) {
                $filteredCategories[$category['pc_catid']] = $category;
            }
        }
        $this->categoryEvents = $filteredCategories;
        return $this->categoryEvents;
    }
}
