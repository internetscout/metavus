
-- standard metadata field mappings
CREATE TABLE IF NOT EXISTS StandardMetadataFieldMappings (
    SchemaId                INT NOT NULL,
    Name                    TEXT DEFAULT NULL,
    FieldId                 INT NOT NULL,
    INDEX                   Index_S (SchemaId)
);

ALTER TABLE SystemConfiguration
   ADD COLUMN PasswordMinLength INT DEFAULT 6;

ALTER TABLE SystemConfiguration
    ADD COLUMN PasswordUniqueChars INT DEFAULT 4;

ALTER TABLE SystemConfiguration
    ADD COLUMN PasswordRequiresPunctuation INT DEFAULT 0;

ALTER TABLE SystemConfiguration
    ADD COLUMN PasswordRequiresMixedCase INT DEFAULT 0;

ALTER TABLE SystemConfiguration
    ADD COLUMN PasswordRequiresDigits INT DEFAULT 0;

-- remove owner from Release Flag
UPDATE MetadataFields SET Owner=NULL WHERE FieldName="Release Flag" AND SchemaId=0;
