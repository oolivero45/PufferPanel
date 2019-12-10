USE pufferpanel;

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `_meta` (
  `metaId` INT(11) NOT NULL AUTO_INCREMENT,
  `metaKey` VARCHAR(20) NOT NULL,
  `metaValue` VARCHAR(200) NOT NULL,
  PRIMARY KEY (`metaId`),
  UNIQUE INDEX `UK_metaKey` (`metaKey`)
);

INSERT INTO _meta (metaKey, metaValue) VALUES
  ('version', 'v1.2.1'),
  ('updateDate', CURRENT_TIMESTAMP)
  ON DUPLICATE KEY UPDATE
  metaKey=VALUES(metaKey),
  metaValue=VALUES(metaValue);

UPDATE IGNORE acp_settings
SET setting_val='en_US'
WHERE setting_ref='default_language';

UPDATE oauth_clients
  JOIN users ON users.id = oauth_clients.user_id
SET scopes = CONCAT(scopes , ' server.edit')
WHERE scopes NOT LIKE '%server.edit%' AND users.root_admin = 1;

UPDATE oauth_clients
  JOIN users ON users.id = oauth_clients.user_id
SET scopes = CONCAT(scopes , ' node.templates')
WHERE scopes NOT LIKE '%node.templates%' AND users.root_admin = 1;

ALTER TABLE users MODIFY `session_id` char(40);

SET FOREIGN_KEY_CHECKS = 1;
