CREATE TABLE IF NOT EXISTS resource_sharing_contacts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  university VARCHAR(120) NOT NULL,
  partner_library VARCHAR(200) NOT NULL,
  cls_contact VARCHAR(160) NULL,
  cls_email VARCHAR(254) NULL,
  cls_phone VARCHAR(80) NULL,
  p2p_email VARCHAR(254) NULL,
  p2p_phone VARCHAR(80) NULL,
  ill_email VARCHAR(254) NULL,
  ill_phone VARCHAR(80) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX ix_resource_sharing_partner (partner_library),
  INDEX ix_resource_sharing_university (university)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

