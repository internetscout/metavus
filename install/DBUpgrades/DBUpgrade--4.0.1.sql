
ALTER TABLE TaskQueue MODIFY COLUMN Callback BLOB DEFAULT NULL;
ALTER TABLE TaskQueue MODIFY COLUMN Parameters MEDIUMBLOB DEFAULT NULL;
ALTER TABLE RunningTasks MODIFY COLUMN Callback BLOB DEFAULT NULL;
ALTER TABLE RunningTasks MODIFY COLUMN Parameters MEDIUMBLOB DEFAULT NULL;

ALTER TABLE Files MODIFY COLUMN ResourceId INT DEFAULT -2123456789;
ALTER TABLE Files MODIFY COLUMN FieldId INT DEFAULT -2123456789;

