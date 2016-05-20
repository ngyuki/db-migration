CREATE TABLE migtable (
    id INT NOT NULL,
    code INT NOT NULL,
    UNIQUE INDEX `unq_index` (`code`),
    PRIMARY KEY (id)
);
CREATE TABLE longtable (
    id INT NOT NULL,
    text_data TEXT NOT NULL,
    blob_data BLOB NOT NULL,
    PRIMARY KEY (id)
);
CREATE TABLE difftable (
    id INT NOT NULL,
    PRIMARY KEY (id)
);
CREATE TABLE nopkeytable (
    id INT NOT NULL
);
CREATE TABLE igntable (
    id INT NOT NULL,
    code INT NOT NULL,
    name CHAR(16),
    PRIMARY KEY (id)
);
CREATE TABLE unqtable (
    id INT NOT NULL,
    code INT NOT NULL,
    UNIQUE INDEX `unq_index` (`code`),
    PRIMARY KEY (id)
);
CREATE TABLE sametable (
    id INT NOT NULL,
    PRIMARY KEY (id)
);
CREATE TABLE notexist (
    id INT NOT NULL,
    PRIMARY KEY (id)
);
