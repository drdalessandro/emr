<?php

/**
 * Manages Jitsi telehealth session records in the database.
 *
 * @package   openemr
 * @link      http://www.open-emr.org
 * @author    EPA Bienestar
 * @copyright Copyright (c) 2024 EPA Bienestar
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace EPA\OpenEMR\Modules\JitsiTeleHealth\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use InvalidArgumentException;

class JitsiSessionRepository
{
    const TABLE_NAME = "jitsi_telehealth_session";

    public function getOrCreateSessionByAppointmentId($pc_eid, $user_id = null, $encounter = 0, $pid = null): ?array
    {
        $session = $this->getSessionByAppointmentId($pc_eid);
        if (!empty($session)) {
            return $session;
        }

        if (!empty($user_id) && !empty($pid)) {
            return $this->createSession($pc_eid, $user_id, $encounter, $pid);
        }

        return null;
    }

    public function createSession($pc_eid, $user_id, $encounter, $pid): ?array
    {
        $sql = "INSERT INTO " . self::TABLE_NAME . " (pc_eid, user_id, encounter, pid) VALUES (?,?,?,?)";
        (new SystemLogger())->debug(
            "JitsiSessionRepository: Creating session",
            ['pc_eid' => $pc_eid, 'user_id' => $user_id, 'encounter' => $encounter, 'pid' => $pid]
        );
        QueryUtils::sqlInsert($sql, [$pc_eid, $user_id, $encounter, $pid]);
        return $this->getSessionByAppointmentId($pc_eid);
    }

    public function getSessionByAppointmentId($pc_eid, $user_id = null): ?array
    {
        $sql = "SELECT * FROM " . self::TABLE_NAME . " WHERE pc_eid = ?";
        $binds = [$pc_eid];
        if (!empty($user_id)) {
            $sql .= " AND user_id = ?";
            $binds[] = $user_id;
        }
        $records = QueryUtils::fetchRecords($sql, $binds);
        if (!empty($records)) {
            return $records[0];
        }
        return null;
    }

    public function updateStartTimestamp($pc_eid, string $role = 'provider'): void
    {
        $validRoles = ['provider', 'patient'];
        if (!in_array($role, $validRoles)) {
            throw new InvalidArgumentException("Invalid role: " . $role);
        }

        $sql = "UPDATE " . self::TABLE_NAME . " SET " . $role . "_start_time = NOW() WHERE pc_eid = ?";
        QueryUtils::sqlStatementThrowException($sql, [$pc_eid]);
    }

    public function updateLastSeenTimestamp($pc_eid, string $role): void
    {
        $validRoles = ['provider', 'patient'];
        if (!in_array($role, $validRoles)) {
            throw new InvalidArgumentException("Invalid role: " . $role);
        }

        $sql = "UPDATE " . self::TABLE_NAME . " SET " . $role . "_last_update = NOW() WHERE pc_eid = ?";
        QueryUtils::sqlStatementThrowException($sql, [$pc_eid]);
    }

    public function updateEncounter($pc_eid, int $encounter): void
    {
        $sql = "UPDATE " . self::TABLE_NAME . " SET encounter = ? WHERE pc_eid = ?";
        QueryUtils::sqlStatementThrowException($sql, [$encounter, $pc_eid]);
    }
}
