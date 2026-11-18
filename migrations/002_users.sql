-- Dashboard logins. Accounts are created with cli/create-user.php.
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL
) ENGINE=InnoDB;

-- Used to throttle password guessing (see includes/auth.php).
CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(60) NOT NULL,
  ts BIGINT UNSIGNED NOT NULL,
  success TINYINT(1) NOT NULL,
  INDEX idx_ip_ts (ip, ts),
  INDEX idx_user_ts (username, ts)
) ENGINE=InnoDB;
