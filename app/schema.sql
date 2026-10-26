-- Runnex Email Marketing schema (MySQL / MariaDB). Also converted automatically for SQLite in dev.

CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(191) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'agent',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE UNIQUE INDEX ux_users_email ON users(email);

CREATE TABLE companies (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  website VARCHAR(191) NULL,
  phone VARCHAR(50) NULL,
  address VARCHAR(255) NULL,
  signature_html TEXT NULL,
  footer_text TEXT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE company_users (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  company_id INT NOT NULL,
  user_id INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE UNIQUE INDEX ux_company_users ON company_users(company_id, user_id);

CREATE TABLE email_accounts (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  company_id INT NOT NULL,
  user_id INT NOT NULL,
  email VARCHAR(191) NOT NULL,
  from_name VARCHAR(150) NULL,
  provider VARCHAR(20) NOT NULL DEFAULT 'gmail',
  smtp_host VARCHAR(191) NOT NULL,
  smtp_port INT NOT NULL DEFAULT 587,
  smtp_secure VARCHAR(10) NOT NULL DEFAULT 'tls',
  imap_host VARCHAR(191) NOT NULL,
  imap_port INT NOT NULL DEFAULT 993,
  username VARCHAR(191) NOT NULL,
  password_enc TEXT NOT NULL,
  signature_html TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  last_error TEXT NULL,
  daily_limit INT NOT NULL DEFAULT 30,
  auto_limit TINYINT NOT NULL DEFAULT 1,
  sent_today INT NOT NULL DEFAULT 0,
  warmup_sent_today INT NOT NULL DEFAULT 0,
  counters_day VARCHAR(10) NULL,
  next_send_at DATETIME NULL,
  last_sent_at DATETIME NULL,
  warmup_enabled TINYINT NOT NULL DEFAULT 1,
  warmup_start INT NOT NULL DEFAULT 3,
  warmup_increase INT NOT NULL DEFAULT 2,
  warmup_max INT NOT NULL DEFAULT 30,
  warmup_reply_rate INT NOT NULL DEFAULT 35,
  warmup_started_at DATETIME NULL,
  warmup_next_at DATETIME NULL,
  health_score INT NOT NULL DEFAULT 0,
  health_status VARCHAR(20) NOT NULL DEFAULT 'new',
  health_data TEXT NULL,
  send_paused_reason VARCHAR(255) NULL,
  imap_checked_at DATETIME NULL,
  imap_next_at DATETIME NULL,
  imap_state TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE UNIQUE INDEX ux_accounts_email ON email_accounts(email);
CREATE INDEX ix_accounts_company ON email_accounts(company_id);
CREATE INDEX ix_accounts_user ON email_accounts(user_id);

CREATE TABLE contacts (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  company_id INT NOT NULL,
  email VARCHAR(191) NOT NULL,
  first_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NULL,
  company_name VARCHAR(191) NULL,
  mc_number VARCHAR(30) NULL,
  dot_number VARCHAR(30) NULL,
  phone VARCHAR(50) NULL,
  city VARCHAR(100) NULL,
  state VARCHAR(50) NULL,
  equipment_type VARCHAR(100) NULL,
  truck_count VARCHAR(20) NULL,
  notes TEXT NULL,
  custom TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  label VARCHAR(30) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  last_contacted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE UNIQUE INDEX ux_contacts_email ON contacts(company_id, email);
CREATE INDEX ix_contacts_status ON contacts(company_id, status);

CREATE TABLE lists (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  company_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  description VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX ix_lists_company ON lists(company_id);

CREATE TABLE list_contacts (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  list_id INT NOT NULL,
  contact_id INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE UNIQUE INDEX ux_list_contacts ON list_contacts(list_id, contact_id);
CREATE INDEX ix_list_contacts_contact ON list_contacts(contact_id);

CREATE TABLE templates (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  company_id INT NOT NULL,
  user_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE campaigns (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  company_id INT NOT NULL,
  user_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  list_id INT NULL,
  track_opens TINYINT NOT NULL DEFAULT 1,
  track_clicks TINYINT NOT NULL DEFAULT 0,
  plain_text TINYINT NOT NULL DEFAULT 0,
  include_unsub TINYINT NOT NULL DEFAULT 1,
  stop_on_reply TINYINT NOT NULL DEFAULT 1,
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Chicago',
  window_start VARCHAR(5) NOT NULL DEFAULT '08:00',
  window_end VARCHAR(5) NOT NULL DEFAULT '17:00',
  send_days VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5',
  min_delay INT NOT NULL DEFAULT 90,
  max_delay INT NOT NULL DEFAULT 240,
  daily_limit INT NOT NULL DEFAULT 0,
  skip_recent_days INT NOT NULL DEFAULT 7,
  scheduled_at DATETIME NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX ix_campaigns_company ON campaigns(company_id, status);

CREATE TABLE campaign_steps (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  campaign_id INT NOT NULL,
  step_no INT NOT NULL,
  delay_days INT NOT NULL DEFAULT 0,
  subject VARCHAR(255) NULL,
  body_html MEDIUMTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX ix_steps_campaign ON campaign_steps(campaign_id, step_no);

CREATE TABLE campaign_accounts (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  campaign_id INT NOT NULL,
  account_id INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE UNIQUE INDEX ux_campaign_accounts ON campaign_accounts(campaign_id, account_id);
CREATE INDEX ix_campaign_accounts_acc ON campaign_accounts(account_id);

CREATE TABLE campaign_leads (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  campaign_id INT NOT NULL,
  contact_id INT NOT NULL,
  account_id INT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  current_step INT NOT NULL DEFAULT 0,
  next_send_at DATETIME NULL,
  last_sent_at DATETIME NULL,
  attempts INT NOT NULL DEFAULT 0,
  thread_subject VARCHAR(255) NULL,
  last_message_id VARCHAR(191) NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE UNIQUE INDEX ux_campaign_leads ON campaign_leads(campaign_id, contact_id);
CREATE INDEX ix_leads_queue ON campaign_leads(campaign_id, status, next_send_at);
CREATE INDEX ix_leads_contact ON campaign_leads(contact_id);

CREATE TABLE messages (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  company_id INT NOT NULL,
  campaign_id INT NULL,
  step_no INT NULL,
  lead_id INT NULL,
  contact_id INT NULL,
  account_id INT NOT NULL,
  user_id INT NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'campaign',
  to_email VARCHAR(191) NOT NULL,
  subject VARCHAR(255) NULL,
  message_id VARCHAR(191) NULL,
  token VARCHAR(40) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'sent',
  error TEXT NULL,
  sent_at DATETIME NOT NULL,
  sent_day VARCHAR(10) NOT NULL,
  opened_at DATETIME NULL,
  open_count INT NOT NULL DEFAULT 0,
  clicked_at DATETIME NULL,
  click_count INT NOT NULL DEFAULT 0,
  replied_at DATETIME NULL,
  bounced_at DATETIME NULL,
  unsubscribed_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE UNIQUE INDEX ux_messages_token ON messages(token);
CREATE INDEX ix_messages_msgid ON messages(message_id);
CREATE INDEX ix_messages_campaign ON messages(campaign_id);
CREATE INDEX ix_messages_account ON messages(account_id, sent_day);
CREATE INDEX ix_messages_contact ON messages(contact_id);
CREATE INDEX ix_messages_company_day ON messages(company_id, sent_day);
CREATE INDEX ix_messages_to ON messages(to_email);

CREATE TABLE events (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  message_id INT NOT NULL,
  campaign_id INT NULL,
  account_id INT NULL,
  company_id INT NULL,
  user_id INT NULL,
  type VARCHAR(20) NOT NULL,
  url TEXT NULL,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  day VARCHAR(10) NOT NULL,
  hour INT NOT NULL DEFAULT 0,
  dow INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX ix_events_message ON events(message_id);
CREATE INDEX ix_events_campaign ON events(campaign_id, type);
CREATE INDEX ix_events_company_day ON events(company_id, day);

CREATE TABLE replies (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  company_id INT NOT NULL,
  account_id INT NOT NULL,
  user_id INT NULL,
  contact_id INT NULL,
  campaign_id INT NULL,
  message_ref_id INT NULL,
  direction VARCHAR(5) NOT NULL DEFAULT 'in',
  is_auto TINYINT NOT NULL DEFAULT 0,
  from_email VARCHAR(191) NULL,
  from_name VARCHAR(191) NULL,
  to_email VARCHAR(191) NULL,
  subject VARCHAR(255) NULL,
  body_text MEDIUMTEXT NULL,
  message_id VARCHAR(191) NULL,
  in_reply_to VARCHAR(191) NULL,
  received_at DATETIME NOT NULL,
  is_read TINYINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX ix_replies_account ON replies(account_id, received_at);
CREATE INDEX ix_replies_company ON replies(company_id, received_at);
CREATE INDEX ix_replies_msgid ON replies(message_id);

CREATE TABLE warmup_messages (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  from_account_id INT NOT NULL,
  to_account_id INT NOT NULL,
  token VARCHAR(40) NOT NULL,
  message_id VARCHAR(191) NULL,
  subject VARCHAR(255) NULL,
  is_reply TINYINT NOT NULL DEFAULT 0,
  sent_at DATETIME NOT NULL,
  landed VARCHAR(10) NULL,
  checked_at DATETIME NULL,
  replied TINYINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE UNIQUE INDEX ux_warmup_token ON warmup_messages(token);
CREATE INDEX ix_warmup_from ON warmup_messages(from_account_id, sent_at);
CREATE INDEX ix_warmup_to ON warmup_messages(to_account_id);

CREATE TABLE placement_tests (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  company_id INT NOT NULL,
  user_id INT NOT NULL,
  account_id INT NOT NULL,
  campaign_id INT NULL,
  token VARCHAR(40) NOT NULL,
  subject VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'running',
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE UNIQUE INDEX ux_placement_token ON placement_tests(token);

CREATE TABLE placement_results (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  test_id INT NOT NULL,
  seed_account_id INT NOT NULL,
  landed VARCHAR(10) NOT NULL DEFAULT 'pending',
  checked_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX ix_placement_results ON placement_results(test_id);

CREATE TABLE account_daily (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  account_id INT NOT NULL,
  day VARCHAR(10) NOT NULL,
  sent INT NOT NULL DEFAULT 0,
  warmup_sent INT NOT NULL DEFAULT 0,
  warmup_inbox INT NOT NULL DEFAULT 0,
  warmup_spam INT NOT NULL DEFAULT 0,
  bounces INT NOT NULL DEFAULT 0,
  replies INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE UNIQUE INDEX ux_account_daily ON account_daily(account_id, day);

CREATE TABLE suppression (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  company_id INT NOT NULL DEFAULT 0,
  email VARCHAR(191) NOT NULL,
  reason VARCHAR(30) NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE UNIQUE INDEX ux_suppression ON suppression(company_id, email);
CREATE INDEX ix_suppression_email ON suppression(email);

CREATE TABLE settings (
  skey VARCHAR(100) NOT NULL PRIMARY KEY,
  svalue TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE activity_log (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  user_id INT NULL,
  action VARCHAR(100) NOT NULL,
  details TEXT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX ix_activity_created ON activity_log(created_at);

CREATE TABLE login_attempts (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  ip VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX ix_login_attempts ON login_attempts(ip, created_at);
