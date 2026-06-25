CREATE TABLE user_group_map (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  group_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_user_group (user_id, group_id),
  KEY idx_ugm_group_id (group_id),
  KEY idx_ugm_user_id (user_id)
);
