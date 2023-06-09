ALTER TABLE MetadataFields CHANGE COLUMN UpdateMethod UpdateMethod ENUM("NoAutoUpdate", "OnRecordCreate", "Button", "OnRecordEdit", "OnRecordChange", "OnRecordRelease") DEFAULT "NoAutoUpdate";

UPDATE MetadataFields SET CopyOnResourceDuplication=0 WHERE SchemaId=0 AND FieldName IN ('Date Of Record Creation' , 'Date Of Record Release', 'Date Last Modified', 'Added By Id', 'Last Modified By Id');

UPDATE MetadataFields SET UpdateMethod='OnRecordRelease' WHERE SchemaId=0 AND FieldName='Date Of Record Release';

ALTER TABLE Qualifiers CHANGE COLUMN QualifierId QualifierId INT NOT NULL AUTO_INCREMENT;

DROP TABLE IF EXISTS EventLog;
ALTER TABLE SystemConfiguration DROP COLUMN ForumsEnabled;
ALTER TABLE SystemConfiguration DROP COLUMN ForumsUseWYSIWYG;
ALTER TABLE SystemConfiguration CHANGE COLUMN ForumsAllowHTML CommentsAllowHTML INT DEFAULT 1;

ALTER TABLE SystemConfiguration ADD COLUMN PasswordChangeTemplateId INT DEFAULT NULL;
ALTER TABLE SystemConfiguration ADD COLUMN EmailChangeTemplateId INT DEFAULT NULL;
ALTER TABLE SystemConfiguration ADD COLUMN ActivateAccountTemplateId INT DEFAULT NULL;

ALTER TABLE MetadataFields DROP COLUMN ViewingUserIsValue;
ALTER TABLE MetadataFields DROP COLUMN AuthoringUserIsValue;
ALTER TABLE MetadataFields DROP COLUMN EditingUserIsValue;
ALTER TABLE MetadataFields DROP COLUMN ViewingUserValue;
ALTER TABLE MetadataFields DROP COLUMN AuthoringUserValue;
ALTER TABLE MetadataFields DROP COLUMN EditingUserValue;
ALTER TABLE MetadataFields DROP COLUMN ImagePreviewPrivilege;
ALTER TABLE MetadataFields DROP COLUMN ViewingPrivilege;
ALTER TABLE MetadataFields DROP COLUMN AuthoringPrivilege;
ALTER TABLE MetadataFields DROP COLUMN EditingPrivilege;
ALTER TABLE MetadataFields DROP COLUMN TreeBrowsingPrivilege;
ALTER TABLE MetadataFields DROP COLUMN PreviewingPrivileges;

DROP TABLE IF EXISTS Forums;
DROP TABLE IF EXISTS Topics;
DROP TABLE IF EXISTS PopupLog;

ALTER TABLE Images DROP COLUMN LinkTarget;

ALTER TABLE ApplicationFrameworkSettings ADD COLUMN SessionLifetime INT DEFAULT 1800;
ALTER TABLE ApplicationFrameworkSettings ADD COLUMN LogPhpNotices INT DEFAULT 0;

CREATE TABLE IF NOT EXISTS AF_CachedPageCallbacks (
    CacheId     INT NOT NULL PRIMARY KEY,
    Callbacks   MEDIUMBLOB DEFAULT NULL,
    INDEX       Index_C (CacheId)
);

ALTER TABLE ResourceClassInts RENAME RecordClassInts;
ALTER TABLE ResourceFieldTimestamps RENAME RecordFieldTimestamps;
ALTER TABLE ResourceImageInts RENAME RecordImageInts;
ALTER TABLE ResourceNameInts RENAME RecordNameInts;
ALTER TABLE ResourceRatings RENAME RecordRatings;
ALTER TABLE Resources RENAME Records;
ALTER TABLE ResourceUserInts RENAME RecordUserInts;
ALTER TABLE VisibleResourceCounts RENAME VisibleRecordCounts;

CREATE TABLE IF NOT EXISTS TextValues (
    ResourceId              INT NOT NULL,
    FieldId                 INT NOT NULL,
    TextValue               TEXT DEFAULT NULL,
    INDEX                   Index_RF (ResourceId, FieldId)
);

CREATE TABLE IF NOT EXISTS NumberValues (
    ResourceId              INT NOT NULL,
    FieldId                 INT NOT NULL,
    NumberValue             INT DEFAULT NULL,
    INDEX                   Index_RF (ResourceId, FieldId)
);

CREATE TABLE IF NOT EXISTS DateValues (
    ResourceId              INT NOT NULL,
    FieldId                 INT NOT NULL,
    DateBegin               DATETIME DEFAULT NULL,
    DateEnd                 DATETIME DEFAULT NULL,
    DatePrecision           INT DEFAULT NULL,
    INDEX                   Index_RF (ResourceId, FieldId)
);

ALTER TABLE Files CHANGE COLUMN ResourceId RecordId INT NOT NULL;
ALTER TABLE RecordClassInts CHANGE COLUMN ResourceId RecordId INT NOT NULL;
ALTER TABLE RecordFieldTimestamps CHANGE COLUMN ResourceId RecordId INT NOT NULL;
ALTER TABLE RecordImageInts CHANGE COLUMN ResourceId RecordId INT NOT NULL;
ALTER TABLE RecordNameInts CHANGE COLUMN ResourceId RecordId INT NOT NULL;
ALTER TABLE RecordRatings CHANGE COLUMN ResourceId RecordId INT NOT NULL;
ALTER TABLE Records CHANGE COLUMN ResourceId RecordId INT NOT NULL;
ALTER TABLE RecordUserInts CHANGE COLUMN ResourceId RecordId INT NOT NULL;
ALTER TABLE ReferenceInts CHANGE COLUMN DstResourceId DstRecordId INT NOT NULL;
ALTER TABLE ReferenceInts CHANGE COLUMN SrcResourceId SrcRecordId INT NOT NULL;
ALTER TABLE UserPermsCache CHANGE COLUMN ResourceId RecordId INT NOT NULL;
ALTER TABLE TextValues CHANGE COLUMN ResourceId RecordId INT NOT NULL;
ALTER TABLE NumberValues CHANGE COLUMN ResourceId RecordId INT NOT NULL;
ALTER TABLE DateValues CHANGE COLUMN ResourceId RecordId INT NOT NULL;

UPDATE FolderContentTypes SET NormalizedTypeName = "METAVUSMETADATAFIELD", TypeName = "Metavus\\MetadataField" WHERE TypeName = "MetadataField";
UPDATE FolderContentTypes SET NormalizedTypeName = "METAVUSMETADATAFIELDGROUP", TypeName = "Metavus\\MetadataFieldGroup" WHERE TypeName = "MetadataFieldGroup";

DROP TABLE IF EXISTS ClassResourceCounts;
DROP TABLE IF EXISTS ClassResourceConditionals;

-- fix default on Files (RecordId), setting it to Item::NO_ITEM
ALTER TABLE Files CHANGE COLUMN RecordId RecordId INT NOT NULL DEFAULT -2123456789;
UPDATE Files SET RecordId = -2123456789 WHERE RecordId = 0 AND FieldId = -2123456789;

-- add CommentsEnabled column to MetadataSchema table and remove ResourceCommentsEnabled
ALTER TABLE MetadataSchemas ADD COLUMN CommentsEnabled INT DEFAULT 1;
ALTER TABLE SystemConfiguration DROP COLUMN ResourceCommentsEnabled;

-- add column to support explicit ApplicationFramework cached page expirations
ALTER TABLE AF_CachedPages ADD COLUMN ExpirationDate DATETIME DEFAULT NULL;
CREATE INDEX Index_E ON AF_CachedPages (ExpirationDate);

ALTER TABLE Files ADD COLUMN FileChecksum TEXT DEFAULT NULL;
ALTER TABLE Files ADD COLUMN ContentLastChecked DATETIME DEFAULT NULL;
ALTER TABLE Files ADD COLUMN ContentUnchanged INT DEFAULT 1;

ALTER TABLE Images ADD COLUMN FileLength INT DEFAULT 0;
ALTER TABLE Images ADD COLUMN FileChecksum TEXT DEFAULT NULL;
ALTER TABLE Images ADD COLUMN ContentLastChecked DATETIME DEFAULT NULL;
ALTER TABLE Images ADD COLUMN ContentUnchanged INT DEFAULT 1;


-- add columns to support expiration of UserPermsCache entries
ALTER TABLE UserPermsCache ADD COLUMN ExpirationDate DATETIME DEFAULT NULL;
CREATE INDEX Index_E ON UserPermsCache (ExpirationDate);

-- add column in SystemConfiguration for whether to display dialogs (popups) or tooltips
ALTER TABLE SystemConfiguration DROP COLUMN TooltipsUseDialogs;

-- add enum for Email MetadataFields, add option to obfuscate MetadataField values for anonymous users
ALTER TABLE MetadataFields MODIFY COLUMN FieldType ENUM("Text", "Number", "Date", "TimeStamp", "Paragraph", "Flag", "Tree", "ControlledName", "Option", "User", "Still Image", "File", "Url", "Point", "Reference", "Email", "SearchParameterSet") NOT NULL AFTER FieldName;
ALTER TABLE MetadataFields ADD COLUMN ObfuscateValueForAnonymousUsers INT DEFAULT 0;

UPDATE MetadataFields SET UpdateMethod='Button' WHERE SchemaId=0 AND FieldName='Date Record Checked';
UPDATE MetadataFields SET UpdateMethod='OnRecordChange' WHERE SchemaId=0 AND FieldName='Date Last Modified';
UPDATE MetadataFields SET UpdateMethod='OnRecordChange' WHERE SchemaId=0 AND FieldName='Last Modified By Id';
UPDATE MetadataFields SET UpdateMethod='OnRecordCreate' WHERE SchemaId=0 AND FieldName='Added By Id';
UPDATE MetadataFields SET UpdateMethod='OnRecordCreate' WHERE SchemaId=0 AND FieldName='Date Of Record Creation';
UPDATE MetadataFields SET UpdateMethod='OnRecordRelease' WHERE SchemaId=0 AND FieldName='Date Of Record Release';

-- add column for FacetsShowOnlyTermsUsedInResults MField setting
ALTER TABLE MetadataFields ADD COLUMN FacetsShowOnlyTermsUsedInResults INT DEFAULT 0;
DROP INDEX Index_SU ON VisibleRecordCounts;
CREATE INDEX Index_SU ON VisibleRecordCounts(SchemaId, UserClass(32));
CREATE INDEX Index_V ON VisibleRecordCounts(ValueId);

-- allow columns in APUsers to be null
-- to clear NO_ZERO_DATE for existing rows
SET sql_mode='';
ALTER TABLE APUsers CHANGE COLUMN UserPassword UserPassword TEXT DEFAULT NULL;
ALTER TABLE APUsers CHANGE COLUMN CreationDate CreationDate DATETIME DEFAULT NULL;
ALTER TABLE APUsers CHANGE COLUMN LastLoginDate LastLoginDate DATETIME DEFAULT NULL;
ALTER TABLE APUsers CHANGE COLUMN EMail EMail TEXT DEFAULT NULL;
ALTER TABLE APUsers CHANGE COLUMN EMailNew EMailNew TEXT DEFAULT NULL;
ALTER TABLE APUsers CHANGE COLUMN WebSite WebSite TEXT DEFAULT NULL;
ALTER TABLE APUsers CHANGE COLUMN RealName RealName TEXT DEFAULT NULL;
ALTER TABLE APUsers CHANGE COLUMN AddressLineOne AddressLineOne TEXT DEFAULT NULL;
ALTER TABLE APUsers CHANGE COLUMN AddressLineTwo AddressLineTwo TEXT DEFAULT NULL;
ALTER TABLE APUsers CHANGE COLUMN City City TEXT DEFAULT NULL;
ALTER TABLE APUsers CHANGE COLUMN State State TEXT DEFAULT NULL;
ALTER TABLE APUsers CHANGE COLUMN Country Country TEXT DEFAULT NULL;
ALTER TABLE APUsers CHANGE COLUMN ZipCode ZipCode TEXT DEFAULT NULL;
ALTER TABLE APUsers CHANGE COLUMN LastLocation LastLocation TEXT DEFAULT NULL;
ALTER TABLE APUsers CHANGE COLUMN LastActiveDate LastActiveDate DATETIME DEFAULT NULL;
ALTER TABLE APUsers CHANGE COLUMN LastIPAddress LastIPAddress TEXT DEFAULT NULL;

-- update various columns to give them defaults
-- (MySQL 8 requires defaults for columns used for indexes)
ALTER TABLE Messages CHANGE COLUMN ParentId ParentId INT DEFAULT NULL;
ALTER TABLE Messages CHANGE COLUMN ParentType ParentType INT DEFAULT NULL;
ALTER TABLE Folders CHANGE COLUMN OwnerId OwnerId INT DEFAULT NULL;

-- change columns to use BLOB for serialized data
ALTER TABLE SystemConfiguration CHANGE COLUMN DefaultUserPrivs DefaultUserPrivs BLOB DEFAULT NULL;
ALTER TABLE SavedSearches CHANGE COLUMN SearchData SearchData BLOB DEFAULT NULL;
ALTER TABLE CachedValues CHANGE COLUMN Value Value BLOB DEFAULT NULL;
ALTER TABLE APUsers CHANGE COLUMN SearchSelections SearchSelections BLOB DEFAULT NULL;

-- correct schema name for collections
UPDATE MetadataSchemas SET Name = 'Collections' WHERE Name = 'Collection';

-- remove columns for image properties from MetadataFields
ALTER TABLE MetadataFields DROP COLUMN MaxHeight;
ALTER TABLE MetadataFields DROP COLUMN MaxWidth;
ALTER TABLE MetadataFields DROP COLUMN MaxPreviewHeight;
ALTER TABLE MetadataFields DROP COLUMN MaxPreviewWidth;
ALTER TABLE MetadataFields DROP COLUMN MaxThumbnailHegiht;
ALTER TABLE MetadataFields DROP COLUMN MaxThumbnailWidth;
ALTER TABLE MetadataFields DROP COLUMN DefaultAltText;

-- remove image dimensions from Images table
ALTER TABLE Images DROP COLUMN Width;
ALTER TABLE Images DROP COLUMN Height;
ALTER TABLE Images DROP COLUMN PreviewWidth;
ALTER TABLE Images DROP COLUMN PreviewHeight;
ALTER TABLE Images DROP COLUMN ThumbnailWidth;
ALTER TABLE Images DROP COLUMN ThumbnailHeight;

-- add columns to Images, defaulting them to Item::NO_ITEM
ALTER TABLE Images ADD COLUMN ItemId INT DEFAULT -2123456789;
ALTER TABLE Images ADD COLUMN FieldId INT DEFAULT -2123456789;

-- remove the deprecated DefaultSortField from SystemConfiguration table
ALTER TABLE SystemConfiguration DROP COLUMN DefaultSortField;

-- add column DefaultSortField to the MetadataSchemas table
ALTER TABLE MetadataSchemas ADD COLUMN DefaultSortField INT DEFAULT NULL;

-- make sure view page is set for collections
UPDATE MetadataSchemas SET ViewPage='index.php?P=DisplayCollection&ID=$ID' WHERE Name = 'Collections';

-- add performance-enhancing indexes
CREATE INDEX Index_B ON PluginInfo(BaseName(12));
CREATE INDEX Index_CM ON TaskQueue(Callback(64), Parameters(256));
CREATE INDEX Index_NI ON Classifications(ClassificationName(16), FieldId);
CREATE INDEX Index_PI ON TaskQueue(Priority, TaskId);
CREATE INDEX Index_S ON PeriodicEvents(Signature(24));
CREATE INDEX Index_TF ON Images(ItemId, FieldId);
CREATE INDEX Index_Un ON APUsers (UserName(12));

-- set the user CreationDate metadata field to update on user creation
UPDATE MetadataFields SET UpdateMethod = 'OnRecordCreate' WHERE SchemaId = 1 AND FieldName = "CreationDate";

-- add class name column for schemas
ALTER TABLE MetadataSchemas ADD COLUMN ItemClassName TEXT;
UPDATE MetadataSchemas SET ItemClassName='Metavus\\Collection' WHERE Name = 'Collections';

-- set the collection schema Date Of Record Release metadata field to auto-update on record release
UPDATE MetadataFields SET UpdateMethod = 'OnRecordRelease' WHERE SchemaId = 2 AND FieldName = "Date Of Record Release";

-- add column EditPage to the MetadataSchemas table
ALTER TABLE MetadataSchemas ADD COLUMN EditPage TEXT DEFAULT NULL;
UPDATE MetadataSchemas SET EditPage = 'index.php?P=EditResource&ID=$ID' WHERE Name IN ('Resources', 'Collections');
UPDATE MetadataSchemas SET EditPage = 'index.php?P=EditUser&ID=$ID' WHERE Name = 'User';
