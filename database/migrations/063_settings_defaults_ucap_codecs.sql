-- New-install / stock-seed defaults: failover TCAP, unlimited caps as -1, G* codecs + DTMF, 7-day recordings.
UPDATE system_settings
SET setting_value = '408,480,486,503,TCAP'
WHERE setting_key = 'failover_sip_codes'
  AND setting_value IN ('408,480,486,503', '408,480,486,487,500,503');

UPDATE system_settings
SET setting_value = '-1'
WHERE setting_key = 'system_max_concurrent'
  AND setting_value IN ('0', '');

UPDATE system_settings
SET setting_value = '-1'
WHERE setting_key = 'system_max_cps'
  AND setting_value IN ('0', '');

UPDATE system_settings
SET setting_value = '7'
WHERE setting_key = 'recording_retention_days'
  AND setting_value IN ('90', '30', '');

INSERT INTO system_settings (setting_key, setting_value, updated_by)
SELECT 'recording_retention_days', '7', NULL
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM system_settings WHERE setting_key = 'recording_retention_days'
);

UPDATE system_settings
SET setting_value = 'G729,G729A,GSM,G722,G723,G726,telephone-event'
WHERE setting_key = 'default_codecs'
  AND (
    setting_value IS NULL
    OR setting_value = ''
    OR setting_value = 'PCMU,PCMA,G729,GSM,telephone-event'
  );

INSERT INTO system_settings (setting_key, setting_value, updated_by)
SELECT 'default_codecs', 'G729,G729A,GSM,G722,G723,G726,telephone-event', NULL
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM system_settings WHERE setting_key = 'default_codecs'
);

INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
 ('failover_sip_codes', '408,480,486,503,TCAP'),
 ('system_max_concurrent', '-1'),
 ('system_max_cps', '-1');
