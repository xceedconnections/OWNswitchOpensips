-- Seed Settings → Default codecs (every G* + telephone-event) when missing.
INSERT INTO system_settings (setting_key, setting_value, updated_by)
SELECT 'default_codecs', 'G729,G729A,GSM,G722,G723,G726,telephone-event', NULL
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM system_settings WHERE setting_key = 'default_codecs'
);
