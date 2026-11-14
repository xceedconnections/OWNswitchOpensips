-- Dummy / lab concurrent calls (no trunk). Used by Dial Lab + Calls Online merge.
CREATE TABLE IF NOT EXISTS lab_dialogs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    callid VARCHAR(128) NOT NULL,
    sip_user VARCHAR(64) NOT NULL,
    from_user VARCHAR(64) NOT NULL DEFAULT 'lab',
    to_user VARCHAR(64) NOT NULL DEFAULT 'lab-sink',
    caller_id VARCHAR(64) NULL,
    state TINYINT UNSIGNED NOT NULL DEFAULT 4 COMMENT '4=connected (OpenSIPS-style)',
    started_at INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NULL,
    UNIQUE KEY uq_lab_callid (callid),
    KEY idx_lab_sip (sip_user),
    KEY idx_lab_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
