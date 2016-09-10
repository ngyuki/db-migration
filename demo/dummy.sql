CREATE TABLE AlteredTable (
  id INT UNSIGNED NOT NULL, 
  altered_column VARCHAR(50) NOT NULL COLLATE utf8_general_ci, 
  dropped_column INT NOT NULL, 
  INDEX dropped_index (dropped_column), 
  PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB;
CREATE TABLE CreatedTable (
  id INT UNSIGNED NOT NULL, 
  PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB;
CREATE TABLE DroppedTable (
  id INT UNSIGNED NOT NULL, 
  PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB;
CREATE TABLE RecordTable (
  id INT UNSIGNED NOT NULL, 
  name VARCHAR(50) NOT NULL COLLATE utf8_general_ci, 
  value INT NOT NULL, 
  PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB;
