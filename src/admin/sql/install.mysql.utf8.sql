CREATE TABLE IF NOT EXISTS `#__footprint_scans` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created` DATETIME NOT NULL,
    `updated` DATETIME NULL,
    `state` VARCHAR(20) NOT NULL DEFAULT 'done',
    `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
    `total_files` INT UNSIGNED NOT NULL DEFAULT 0,
    `total_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `db_tables` INT UNSIGNED NOT NULL DEFAULT 0,
    `db_rows` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `db_data` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `db_index` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `db_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `has_index_sizes` TINYINT(1) NOT NULL DEFAULT 1,
    `working` LONGTEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_state_created` (`state`, `created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__footprint_groups` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `scan_id` INT UNSIGNED NOT NULL,
    `group_key` VARCHAR(190) NOT NULL,
    `type` VARCHAR(20) NOT NULL DEFAULT '',
    `element` VARCHAR(190) NULL,
    `origin` VARCHAR(190) NULL,
    `folder` VARCHAR(100) NULL,
    `client_id` TINYINT NOT NULL DEFAULT 1,
    `enabled` TINYINT NULL,
    `name` VARCHAR(255) NOT NULL DEFAULT '',
    `files` INT UNSIGNED NOT NULL DEFAULT 0,
    `bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `db_tables` INT UNSIGNED NOT NULL DEFAULT 0,
    `db_rows` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `db_data` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `db_index` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `db_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_scan_group` (`scan_id`, `group_key`),
    KEY `idx_group_key` (`group_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__footprint_tree` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `scan_id` INT UNSIGNED NOT NULL,
    `path` VARCHAR(512) NOT NULL,
    `depth` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `files` INT UNSIGNED NOT NULL DEFAULT 0,
    `bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `direct_files` INT UNSIGNED NOT NULL DEFAULT 0,
    `direct_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_scan_depth` (`scan_id`, `depth`),
    KEY `idx_scan_path` (`scan_id`, `path`(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__footprint_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `scan_id` INT UNSIGNED NOT NULL,
    `group_key` VARCHAR(190) NOT NULL,
    `kind` VARCHAR(10) NOT NULL DEFAULT 'file',
    `path` VARCHAR(512) NOT NULL,
    `files` INT UNSIGNED NOT NULL DEFAULT 0,
    `bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_scan_group_kind` (`scan_id`, `group_key`, `kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__footprint_tables` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `scan_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(190) NOT NULL,
    `engine` VARCHAR(50) NOT NULL DEFAULT '',
    `collation` VARCHAR(100) NOT NULL DEFAULT '',
    `row_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `data_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `index_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `total_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `free_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `group_key` VARCHAR(190) NOT NULL DEFAULT '__other',
    PRIMARY KEY (`id`),
    KEY `idx_scan_group` (`scan_id`, `group_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__footprint_resolver` (
    `group_key` VARCHAR(190) NOT NULL,
    `sql_hash` CHAR(32) NOT NULL,
    `tables_json` TEXT NOT NULL,
    `updated` DATETIME NOT NULL,
    PRIMARY KEY (`group_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
