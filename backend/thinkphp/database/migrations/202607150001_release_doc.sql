-- 需求级上线文档：按项目汇总分支、SQL、env、脚本
CREATE TABLE IF NOT EXISTS `ai_dev_release_docs` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `requirement_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `content` MEDIUMTEXT,
  `project_entries_json` MEDIUMTEXT,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL,
  KEY `idx_release_doc_requirement_id` (`requirement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
