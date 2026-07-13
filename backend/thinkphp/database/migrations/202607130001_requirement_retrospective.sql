-- 在保留旧工单复盘的同时，支持一份需求复盘按项目记录问题和优化项
ALTER TABLE `ai_dev_retrospectives` ADD COLUMN `requirement_id` BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE `ai_dev_retrospectives` ADD COLUMN `project_summaries_json` MEDIUMTEXT NULL;
ALTER TABLE `ai_dev_retrospectives` ADD COLUMN `updated_at` DATETIME NULL;
