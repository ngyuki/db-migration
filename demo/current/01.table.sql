SET @@session.SQL_NOTES = 0;
SET @@session.FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS DroppedTable;
DROP TABLE IF EXISTS AlteredTable;
DROP TABLE IF EXISTS RecordTable;
SET @@session.SQL_NOTES = DEFAULT;
SET @@session.FOREIGN_KEY_CHECKS = DEFAULT;

CREATE TABLE DroppedTable (
  id INT(10) UNSIGNED NOT NULL,
  PRIMARY KEY (id)
);

CREATE TABLE AlteredTable (
  id INT(10) UNSIGNED NOT NULL,
  altered_column VARCHAR(50) NOT NULL,
  dropped_column INT(11) NOT NULL,
  PRIMARY KEY (id),
  INDEX dropped_index (dropped_column)
);

CREATE TABLE RecordTable (
  id INT(10) UNSIGNED NOT NULL,
  name VARCHAR(50) NOT NULL,
  value INT(11) NOT NULL,
  PRIMARY KEY (id)
);
