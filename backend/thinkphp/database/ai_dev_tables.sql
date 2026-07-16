-- AI 开发工单台 v2 schema
-- 实体关系:需求 1:N 工单 N:1 项目;需求下挂文档快照(版本化)与拆解(版本化)

CREATE TABLE ai_dev_requirements (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL DEFAULT '',
  doc_url VARCHAR(1000) NOT NULL DEFAULT '',
  branch_name VARCHAR(255) NOT NULL DEFAULT '',
  final_branch_name VARCHAR(255) NOT NULL DEFAULT '',
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_dev_requirement_docs (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  requirement_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  version INT NOT NULL DEFAULT 1,
  content MEDIUMTEXT,
  source VARCHAR(32) NOT NULL DEFAULT 'manual',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_requirement_id (requirement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_dev_breakdowns (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  requirement_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  version INT NOT NULL DEFAULT 1,
  content MEDIUMTEXT,
  projects_json TEXT,
  source VARCHAR(32) NOT NULL DEFAULT 'ai',
  model_name VARCHAR(255) NOT NULL DEFAULT '',
  confirmed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  confirmed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_requirement_id (requirement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_dev_settings (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `key` VARCHAR(64) NOT NULL DEFAULT '',
  `value` TEXT,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_dev_tasks (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  requirement_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  doc_version_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  scope_summary TEXT,
  spec_markdown MEDIUMTEXT,
  title VARCHAR(255) NOT NULL DEFAULT '',
  project_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  repo_name VARCHAR(255) NOT NULL DEFAULT '',
  base_branch VARCHAR(255) NOT NULL DEFAULT '',
  branch_prefix VARCHAR(255) NOT NULL DEFAULT '',
  branch_name VARCHAR(255) NOT NULL DEFAULT '',
  final_branch_name VARCHAR(255) NOT NULL DEFAULT '',
  status VARCHAR(64) NOT NULL DEFAULT 'created',
  commit_message TEXT,
  commit_hash VARCHAR(255) NOT NULL DEFAULT '',
  is_pushed TINYINT NOT NULL DEFAULT 0,
  pr_url VARCHAR(1000) NOT NULL DEFAULT '',
  created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_status (status),
  KEY idx_project_id (project_id),
  KEY idx_requirement_id (requirement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_dev_projects (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL DEFAULT '',
  description VARCHAR(500) NOT NULL DEFAULT '',
  repo_url VARCHAR(1000) NOT NULL DEFAULT '',
  local_path VARCHAR(1000) NOT NULL DEFAULT '',
  default_base_branch VARCHAR(255) NOT NULL DEFAULT '',
  default_branch_prefix VARCHAR(255) NOT NULL DEFAULT '',
  test_command TEXT,
  lint_command TEXT,
  build_command TEXT,
  allow_auto_commit TINYINT NOT NULL DEFAULT 1,
  allow_auto_push TINYINT NOT NULL DEFAULT 0,
  status TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_dev_plans (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  task_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  version INT NOT NULL DEFAULT 1,
  plan_content MEDIUMTEXT,
  source VARCHAR(32) NOT NULL DEFAULT 'ai',
  model_name VARCHAR(255) NOT NULL DEFAULT '',
  confirmed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  confirmed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_task_id (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_dev_runs (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  task_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  run_type VARCHAR(64) NOT NULL DEFAULT '',
  status VARCHAR(64) NOT NULL DEFAULT '',
  model_name VARCHAR(255) NOT NULL DEFAULT '',
  agent_session_id VARCHAR(255) NOT NULL DEFAULT '',
  pid INT NOT NULL DEFAULT 0,
  input MEDIUMTEXT,
  output MEDIUMTEXT,
  error MEDIUMTEXT,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_task_id (task_id),
  KEY idx_run_type (run_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_dev_run_logs (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  run_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  seq INT NOT NULL DEFAULT 0,
  event_type VARCHAR(64) NOT NULL DEFAULT '',
  content MEDIUMTEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_run_id_seq (run_id, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_dev_changes (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  task_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  run_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  diff_summary MEDIUMTEXT,
  changed_files MEDIUMTEXT,
  git_diff_snapshot LONGTEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_task_id (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_dev_reviews (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  task_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  run_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(64) NOT NULL DEFAULT '',
  risk_level VARCHAR(64) NOT NULL DEFAULT '',
  review_result MEDIUMTEXT,
  test_result MEDIUMTEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_task_id (task_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_dev_retrospectives (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  task_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  requirement_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  content MEDIUMTEXT,
  project_summaries_json MEDIUMTEXT,
  created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  KEY idx_task_id (task_id),
  KEY idx_requirement_id (requirement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_dev_release_docs (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  requirement_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  content MEDIUMTEXT,
  project_entries_json MEDIUMTEXT,
  created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  KEY idx_release_doc_requirement_id (requirement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_dev_model_configs (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  provider VARCHAR(255) NOT NULL DEFAULT '',
  model_name VARCHAR(255) NOT NULL DEFAULT '',
  api_base VARCHAR(1000) NOT NULL DEFAULT '',
  api_key_ref VARCHAR(255) NOT NULL DEFAULT '',
  context_length INT NOT NULL DEFAULT 0,
  timeout_seconds INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_dev_security_rules (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  pattern VARCHAR(1000) NOT NULL DEFAULT '',
  replacement VARCHAR(1000) NOT NULL DEFAULT '***',
  enabled TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO ai_dev_settings (`key`, `value`) VALUES ('workspace_roots', '[]');

INSERT INTO ai_dev_model_configs
(provider, model_name, api_base, api_key_ref, context_length, timeout_seconds)
VALUES
('Claude', 'claude-sonnet-5', 'https://api.anthropic.com', 'ANTHROPIC_API_KEY', 200000, 1800);

INSERT INTO ai_dev_security_rules (pattern, replacement, enabled) VALUES
('password\\s*=\\s*[^\\n]+', 'PASSWORD = "***"', 1),
('token\\s*=\\s*[^\\n]+', 'TOKEN = "***"', 1),
('secret\\s*=\\s*[^\\n]+', 'SECRET = "***"', 1),
('api_key\\s*=\\s*[^\\n]+', 'API_KEY = "***"', 1),
('Authorization:\\s*Bearer\\s+[^\\n]+', 'Authorization: Bearer ***', 1);
