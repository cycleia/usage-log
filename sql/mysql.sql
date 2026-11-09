-- Reference table for cycleia/usage-log (MySQL 8 / MariaDB 10.6+).
-- Rename the table freely; pass the name to Config.

CREATE TABLE IF NOT EXISTS `usage_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL COMMENT 'UTC',
  `source` varchar(10) NOT NULL COMMENT 'server or beacon',
  `app` varchar(20) NOT NULL,
  `user_id` int DEFAULT NULL,
  `user_role` varchar(20) DEFAULT NULL COMMENT 'role at the time of the event',
  `org_id` int DEFAULT NULL COMMENT 'organisation the event concerns',
  `event_type` varchar(20) NOT NULL,
  `surface` varchar(60) DEFAULT NULL COMMENT 'where a search was typed; unknown if not on the list',
  `search_text` varchar(500) DEFAULT NULL,
  `params` json DEFAULT NULL COMMENT 'allow-listed query parameters only',
  `method` varchar(8) DEFAULT NULL,
  `path` varchar(255) DEFAULT NULL COMMENT 'no query string; token-like segments redacted',
  `route` varchar(120) DEFAULT NULL,
  `entity_type` varchar(40) DEFAULT NULL,
  `entity_id` int DEFAULT NULL,
  `status` smallint DEFAULT NULL,
  `duration_ms` int DEFAULT NULL,
  `session_key` char(16) DEFAULT NULL COMMENT 'hash; never the raw token or session id',
  `device` varchar(10) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_usage_created` (`created_at`),
  KEY `idx_usage_user_created` (`user_id`, `created_at`),
  KEY `idx_usage_org_created` (`org_id`, `created_at`),
  KEY `idx_usage_type_created` (`event_type`, `created_at`),
  KEY `idx_usage_entity` (`entity_type`, `entity_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
