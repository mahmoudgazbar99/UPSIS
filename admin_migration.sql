USE ecole_sante_gabes;

CREATE TABLE IF NOT EXISTS admin_user (
  id_admin INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

INSERT INTO admin_user (username, password_hash)
VALUES ('admin', '$2y$10$9K2qVnYWB/dfdoKTGMn1FO4Qb0bh07OMLHi3l6XJM8VESe28whYNW')
ON DUPLICATE KEY UPDATE username = VALUES(username);
