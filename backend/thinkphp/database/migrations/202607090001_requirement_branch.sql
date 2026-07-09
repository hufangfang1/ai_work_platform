-- 需求级分支名:同一需求下的多项目工单复用同一个分支
ALTER TABLE `ai_dev_requirements` ADD COLUMN `branch_name` VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE `ai_dev_requirements` ADD COLUMN `final_branch_name` VARCHAR(255) NOT NULL DEFAULT '';
