
-- ----- SYSTEM --------------------------------------------------------------

-- saved searches
CREATE TABLE IF NOT EXISTS SavedSearches (
    SearchId            INT NOT NULL AUTO_INCREMENT,
    SearchName          TEXT DEFAULT NULL,
    SearchData          BLOB DEFAULT NULL,
    UserId              INT NOT NULL,
    DateLastRun         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    Frequency           INT DEFAULT 0,
    LastMatchingIds     MEDIUMTEXT DEFAULT NULL,
    INDEX               Index_U (UserId),
    INDEX               Index_S (SearchId)
);

-- add additional fields to user records
ALTER TABLE APUsers ADD COLUMN ActiveUI TEXT DEFAULT NULL;
ALTER TABLE APUsers ADD COLUMN BrowsingFieldId INT DEFAULT NULL;
ALTER TABLE APUsers ADD COLUMN RecordsPerPage INT DEFAULT 20;
ALTER TABLE APUsers ADD COLUMN SearchSelections BLOB DEFAULT NULL;

-- OAI-SQ search sites
CREATE TABLE IF NOT EXISTS GlobalSearchSites (
    SiteId                      INT NOT NULL AUTO_INCREMENT,
    SiteName                    TEXT DEFAULT NULL,
    OaiUrl                      TEXT DEFAULT NULL,
    SiteUrl                     TEXT DEFAULT NULL,
    LastSearchDate              DATETIME DEFAULT NULL,
    ConsecutiveFailures         INT DEFAULT 0,
    SearchAttempts              INT DEFAULT 0,
    SuccessfulSearches          INT DEFAULT 0,
    INDEX                       Index_S (SiteId)
);

-- user-defined privileges
CREATE TABLE IF NOT EXISTS CustomPrivileges (
    Id          INT NOT NULL,
    Name        TEXT DEFAULT NULL,
    INDEX       Index_I (Id)
);

-- secure login data
CREATE TABLE LoginKeys (
  KeyPair TEXT DEFAULT NULL,
  CreationTime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE UsedLoginTokens (
  Token TEXT DEFAULT NULL,
  KeyCTime TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UserName TEXT
);

-- ----- RESOURCES AND RELATED DATA ------------------------------------------

-- metadata schemas
CREATE TABLE IF NOT EXISTS MetadataSchemas (
    SchemaId                INT NOT NULL,
    Name                    TEXT DEFAULT NULL,
    AbbreviatedName         TEXT DEFAULT NULL,
    ResourceName            TEXT DEFAULT NULL,
    ItemClassName           TEXT DEFAULT NULL,
    DefaultSortField        INT DEFAULT NULL,
    AuthoringPrivileges     BLOB DEFAULT NULL,
    EditingPrivileges       BLOB DEFAULT NULL,
    ViewingPrivileges       BLOB DEFAULT NULL,
    ViewPage                TEXT DEFAULT NULL,
    EditPage                TEXT DEFAULT NULL,
    CommentsEnabled         INT DEFAULT 1,
    INDEX                   Index_S (SchemaId)
);

-- standard metadata field mappings
CREATE TABLE IF NOT EXISTS StandardMetadataFieldMappings (
    SchemaId                INT NOT NULL,
    Name                    TEXT DEFAULT NULL,
    FieldId                 INT NOT NULL,
    INDEX                   Index_S (SchemaId)
);

-- metadata fields
CREATE TABLE IF NOT EXISTS MetadataFields (
    FieldId                         INT NOT NULL,
    FieldName                       TEXT NOT NULL,
    FieldType                       ENUM("Text", "Number", "Date", "TimeStamp",
                                            "Paragraph", "Flag", "Tree",
                                            "ControlledName", "Option", "User",
                                            "Still Image", "File", "Url",
                                            "Point", "Reference", "Email", "SearchParameterSet"),
    SchemaId                        INT NOT NULL DEFAULT 0,
    Label                           TEXT DEFAULT NULL,
    Description                     TEXT DEFAULT NULL,
    Instructions                    TEXT DEFAULT NULL,
    Owner                           TEXT DEFAULT NULL,
    EnableOnOwnerReturn             INT DEFAULT 0,
    Enabled                         INT DEFAULT 1,
    Optional                        INT DEFAULT 1,
    CopyOnResourceDuplication       INT DEFAULT 1,
    Editable                        INT DEFAULT 1,
    TriggersAutoUpdates             INT DEFAULT 1,
    AllowMultiple                   INT DEFAULT 0,
    IncludeInKeywordSearch          INT DEFAULT 0,
    IncludeInAdvancedSearch         INT DEFAULT 0,
    IncludeInFacetedSearch          INT DEFAULT 0,
    SearchGroupLogic                INT DEFAULT 2,
    FacetsShowOnlyTermsUsedInResults INT DEFAULT 0,
    IncludeInSortOptions            INT DEFAULT 1,
    IncludeInRecommender            INT DEFAULT 0,
    DisplayAsListForAdvancedSearch  INT DEFAULT 0,
    MaxDepthForAdvancedSearch       INT DEFAULT 0,
    TextFieldSize                   INT DEFAULT NULL,
    MaxLength                       INT DEFAULT NULL,
    ParagraphRows                   INT DEFAULT NULL,
    ParagraphCols                   INT DEFAULT NULL,
    DefaultValue                    TEXT DEFAULT NULL,
    MinValue                        INT DEFAULT NULL,
    `MaxValue`                      INT DEFAULT NULL,
    FlagOnLabel                     TEXT DEFAULT NULL,
    FlagOffLabel                    TEXT DEFAULT NULL,
    DateFormat                      TEXT DEFAULT NULL,
    DateFormatIsPerRecord           INT DEFAULT 0,
    SearchWeight                    INT DEFAULT 1,
    RecommenderWeight               INT DEFAULT 1,
    UsesQualifiers                  INT DEFAULT 0,
    HasItemLevelQualifiers          INT DEFAULT 0,
    DefaultQualifier                INT DEFAULT NULL,
    DateLastModified                TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    LastModifiedById                INT DEFAULT NULL,
    UseForOaiSets                   INT DEFAULT 0,
    NumAjaxResults                  INT DEFAULT 50,
    OptionListThreshold             INT DEFAULT 25,
    AjaxThreshold                   INT DEFAULT 50,
    PointPrecision                  INT DEFAULT 8,
    PointDecimalDigits              INT DEFAULT 5,
    UpdateMethod                    ENUM("NoAutoUpdate", "OnRecordCreate",
                                         "Button", "OnRecordEdit",
                                         "OnRecordChange", "OnRecordRelease")
                                       DEFAULT "NoAutoUpdate",
    AllowHTML                       INT DEFAULT 0,
    UseWysiwygEditor                INT DEFAULT 0,
    ShowQualifiers                  INT DEFAULT 0,
    ReferenceableSchemaIds          TEXT DEFAULT NULL,
    UserPrivilegeRestrictions       TEXT DEFAULT NULL,
    ObfuscateValueForAnonymousUsers INT DEFAULT 0,
    AuthoringPrivileges             BLOB DEFAULT NULL,
    EditingPrivileges               BLOB DEFAULT NULL,
    ViewingPrivileges               BLOB DEFAULT NULL,
    INDEX                           Index_I (FieldId),
    INDEX                           Index_T (FieldType)
);

-- resource metadata field orders
CREATE TABLE IF NOT EXISTS MetadataFieldOrders (
    SchemaId                  INT NOT NULL,
    OrderId                   INT NOT NULL,
    OrderName                 TEXT
);

-- field qualifiers
CREATE TABLE IF NOT EXISTS Qualifiers (
    QualifierId            INT NOT NULL AUTO_INCREMENT,
    QualifierName          TEXT DEFAULT NULL,
    QualifierNamespace     TEXT DEFAULT NULL,
    QualifierUrl           TEXT DEFAULT NULL,
    INDEX                  Index_Q (QualifierId)
);

-- intersection between MetadataFields and Qualifiers
CREATE TABLE IF NOT EXISTS FieldQualifierInts (
    MetadataFieldId        INT NOT NULL,
    QualifierId            INT NOT NULL,
    INDEX                  Index_Q (QualifierId),
    UNIQUE                 UIndex_FQ (MetadataFieldId, QualifierId)
);

-- resource records
CREATE TABLE IF NOT EXISTS Records (
    RecordId                INT NOT NULL,
    SchemaId                INT NOT NULL DEFAULT 0,
    Title                   TEXT DEFAULT NULL,
    AlternateTitle          TEXT DEFAULT NULL,
    Description             MEDIUMTEXT DEFAULT NULL,
    Url                     TEXT DEFAULT NULL,
    ReleaseFlag             INT DEFAULT NULL,
    Source                  TEXT DEFAULT NULL,
    Relation                TEXT DEFAULT NULL,
    Coverage                TEXT DEFAULT NULL,
    Rights                  TEXT DEFAULT NULL,
    EmailAddress            TEXT DEFAULT NULL,
    DateIssuedBegin         DATE DEFAULT NULL,
    DateIssuedEnd           DATE DEFAULT NULL,
    DateIssuedPrecision     INT DEFAULT 0,
    DateOfRecordCreation    DATETIME DEFAULT NULL,
    DateOfRecordRelease     DATETIME DEFAULT NULL,
    DateRecordChecked       DATETIME DEFAULT NULL,
    DateLastModified        DATETIME DEFAULT NULL,
    VerificationAttempts    INT DEFAULT 0,
    CumulativeRating        INT DEFAULT 0,
    INDEX                   Index_R (RecordId),
    INDEX                   Index_S (SchemaId)
);

-- references/links between source resources to destination resources
CREATE TABLE IF NOT EXISTS ReferenceInts (
    FieldId       INT DEFAULT NULL,
    SrcRecordId INT DEFAULT NULL,
    DstRecordId INT DEFAULT NULL,
    INDEX         Index_FD (FieldId, DstRecordId),
    UNIQUE        UIndex_FSD (FieldId, SrcRecordId, DstRecordId)
);

-- intersection table between resources and users
CREATE TABLE IF NOT EXISTS RecordUserInts (
   RecordId INT NOT NULL,
   FieldId INT NOT NULL,
   UserId INT NOT NULL,
   INDEX Index_U (UserId),
   UNIQUE UIndex_RU (RecordId, FieldId, UserId)
);

-- text values associated with resources
CREATE TABLE IF NOT EXISTS TextValues (
    RecordId                INT NOT NULL,
    FieldId                 INT NOT NULL,
    TextValue               TEXT DEFAULT NULL,
    INDEX                   Index_RF (RecordId, FieldId)
);

-- numeric values associated with resources
CREATE TABLE IF NOT EXISTS NumberValues (
    RecordId                INT NOT NULL,
    FieldId                 INT NOT NULL,
    NumberValue             INT DEFAULT NULL,
    INDEX                   Index_RF (RecordId, FieldId)
);

-- date/time values associated with resources
CREATE TABLE IF NOT EXISTS DateValues (
    RecordId                INT NOT NULL,
    FieldId                 INT NOT NULL,
    DateBegin               DATETIME DEFAULT NULL,
    DateEnd                 DATETIME DEFAULT NULL,
    DatePrecision           INT DEFAULT NULL,
    INDEX                   Index_RF (RecordId, FieldId)
);

-- user ratings of resources
CREATE TABLE IF NOT EXISTS RecordRatings (
    RecordId                INT NOT NULL,
    UserId                  INT NOT NULL,
    DateRated               DATETIME DEFAULT NULL,
    Rating                  INT DEFAULT NULL,
    Comments                TEXT DEFAULT NULL,
    CommentApproved         INT DEFAULT 0,
    INDEX                   Index_R (RecordId),
    INDEX                   Index_U (UserId)
);

-- controlled names (publishers, authors, etc)
CREATE TABLE IF NOT EXISTS ControlledNames (
    ControlledNameId        INT NOT NULL AUTO_INCREMENT,
    ControlledName          TEXT DEFAULT NULL,
    FieldId                 INT NOT NULL,
    QualifierId             INT DEFAULT NULL,
    LastAssigned            TIMESTAMP NULL,
    INDEX                   Index_I (ControlledNameId),
    INDEX                   Index_N (ControlledName(16)),
    INDEX                   Index_F (FieldId),
    INDEX                   Index_A (LastAssigned)
);

-- possible variants on controlled names
CREATE TABLE IF NOT EXISTS VariantNames (
    ControlledNameId        INT NOT NULL,
    VariantName             TEXT DEFAULT NULL,
    INDEX                   Index_I (ControlledNameId),
    INDEX                   Index_V (VariantName(16))
);
-- add at least one variant name to avoid MySQL-related query problem
INSERT INTO VariantNames (ControlledNameId, VariantName) VALUES (-1, "DUMMY");

-- classifications (subjects, categories, etc)
CREATE TABLE IF NOT EXISTS Classifications (
    ClassificationId        INT NOT NULL AUTO_INCREMENT,
    FieldId                 INT NOT NULL,
    ClassificationName      TEXT DEFAULT NULL,
    Depth                   INT DEFAULT 0,
    ParentId                INT NOT NULL,
    SegmentName             TEXT DEFAULT NULL,
    ResourceCount           INT DEFAULT 0,
    FullResourceCount       INT DEFAULT 0,
    LinkString              TEXT DEFAULT NULL,
    QualifierId             INT DEFAULT NULL,
    LastAssigned            TIMESTAMP NULL,
    INDEX                   Index_I (ClassificationId),
    INDEX                   Index_P (ParentId),
    INDEX                   Index_F (FieldId),
    INDEX                   Index_FP (FieldId, ParentId),
    INDEX                   Index_A (LastAssigned),
    INDEX                   Index_NI (ClassificationName(16), FieldId),
    FULLTEXT                Index_SC (SegmentName, ClassificationName)
);

-- intersection table between Resources and ControlledNames
CREATE TABLE IF NOT EXISTS RecordNameInts (
    RecordId                INT NOT NULL,
    ControlledNameId        INT NOT NULL,
    INDEX                   Index_C (ControlledNameId),
    UNIQUE                  UIndex_RC (RecordId, ControlledNameId)
);

-- intersection table between Resources and Classifications
CREATE TABLE IF NOT EXISTS RecordClassInts (
    RecordId                INT NOT NULL,
    ClassificationId        INT NOT NULL,
    INDEX                   Index_C (ClassificationId),
    UNIQUE                  UIUndex_RC (RecordId, ClassificationId)
);

-- image information
-- (default ItemId and FieldId Matches Item::NO_ITEM)
CREATE TABLE IF NOT EXISTS Images (
    ImageId                 INT NOT NULL AUTO_INCREMENT,
    Format                  INT DEFAULT NULL,
    AltText                 TEXT DEFAULT NULL,
    FileLength              INT DEFAULT 0,
    FileChecksum            TEXT DEFAULT NULL,
    ContentLastChecked      DATETIME DEFAULT NULL,
    ContentUnchanged        INT DEFAULT 1,
    ItemId                  INT DEFAULT -2123456789,
    FieldId                 INT DEFAULT -2123456789,
    INDEX                   Index_I (ImageId),
    INDEX                   Index_TF (ItemId, FieldId)
);

-- attached files
CREATE TABLE IF NOT EXISTS Files (
    FileId                  INT NOT NULL AUTO_INCREMENT,
    RecordId                INT DEFAULT -2123456789,
    FieldId                 INT DEFAULT -2123456789,
    FileName                TEXT DEFAULT NULL,
    FileComment             TEXT DEFAULT NULL,
    FileLength              BIGINT UNSIGNED DEFAULT 0,
    FileChecksum            TEXT DEFAULT NULL,
    FileType                TEXT DEFAULT NULL,
    SecretString            TEXT DEFAULT NULL,
    ContentLastChecked      DATETIME DEFAULT NULL,
    ContentUnchanged        INT DEFAULT 1,
    INDEX                   Index_R (RecordId),
    INDEX                   Index_F (FileId),
    INDEX                   Index_RF (RecordId, FieldId)
);

-- resource modification timestamps
CREATE TABLE IF NOT EXISTS RecordFieldTimestamps (
    RecordId        INT NOT NULL,
    FieldId         INT NOT NULL,
    Timestamp       DATETIME DEFAULT NULL,
    ModifiedBy      INT NOT NULL,
    INDEX           Index_RF (RecordId, FieldId),
    INDEX           Index_T (Timestamp)
);

-- cache of which resources are viewable
CREATE TABLE IF NOT EXISTS UserPermsCache (
    RecordId INT DEFAULT NULL,
    UserClass TEXT DEFAULT NULL,
    CanView BOOL DEFAULT FALSE,
    ExpirationDate DATETIME DEFAULT NULL,
    UNIQUE UIndex_RU (RecordId, UserClass(32) ),
    INDEX Index_R (RecordId),
    INDEX Index_U (UserClass(32)),
    INDEX Index_E (ExpirationDate)
);

-- Count of resources assigned to each defined ControlledName
CREATE TABLE VisibleRecordCounts (
    SchemaId INT NOT NULL,
    UserClass TEXT NOT NULL,
    ValueId INT NOT NULL,
    ResourceCount INT NOT NULL,
    INDEX Index_SU(SchemaId,UserClass(32)),
    INDEX Index_V(ValueId)
);

CREATE TABLE IF NOT EXISTS CachedValues (
    Name TEXT DEFAULT NULL,
    Value BLOB DEFAULT NULL,
    Updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


-- resource comments
CREATE TABLE IF NOT EXISTS Messages (
    MessageId               INT NOT NULL AUTO_INCREMENT,
    ParentId                INT DEFAULT NULL,
    ParentType              INT DEFAULT NULL,
    DatePosted              DATETIME DEFAULT NULL,
    DateEdited              DATETIME DEFAULT NULL,
    PosterId                INT DEFAULT NULL,
    EditorId                INT DEFAULT NULL,
    Subject                 TEXT DEFAULT NULL,
    Body                    TEXT DEFAULT NULL,
    INDEX                   Index_MP (MessageId, ParentId)
);

-- resource searches performed by users or set up for use with user agents
CREATE TABLE IF NOT EXISTS Searches (
    SearchId                INT NOT NULL AUTO_INCREMENT,
    UserId                  INT NOT NULL,
    DateLastRun             DATETIME DEFAULT NULL,
    Keywords                TEXT DEFAULT NULL,
    RunInterval             INT DEFAULT NULL,
    INDEX                   Index_S (SearchId)
);


-- ----- RECOMMENDER SYSTEM --------------------------------------------------

-- correlation values for recommender system
CREATE TABLE IF NOT EXISTS RecContentCorrelations (
    ItemIdA                 INT NOT NULL,
    ItemIdB                 INT NOT NULL,
    Correlation             SMALLINT NOT NULL,
    INDEX                   Index_A (ItemIdA),
    INDEX                   Index_B (ItemIdB),
    INDEX                   Index_C (Correlation),
    INDEX                   Index_AB (ItemIdA, ItemIdB)
);


-- ----- FOLDERS ------------------------------------------------------------

-- folders for storing groups of items
CREATE TABLE IF NOT EXISTS Folders (
    FolderId                INT NOT NULL AUTO_INCREMENT,
    PreviousFolderId        INT DEFAULT NULL,
    NextFolderId            INT DEFAULT NULL,
    OwnerId                 INT DEFAULT NULL,
    FolderName              TEXT DEFAULT NULL,
    NormalizedName          TEXT DEFAULT NULL,
    FolderNote              TEXT DEFAULT NULL,
    CoverImageId            INT DEFAULT NULL,
    IsShared                INT DEFAULT 0,
    ContentType             INT DEFAULT -1,
    INDEX                   Index_O (OwnerId),
    INDEX                   Index_F (FolderId)
);

-- intersection table indicating which items are in which folders
CREATE TABLE IF NOT EXISTS FolderItemInts (
    FolderId                INT NOT NULL,
    ItemId                  INT NOT NULL,
    ItemTypeId              INT DEFAULT -1,
    PreviousItemId          INT DEFAULT -1,
    PreviousItemTypeId      INT DEFAULT -1,
    NextItemId              INT DEFAULT -1,
    NextItemTypeId          INT DEFAULT -1,
    ItemNote                TEXT DEFAULT NULL,
    INDEX                   Index_F (FolderId),
    INDEX                   Index_I (ItemId)
);

-- mapping of item type names to numerical item type IDs
CREATE TABLE IF NOT EXISTS FolderContentTypes (
    TypeId                  INT NOT NULL AUTO_INCREMENT,
    TypeName                TEXT DEFAULT NULL,
    NormalizedTypeName      TEXT DEFAULT NULL,
    INDEX                   Index_T (TypeId)
);
