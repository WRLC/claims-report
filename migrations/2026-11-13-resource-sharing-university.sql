-- Convert the first version of the contact table to the controlled-vocabulary University field.
SET @has_old_partner_library_column = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'resource_sharing_contacts'
    AND COLUMN_NAME = 'partner_library'
);
SET @contact_table_upgrade = IF(
  @has_old_partner_library_column > 0,
  'ALTER TABLE resource_sharing_contacts DROP INDEX ix_resource_sharing_partner, DROP INDEX ix_resource_sharing_university, DROP COLUMN university, CHANGE COLUMN partner_library university VARCHAR(200) NOT NULL, ADD INDEX ix_resource_sharing_university (university)',
  'SELECT 1'
);
PREPARE contact_table_upgrade_stmt FROM @contact_table_upgrade;
EXECUTE contact_table_upgrade_stmt;
DEALLOCATE PREPARE contact_table_upgrade_stmt;

UPDATE resource_sharing_contacts SET university = CASE LOWER(TRIM(university))
  WHEN 'american university — washington college of law' THEN 'American University'
  WHEN 'catholic university of america — columbus school of law' THEN 'Catholic University of America'
  WHEN 'georgetown university law center' THEN 'Georgetown University'
  WHEN 'george washington university — health sciences library' THEN 'George Washington University'
  WHEN 'george washington university — law library' THEN 'George Washington University'
  WHEN 'howard university — louis stokes health sciences library' THEN 'Howard University'
  WHEN 'howard university school of law' THEN 'Howard University'
  WHEN 'university of the district of columbia — david a. clarke school of law' THEN 'University of the District of Columbia'
  ELSE university
END;
