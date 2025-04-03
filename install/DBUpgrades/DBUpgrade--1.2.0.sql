--
-- Database Upgrade Commands for Metavus 1.2.0
--
-- Copyright 2025 Edward Almasy and Internet Scout Research Group
-- http://metavus.net
--
-- IMPORTANT:  All commands must be idempotent and/or produce errors that
--      are covered by Bootloader::$SqlErrorsWeCanIgnore.
--

-- add TriggersAutoUpdates MField attribute
ALTER TABLE MetadataFields ADD COLUMN TriggersAutoUpdates INT DEFAULT 1;

-- set values for TriggersAutoUpdates for workflow fields in any schema
UPDATE MetadataFields SET TriggersAutoUpdates = 0 WHERE FieldName='Added By Id';
UPDATE MetadataFields SET TriggersAutoUpdates = 0 WHERE FieldName='Date Last Modified';
UPDATE MetadataFields SET TriggersAutoUpdates = 0 WHERE FieldName='Date Of Record Creation';
UPDATE MetadataFields SET TriggersAutoUpdates = 0 WHERE FieldName='Date Of Record Release';
UPDATE MetadataFields SET TriggersAutoUpdates = 0 WHERE FieldName='Last Modified By Id';

-- set schema-specific values for TriggersAutoUpdates
UPDATE MetadataFields SET TriggersAutoUpdates = 0 WHERE FieldName='Cumulative Rating' AND SchemaId=0;
UPDATE MetadataFields SET TriggersAutoUpdates = 0 WHERE FieldName IN ('Date of Modification', 'Date of Publication', 'Author', 'Editor')  AND SchemaId IN (SELECT SchemaId FROM MetadataSchemas WHERE Name = 'Blog');
UPDATE MetadataFields SET TriggersAutoUpdates = 0 WHERE FieldName='Creation Date' AND SchemaId IN (SELECT SchemaId FROM MetadataSchemas WHERE Name = 'Microsite Pages');
UPDATE MetadataFields SET TriggersAutoUpdates = 0 WHERE FieldName='Original Page' AND SchemaId IN (SELECT SchemaId FROM MetadataSchemas WHERE Name = 'Microsite Pages');
UPDATE MetadataFields SET TriggersAutoUpdates = 0 WHERE FieldName='Creation Date' AND SchemaId IN (SELECT SchemaId FROM MetadataSchemas WHERE Name = 'Pages');
UPDATE MetadataFields SET TriggersAutoUpdates = 0 WHERE FieldName='Cumulative Rating' AND SchemaId IN (SELECT SchemaId FROM MetadataSchemas WHERE Name = 'Photos');

-- add AF setting for toggling logging of DB cache pruning activity
ALTER TABLE ApplicationFrameworkSettings ADD COLUMN LogDBCachePruning INT DEFAULT 0;

-- fix last login date for accounts created with code from before 10/29/21
UPDATE APUsers SET LastLoginDate = NULL WHERE LastLoginDate < "0000-01-01 00:00:00";

-- add column for storing cover image info for folders
ALTER TABLE Folders ADD COLUMN CoverImageId INT DEFAULT NULL;

-- remove special-purpose table for facet caching (which now uses DataCache)
DROP TABLE IF EXISTS SearchFacetCache;

-- set AF page cache expiration period to 24 hours in minutes if it was
--      previously set to 24 hours in seconds (i.e. the old pre-1.2.0 default)
UPDATE ApplicationFrameworkSettings SET PageCacheExpirationPeriod = 1440 WHERE PageCacheExpirationPeriod = 21600;
