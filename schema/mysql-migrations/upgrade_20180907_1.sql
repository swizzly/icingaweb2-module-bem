ALTER TABLE bem_cell_stats
  ADD COLUMN fqdn VARCHAR(255) DEFAULT NULL AFTER queue_size,
  ADD COLUMN username VARCHAR(64) DEFAULT NULL AFTER fqdn,
  ADD COLUMN pid INT UNSIGNED DEFAULT NULL AFTER username,
  ADD COLUMN php_version VARCHAR(64) DEFAULT NULL AFTER pid;
