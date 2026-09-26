CREATE TABLE IF NOT EXISTS waste_trip_registration_journal (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  waste_trip_id BIGINT UNSIGNED NOT NULL,
  waste_trip_request_id BIGINT UNSIGNED NOT NULL,
  document_id BIGINT UNSIGNED NULL,
  event_kind VARCHAR(32) NOT NULL,
  action_text VARCHAR(255) NOT NULL,
  actor_membership_id BIGINT UNSIGNED NULL,
  actor_login VARCHAR(100) NOT NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  initial_key VARCHAR(80) NULL,
  occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uk_registration_journal_initial (initial_key),
  KEY idx_registration_journal_time (occurred_at,id),
  KEY idx_registration_journal_trip (waste_trip_id,waste_trip_request_id,id),
  KEY idx_registration_journal_actor (actor_login,occurred_at),
  CONSTRAINT fk_registration_journal_trip FOREIGN KEY (waste_trip_id) REFERENCES waste_trips(id) ON DELETE RESTRICT,
  CONSTRAINT fk_registration_journal_request FOREIGN KEY (waste_trip_request_id) REFERENCES waste_trip_requests(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing values have no trustworthy historical entry time. The migration time
-- is the event time; the owner specified enshina as the initial-state author.
INSERT INTO waste_trip_registration_journal
  (waste_trip_id,waste_trip_request_id,event_kind,action_text,actor_login,after_json,initial_key)
SELECT wtr.waste_trip_id,i.waste_trip_request_id,'initial','Зафиксированы существующие значения','enshina',
  JSON_OBJECT('arrived_at',i.arrived_at,'departed_at',i.departed_at,
    'sender_signature_present',i.sender_signature_present,'sender_signature_status',i.sender_signature_status,
    'driver_signature_present',i.driver_signature_present,'driver_signature_status',i.driver_signature_status,
    'tn_datetime_missing',i.tn_datetime_missing,'tn_datetime_status',i.tn_datetime_status,
    'data_from_tracker',i.data_from_tracker,'data_from_driver',i.data_from_driver,
    'data_invented',i.data_invented,'recovery_source',i.recovery_source,
    'tn_absent',i.tn_absent,'comment',i.comment),
  CONCAT('item:',i.waste_trip_request_id)
FROM waste_trip_completion_items i JOIN waste_trip_requests wtr ON wtr.id=i.waste_trip_request_id
ON DUPLICATE KEY UPDATE initial_key=VALUES(initial_key);

INSERT INTO waste_trip_registration_journal
  (waste_trip_id,waste_trip_request_id,document_id,event_kind,action_text,actor_login,after_json,initial_key)
SELECT wtr.waste_trip_id,d.waste_trip_request_id,d.id,'initial','Зафиксирован существующий скан ТН','enshina',
  JSON_OBJECT('file',d.original_name,'download_name',d.download_name,'active',IF(d.deleted_at IS NULL,1,0)),
  CONCAT('document:',d.id)
FROM waste_trip_completion_documents d JOIN waste_trip_requests wtr ON wtr.id=d.waste_trip_request_id
ON DUPLICATE KEY UPDATE initial_key=VALUES(initial_key);
