-- Flow Builder graph storage. Existing chatbot_nodes data remains untouched.
ALTER TABLE chatbot_flows ADD COLUMN IF NOT EXISTS status ENUM('draft','published') NOT NULL DEFAULT 'draft';
ALTER TABLE chatbot_flows ADD COLUMN IF NOT EXISTS priority INT NOT NULL DEFAULT 100;
ALTER TABLE chatbot_flows ADD COLUMN IF NOT EXISTS idle_minutes INT NOT NULL DEFAULT 120;
ALTER TABLE chatbot_flows ADD COLUMN IF NOT EXISTS max_steps INT NOT NULL DEFAULT 25;
ALTER TABLE chatbot_flows ADD COLUMN IF NOT EXISTS graph_version INT NOT NULL DEFAULT 1;
ALTER TABLE chatbot_flows ADD COLUMN IF NOT EXISTS published_at DATETIME NULL;
ALTER TABLE chatbot_flows ADD COLUMN IF NOT EXISTS canvas_view VARCHAR(80) NULL;

CREATE TABLE IF NOT EXISTS flow_nodes (
 flow_id INT NOT NULL, node_id VARCHAR(40) NOT NULL, company_id INT NOT NULL, type VARCHAR(30) NOT NULL,
 pos_x INT NOT NULL DEFAULT 0, pos_y INT NOT NULL DEFAULT 0, config LONGTEXT NULL,
 PRIMARY KEY (flow_id,node_id), KEY idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS flow_edges (
 flow_id INT NOT NULL, edge_id VARCHAR(40) NOT NULL, company_id INT NOT NULL, source_node VARCHAR(40) NOT NULL,
 source_port VARCHAR(60) NOT NULL, target_node VARCHAR(40) NOT NULL,
 PRIMARY KEY (flow_id,edge_id), KEY idx_source (flow_id,source_node,source_port), KEY idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS flow_sessions (
 id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, contact_id INT NOT NULL, flow_id INT NOT NULL,
 current_node_id VARCHAR(40) NULL, waiting_for VARCHAR(20) NOT NULL DEFAULT '', status ENUM('active','waiting','completed','expired','error') NOT NULL DEFAULT 'active',
 active_key TINYINT NULL DEFAULT 1, variables LONGTEXT NULL, retries INT NOT NULL DEFAULT 0, end_reason VARCHAR(40) NULL,
 started_at DATETIME NOT NULL, last_activity_at DATETIME NOT NULL, ended_at DATETIME NULL,
 UNIQUE KEY uq_open_session (company_id,contact_id,active_key), KEY idx_flow (flow_id), KEY idx_activity (status,last_activity_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS flow_jobs (
 id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, session_id INT NOT NULL, flow_id INT NOT NULL, contact_id INT NOT NULL,
 node_id VARCHAR(40) NOT NULL, run_at DATETIME NOT NULL, status ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
 attempts INT NOT NULL DEFAULT 0, error VARCHAR(255) NULL, created_at DATETIME NOT NULL, KEY idx_due (status,run_at), KEY idx_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS flow_logs (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, flow_id INT NOT NULL, session_id INT NOT NULL DEFAULT 0, contact_id INT NOT NULL DEFAULT 0,
 node_id VARCHAR(40) NULL, node_type VARCHAR(30) NULL, event VARCHAR(30) NOT NULL, result VARCHAR(10) NOT NULL DEFAULT 'ok', detail TEXT NULL, created_at DATETIME NOT NULL,
 KEY idx_company_time (company_id,created_at), KEY idx_flow (flow_id,created_at), KEY idx_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
