-- PrestaBridge module SQL install
-- Prefix PREFIX_ jest zamieniany dynamicznie przez moduł na wartość _DB_PREFIX_

CREATE TABLE IF NOT EXISTS `PREFIX_prestabridge_image_queue` (
  `id_image_queue` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_product` INT(11) UNSIGNED NOT NULL,
  `sku` VARCHAR(64) NOT NULL,
  `image_url` VARCHAR(2048) NOT NULL,
  `position` INT(3) UNSIGNED NOT NULL DEFAULT 0,
  `is_cover` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
  `error_message` VARCHAR(512) DEFAULT NULL,
  `attempts` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` TINYINT(3) UNSIGNED NOT NULL DEFAULT 3,
  `lock_token` VARCHAR(36) DEFAULT NULL,
  `locked_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_image_queue`),
  INDEX `idx_status_attempts` (`status`, `attempts`),
  INDEX `idx_product` (`id_product`),
  INDEX `idx_sku` (`sku`),
  INDEX `idx_lock` (`lock_token`, `locked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_prestabridge_log` (
  `id_log` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `level` ENUM('debug', 'info', 'warning', 'error', 'critical') NOT NULL DEFAULT 'info',
  `source` ENUM('import', 'image', 'cron', 'api', 'config', 'system') NOT NULL,
  `message` TEXT NOT NULL,
  `context` TEXT DEFAULT NULL COMMENT 'JSON z dodatkowymi danymi kontekstowymi',
  `sku` VARCHAR(64) DEFAULT NULL,
  `id_product` INT(11) UNSIGNED DEFAULT NULL,
  `request_id` VARCHAR(36) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_log`),
  INDEX `idx_level` (`level`),
  INDEX `idx_source` (`source`),
  INDEX `idx_sku` (`sku`),
  INDEX `idx_request_id` (`request_id`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_prestabridge_import_tracking` (
  `id_tracking` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` VARCHAR(36) NOT NULL,
  `id_product` INT(11) UNSIGNED NOT NULL,
  `sku` VARCHAR(64) NOT NULL,
  `status` ENUM('imported', 'images_pending', 'images_partial', 'completed', 'error') NOT NULL DEFAULT 'imported',
  `images_total` INT(3) UNSIGNED NOT NULL DEFAULT 0,
  `images_completed` INT(3) UNSIGNED NOT NULL DEFAULT 0,
  `images_failed` INT(3) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_tracking`),
  UNIQUE KEY `uniq_sku` (`sku`),
  INDEX `idx_request` (`request_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_product` (`id_product`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
