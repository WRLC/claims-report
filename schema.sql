CREATE TABLE institutions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  alma_code VARCHAR(80) NOT NULL,
  partner_email VARCHAR(254) NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/New_York',
  search_interval_days TINYINT UNSIGNED NOT NULL DEFAULT 7,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(254) NOT NULL,
  display_name VARCHAR(160) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('user','super_user','admin') NOT NULL DEFAULT 'user',
  active TINYINT(1) NOT NULL DEFAULT 1,
  failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_email (email),
  CONSTRAINT fk_user_institution FOREIGN KEY (institution_id) REFERENCES institutions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE claims_cases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(12) NOT NULL UNIQUE,
  institution_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  assigned_to BIGINT UNSIGNED NULL,
  patron_primary_id VARCHAR(255) NOT NULL,
  patron_name VARCHAR(255) NOT NULL,
  patron_email VARCHAR(254) NULL,
  item_barcode VARCHAR(100) NULL,
  item_mms_id VARCHAR(100) NULL,
  item_title VARCHAR(500) NOT NULL,
  item_author VARCHAR(255) NULL,
  loan_type ENUM('local','CLS','P2P','ILL') NOT NULL DEFAULT 'local',
  partner_name VARCHAR(200) NULL,
  partner_email VARCHAR(254) NULL,
  partner_notify_email TINYINT(1) NOT NULL DEFAULT 1,
  partner_status ENUM('not_required','pending','requested','in_progress','complete','not_found') NOT NULL DEFAULT 'not_required',
  partner_requested_at DATETIME NULL,
  partner_completed_at DATETIME NULL,
  partner_token_hash CHAR(64) NULL,
  partner_token_expires_at DATETIME NULL,
  stage ENUM('search_1','search_2','search_3','search_4','resolution','closed') NOT NULL DEFAULT 'search_1',
  search_1_at DATETIME NULL, search_1_due_at DATE NULL, search_1_by BIGINT UNSIGNED NULL, search_1_status ENUM('','not_found','complete') NOT NULL DEFAULT '', search_1_result TEXT NULL,
  search_2_at DATETIME NULL, search_2_due_at DATE NULL, search_2_by BIGINT UNSIGNED NULL, search_2_status ENUM('','not_found','complete') NOT NULL DEFAULT '', search_2_result TEXT NULL,
  search_3_at DATETIME NULL, search_3_due_at DATE NULL, search_3_by BIGINT UNSIGNED NULL, search_3_status ENUM('','not_found','complete') NOT NULL DEFAULT '', search_3_result TEXT NULL,
  search_4_at DATETIME NULL, search_4_due_at DATE NULL, search_4_by BIGINT UNSIGNED NULL, search_4_status ENUM('','not_found','complete') NOT NULL DEFAULT '', search_4_result TEXT NULL,
  next_action_at DATETIME NULL,
  resolution ENUM('found','not_found','complete','') NOT NULL DEFAULT '',
  resolution_note TEXT NULL,
  opened_at DATETIME NOT NULL,
  closed_at DATETIME NULL,
  patron_notice_channel ENUM('email','none') NOT NULL DEFAULT 'email',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX ix_queue (institution_id, stage, next_action_at),
  INDEX ix_patron (institution_id, patron_primary_id),
  CONSTRAINT fk_case_institution FOREIGN KEY (institution_id) REFERENCES institutions(id),
  CONSTRAINT fk_case_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_case_assignee FOREIGN KEY (assigned_to) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE case_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(60) NOT NULL,
  summary VARCHAR(500) NOT NULL,
  detail_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_case_events (case_id, created_at),
  CONSTRAINT fk_event_case FOREIGN KEY (case_id) REFERENCES claims_cases(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id BIGINT UNSIGNED NOT NULL,
  channel ENUM('email') NOT NULL,
  recipient VARCHAR(254) NOT NULL,
  template_key VARCHAR(60) NOT NULL,
  status ENUM('sent','failed') NOT NULL,
  provider_id VARCHAR(255) NULL,
  error_text VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_notifications_case (case_id, created_at),
  CONSTRAINT fk_notification_case FOREIGN KEY (case_id) REFERENCES claims_cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
