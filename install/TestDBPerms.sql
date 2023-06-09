CREATE TABLE InstallTest (x INT);

ALTER TABLE InstallTest ADD COLUMN y INT;

INSERT INTO InstallTest (x,y) VALUES (0,1);

UPDATE InstallTest SET y=8 WHERE x=1;

CREATE INDEX test_idx ON InstallTest(x);

SELECT * FROM InstallTest;

DROP INDEX test_idx ON InstallTest;

ALTER TABLE InstallTest DROP COLUMN x;

LOCK TABLES InstallTest WRITE;

DROP TABLE InstallTest;

UNLOCK TABLES;