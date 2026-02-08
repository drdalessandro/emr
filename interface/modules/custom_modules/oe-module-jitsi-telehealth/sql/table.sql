#IfNotTable jitsi_telehealth_session
CREATE TABLE IF NOT EXISTS `jitsi_telehealth_session`
(
    `id`                    BIGINT(20)       NOT NULL AUTO_INCREMENT,
    `user_id`               BIGINT(20)       NOT NULL COMMENT 'Foreign key reference to users.id (provider)',
    `pc_eid`                INT(11) UNSIGNED NOT NULL COMMENT 'Foreign key reference to openemr_postcalendar_events.pc_eid',
    `encounter`             BIGINT(20)       NOT NULL DEFAULT 0 COMMENT 'Foreign key reference to forms.encounter',
    `pid`                   BIGINT(20)       NOT NULL COMMENT 'Foreign key reference to patient_data.pid',
    `provider_start_time`   DATETIME         DEFAULT NULL COMMENT 'Provider start time',
    `provider_last_update`  DATETIME         DEFAULT NULL COMMENT 'Provider last heartbeat timestamp',
    `patient_start_time`    DATETIME         DEFAULT NULL COMMENT 'Patient join time',
    `patient_last_update`   DATETIME         DEFAULT NULL COMMENT 'Patient last heartbeat timestamp',
    `date_created`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date the session record was created',
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_pc_eid` (`pc_eid`)
) ENGINE = InnoDB COMMENT 'Jitsi telehealth session tracking';
#EndIf

#IfNotRow openemr_postcalendar_categories pc_constant_id jitsi_telehealth_new_patient
INSERT INTO `openemr_postcalendar_categories` (
    `pc_constant_id`, `pc_catname`, `pc_catcolor`, `pc_catdesc`,
    `pc_recurrtype`, `pc_enddate`, `pc_recurrspec`, `pc_recurrfreq`, `pc_duration`,
    `pc_end_date_flag`, `pc_end_date_type`, `pc_end_date_freq`, `pc_end_all_day`,
    `pc_dailylimit`, `pc_cattype`, `pc_active`, `pc_seq`, `aco_spec`
)
VALUES (
    'jitsi_telehealth_new_patient', 'Jitsi TeleHealth - Paciente Nuevo', '#4a90d9'
    , 'Teleconsulta Jitsi para pacientes nuevos', '0', NULL
    , 'a:5:{s:17:"event_repeat_freq";s:1:"0";s:22:"event_repeat_freq_type";s:1:"0";s:19:"event_repeat_on_num";s:1:"1";s:19:"event_repeat_on_day";s:1:"0";s:20:"event_repeat_on_freq";s:1:"0";}'
    , '0', '1800', '0', NULL, '0', '0', '0', 0, '1', '11', 'encounters|notes'
);
#EndIf

#IfNotRow openemr_postcalendar_categories pc_constant_id jitsi_telehealth_established_patient
INSERT INTO `openemr_postcalendar_categories` (
    `pc_constant_id`, `pc_catname`, `pc_catcolor`, `pc_catdesc`,
    `pc_recurrtype`, `pc_enddate`, `pc_recurrspec`, `pc_recurrfreq`, `pc_duration`,
    `pc_end_date_flag`, `pc_end_date_type`, `pc_end_date_freq`, `pc_end_all_day`,
    `pc_dailylimit`, `pc_cattype`, `pc_active`, `pc_seq`, `aco_spec`
)
VALUES (
    'jitsi_telehealth_established_patient', 'Jitsi TeleHealth - Paciente Establecido', '#7bc47f'
    , 'Teleconsulta Jitsi para pacientes establecidos', '0', NULL
    , 'a:5:{s:17:"event_repeat_freq";s:1:"0";s:22:"event_repeat_freq_type";s:1:"0";s:19:"event_repeat_on_num";s:1:"1";s:19:"event_repeat_on_day";s:1:"0";s:20:"event_repeat_on_freq";s:1:"0";}'
    , '0', '900', '0', NULL, '0', '0', '0', 0, '1', '12', 'encounters|notes'
);
#EndIf
