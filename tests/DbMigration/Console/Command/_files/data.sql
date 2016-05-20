INSERT INTO migtable (id, code) VALUES (1, 1);
INSERT INTO migtable (id, code) VALUES (2, 2);
INSERT INTO migtable (id, code) VALUES (19, 19);
INSERT INTO migtable (id, code) VALUES (20, 20);

INSERT INTO migtable (id, code) VALUES (9, 9999);

INSERT INTO difftable (id) VALUES (1);

INSERT INTO nopkeytable (id) VALUES (1);

INSERT INTO longtable (id, text_data, blob_data) VALUES (2, (SELECT LPAD('', 512, 'X')), (SELECT RPAD(0x00010203040506070809, 512, 'Y')));

INSERT INTO sametable (id) VALUES (9);

INSERT INTO notexist (id) VALUES (1);
