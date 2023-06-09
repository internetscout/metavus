-- delete and re-create intersection tables with named indexes
CREATE TABLE ResourceNameInts_old AS SELECT DISTINCT * FROM ResourceNameInts;
DROP TABLE ResourceNameInts;
CREATE TABLE ResourceNameInts (
    ResourceId              INT NOT NULL,
    ControlledNameId        INT NOT NULL,
    INDEX                   Index_C (ControlledNameId),
    UNIQUE                  UIndex_RC (ResourceId, ControlledNameId)
);
INSERT INTO ResourceNameInts SELECT * FROM ResourceNameInts_old;
DROP TABLE ResourceNameInts_old;


CREATE TABLE ResourceClassInts_old AS SELECT DISTINCT * FROM ResourceClassInts;
DROP TABLE ResourceClassInts;
CREATE TABLE ResourceClassInts (
    ResourceId              INT NOT NULL,
    ClassificationId        INT NOT NULL,
    INDEX                   Index_C (ClassificationId),
    UNIQUE                  UIndex_RC (ResourceId, ClassificationId)
);
INSERT INTO ResourceClassInts SELECT * FROM ResourceClassInts_old;
DROP TABLE ResourceClassInts_old;

CREATE TABLE ResourceImageInts_old AS SELECT DISTINCT * FROM ResourceImageInts;
DROP TABLE ResourceImageInts;
CREATE TABLE ResourceImageInts (
    ResourceId              INT NOT NULL,
    FieldId                 INT NOT NULL,
    ImageId                 INT NOT NULL,
    INDEX                   Index_I (ImageId),
    UNIQUE                  UIndex_RFI (ResourceId, FieldId, ImageId)
);
INSERT INTO ResourceImageInts SELECT * FROM ResourceImageInts_old;
DROP TABLE ResourceImageInts_old;

CREATE TABLE ReferenceInts_old AS SELECT DISTINCT * FROM ReferenceInts;
DROP TABLE ReferenceInts;
CREATE TABLE ReferenceInts (
    FieldId       INT DEFAULT NULL,
    SrcResourceId INT DEFAULT NULL,
    DstResourceId INT DEFAULT NULL,
    UNIQUE        UIndex_FSD (FieldId, SrcResourceId, DstResourceId)
);
INSERT INTO ReferenceInts SELECT * FROM ReferenceInts_old;
DROP TABLE ReferenceInts_old;

CREATE TABLE FieldQualifierInts_old AS SELECT DISTINCT * FROM FieldQualifierInts;
DROP TABLE FieldQualifierInts;
CREATE TABLE FieldQualifierInts (
    MetadataFieldId        INT NOT NULL,
    QualifierId            INT NOT NULL,
    INDEX                  Index_Q (QualifierId),
    UNIQUE                 UIndex_FQ (MetadataFieldId, QualifierId)
);
INSERT INTO FieldQualifierInts SELECT * FROM FieldQualifierInts_old;
DROP TABLE FieldQualifierInts_old;

-- MIME types for Office XML files
--  (see https://technet.microsoft.com/en-us/library/ee309278(office.12).aspx )
UPDATE Files SET FileType = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    WHERE LOWER(FileName) LIKE '%.docx' AND FileType = 'application/zip; charset=binary';

UPDATE Files SET FileType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    WHERE LOWER(FileName) LIKE '%.xlsx' AND FileType = 'application/zip; charset=binary';

UPDATE Files SET FileType = 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
    WHERE LOWER(FileName) LIKE '%.pptx' AND FileType = 'application/zip; charset=binary';

-- add show group name field to system configuration
ALTER TABLE SystemConfiguration ADD COLUMN ShowGroupNamesEnabled INT DEFAULT 0;

-- SearchGroupLogic metadata field setting
ALTER TABLE MetadataFields
    ADD COLUMN SearchGroupLogic INT DEFAULT 2;

-- LinkTarget image setting
ALTER TABLE Images ADD COLUMN LinkTarget TEXT DEFAULT NULL;

-- no longer need search field mapping in SearchEngine
DROP TABLE SearchFields;

-- add ReferenceableSchemaIds column
ALTER TABLE MetadataFields
    ADD COLUMN ReferenceableSchemaIds TEXT DEFAULT NULL;

-- clean up schema names and item names
UPDATE MetadataSchemas SET Name = 'Resources' WHERE Name = 'Default';
UPDATE MetadataSchemas SET ResourceName = 'Resource' WHERE Name = 'Resources';
UPDATE MetadataSchemas SET Name = 'Events' WHERE Name = 'Calendar Events';
UPDATE MetadataSchemas SET ResourceName = 'Event' WHERE Name = 'Events';
UPDATE MetadataSchemas SET ResourceName = 'User' WHERE Name = 'Users';
UPDATE MetadataSchemas SET ResourceName = 'Page' WHERE Name = 'Pages';
UPDATE MetadataSchemas SET ResourceName = 'Blog' WHERE Name = 'Blog';

-- clean up schema view pages
UPDATE MetadataSchemas SET ViewPage = 'index.php?P=FullRecord&ID=$ID'
    WHERE ViewPage = '?P=FullRecord&ID=$ID';
UPDATE MetadataSchemas SET ViewPage = 'index.php?P=UserList&ID=$ID'
    WHERE ViewPage = '?P=UserList&ID=$ID';

-- Add VisibleResourceCounts
CREATE TABLE VisibleResourceCounts (
    SchemaId INT NOT NULL,
    UserClass TEXT NOT NULL,
    ValueId INT NOT NULL,
    ResourceCount INT NOT NULL,
    INDEX Index_SU(SchemaId,UserClass(16)) );

-- Clean up ResourceCounts(Old) tables
DROP TABLE ResourceCounts;
DROP TABLE ResourceCountsOld;

-- add MaxDepthForAdvancedSearch metadata field setting
ALTER TABLE MetadataFields
    ADD COLUMN MaxDepthForAdvancedSearch INT DEFAULT 0;

-- add AbbreviatedName column to MetadataSchemas
ALTER TABLE MetadataSchemas
    ADD COLUMN AbbreviatedName TEXT DEFAULT NULL;

-- toggle tree, file, and controlled name fields to AllowMultiple
-- uses a doubly nested subquery to sidestep mysql's annoyng error 1093
-- which prevents updating a table referenced in a subquery
UPDATE MetadataFields SET AllowMultiple = 1 WHERE FieldId IN
       (SELECT FieldId FROM (SELECT FieldId FROM MetadataFields
            WHERE FieldType IN ('Tree','File','ControlledName')) AS X);
