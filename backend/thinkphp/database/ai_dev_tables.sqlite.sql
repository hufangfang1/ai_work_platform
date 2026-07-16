-- AI 开发工单台 v2 schema(SQLite 版,本机无 MySQL 时使用)
-- 与 ai_dev_tables.sql 保持字段一致

CREATE TABLE ai_dev_requirements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL DEFAULT '',
  doc_url TEXT NOT NULL DEFAULT '',
  branch_name TEXT NOT NULL DEFAULT '',
  final_branch_name TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'draft',
  created_by INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX idx_req_status ON ai_dev_requirements (status);

CREATE TABLE ai_dev_requirement_docs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  requirement_id INTEGER NOT NULL DEFAULT 0,
  version INTEGER NOT NULL DEFAULT 1,
  content TEXT,
  source TEXT NOT NULL DEFAULT 'manual',
  created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX idx_reqdoc_requirement_id ON ai_dev_requirement_docs (requirement_id);

CREATE TABLE ai_dev_breakdowns (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  requirement_id INTEGER NOT NULL DEFAULT 0,
  version INTEGER NOT NULL DEFAULT 1,
  content TEXT,
  projects_json TEXT,
  source TEXT NOT NULL DEFAULT 'ai',
  model_name TEXT NOT NULL DEFAULT '',
  confirmed_by INTEGER NOT NULL DEFAULT 0,
  confirmed_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX idx_breakdown_requirement_id ON ai_dev_breakdowns (requirement_id);

CREATE TABLE ai_dev_settings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  key TEXT NOT NULL DEFAULT '' UNIQUE,
  value TEXT,
  updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE ai_dev_tasks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  requirement_id INTEGER NOT NULL DEFAULT 0,
  doc_version_id INTEGER NOT NULL DEFAULT 0,
  scope_summary TEXT,
  spec_markdown TEXT,
  title TEXT NOT NULL DEFAULT '',
  project_id INTEGER NOT NULL DEFAULT 0,
  repo_name TEXT NOT NULL DEFAULT '',
  base_branch TEXT NOT NULL DEFAULT '',
  branch_prefix TEXT NOT NULL DEFAULT '',
  branch_name TEXT NOT NULL DEFAULT '',
  final_branch_name TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'created',
  commit_message TEXT,
  commit_hash TEXT NOT NULL DEFAULT '',
  is_pushed INTEGER NOT NULL DEFAULT 0,
  pr_url TEXT NOT NULL DEFAULT '',
  created_by INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX idx_task_status ON ai_dev_tasks (status);
CREATE INDEX idx_task_project_id ON ai_dev_tasks (project_id);
CREATE INDEX idx_task_requirement_id ON ai_dev_tasks (requirement_id);

CREATE TABLE ai_dev_projects (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL DEFAULT '',
  description TEXT NOT NULL DEFAULT '',
  repo_url TEXT NOT NULL DEFAULT '',
  local_path TEXT NOT NULL DEFAULT '',
  default_base_branch TEXT NOT NULL DEFAULT '',
  default_branch_prefix TEXT NOT NULL DEFAULT '',
  test_command TEXT,
  lint_command TEXT,
  build_command TEXT,
  allow_auto_commit INTEGER NOT NULL DEFAULT 1,
  allow_auto_push INTEGER NOT NULL DEFAULT 0,
  status INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX idx_project_name ON ai_dev_projects (name);

CREATE TABLE ai_dev_plans (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  task_id INTEGER NOT NULL DEFAULT 0,
  version INTEGER NOT NULL DEFAULT 1,
  plan_content TEXT,
  source TEXT NOT NULL DEFAULT 'ai',
  model_name TEXT NOT NULL DEFAULT '',
  confirmed_by INTEGER NOT NULL DEFAULT 0,
  confirmed_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX idx_plan_task_id ON ai_dev_plans (task_id);

CREATE TABLE ai_dev_runs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  task_id INTEGER NOT NULL DEFAULT 0,
  run_type TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT '',
  model_name TEXT NOT NULL DEFAULT '',
  agent_session_id TEXT NOT NULL DEFAULT '',
  pid INTEGER NOT NULL DEFAULT 0,
  input TEXT,
  output TEXT,
  error TEXT,
  started_at TEXT NULL,
  finished_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX idx_run_task_id ON ai_dev_runs (task_id);
CREATE INDEX idx_run_type ON ai_dev_runs (run_type);

CREATE TABLE ai_dev_run_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  run_id INTEGER NOT NULL DEFAULT 0,
  seq INTEGER NOT NULL DEFAULT 0,
  event_type TEXT NOT NULL DEFAULT '',
  content TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX idx_runlog_run_id_seq ON ai_dev_run_logs (run_id, seq);

CREATE TABLE ai_dev_changes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  task_id INTEGER NOT NULL DEFAULT 0,
  run_id INTEGER NOT NULL DEFAULT 0,
  diff_summary TEXT,
  changed_files TEXT,
  git_diff_snapshot TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX idx_change_task_id ON ai_dev_changes (task_id);

CREATE TABLE ai_dev_reviews (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  task_id INTEGER NOT NULL DEFAULT 0,
  run_id INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT '',
  risk_level TEXT NOT NULL DEFAULT '',
  review_result TEXT,
  test_result TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX idx_review_task_id ON ai_dev_reviews (task_id);
CREATE INDEX idx_review_status ON ai_dev_reviews (status);

CREATE TABLE ai_dev_retrospectives (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  task_id INTEGER NOT NULL DEFAULT 0,
  requirement_id INTEGER NOT NULL DEFAULT 0,
  content TEXT,
  project_summaries_json TEXT,
  created_by INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  updated_at TEXT
);
CREATE INDEX idx_retro_task_id ON ai_dev_retrospectives (task_id);
CREATE INDEX idx_retro_requirement_id ON ai_dev_retrospectives (requirement_id);

CREATE TABLE ai_dev_release_docs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  requirement_id INTEGER NOT NULL DEFAULT 0,
  content TEXT,
  project_entries_json TEXT,
  created_by INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  updated_at TEXT
);
CREATE INDEX idx_release_doc_requirement_id ON ai_dev_release_docs (requirement_id);

CREATE TABLE ai_dev_model_configs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  provider TEXT NOT NULL DEFAULT '',
  model_name TEXT NOT NULL DEFAULT '',
  api_base TEXT NOT NULL DEFAULT '',
  api_key_ref TEXT NOT NULL DEFAULT '',
  context_length INTEGER NOT NULL DEFAULT 0,
  timeout_seconds INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE ai_dev_security_rules (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  pattern TEXT NOT NULL DEFAULT '',
  replacement TEXT NOT NULL DEFAULT '***',
  enabled INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

INSERT INTO ai_dev_settings (key, value) VALUES ('workspace_roots', '[]');

INSERT INTO ai_dev_model_configs
(provider, model_name, api_base, api_key_ref, context_length, timeout_seconds)
VALUES
('Claude', 'claude-sonnet-5', 'https://api.anthropic.com', 'ANTHROPIC_API_KEY', 200000, 1800);

INSERT INTO ai_dev_security_rules (pattern, replacement, enabled) VALUES
('password\s*=\s*[^\n]+', 'PASSWORD = "***"', 1),
('token\s*=\s*[^\n]+', 'TOKEN = "***"', 1),
('secret\s*=\s*[^\n]+', 'SECRET = "***"', 1),
('api_key\s*=\s*[^\n]+', 'API_KEY = "***"', 1),
('Authorization:\s*Bearer\s+[^\n]+', 'Authorization: Bearer ***', 1);
