SET @@session.SQL_NOTES = 0;
SET @@session.FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS CreatedTable;
DROP TABLE IF EXISTS AlteredTable;
DROP TABLE IF EXISTS RecordTable;
SET @@session.SQL_NOTES = DEFAULT;
SET @@session.FOREIGN_KEY_CHECKS = DEFAULT;

CREATE TABLE CreatedTable (
  id INT(10) UNSIGNED NOT NULL,
  PRIMARY KEY (id)
);

CREATE TABLE AlteredTable (
  id INT(10) UNSIGNED NOT NULL,
  created_column VARCHAR(32) NOT NULL,
  altered_column VARCHAR(64) NOT NULL,
  PRIMARY KEY (id),
  INDEX created_index (created_column)
);

CREATE TABLE RecordTable (
  id INT(10) UNSIGNED NOT NULL,
  name VARCHAR(50) NOT NULL,
  value INT(11) NOT NULL,
  PRIMARY KEY (id)
);
