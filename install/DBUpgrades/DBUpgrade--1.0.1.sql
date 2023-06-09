ALTER TABLE Files CHANGE COLUMN FileLength FileLength BIGINT UNSIGNED DEFAULT 0;

UPDATE MetadataFields SET Optional=0 WHERE FieldType='Flag';
