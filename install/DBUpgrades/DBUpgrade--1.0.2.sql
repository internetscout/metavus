--
-- Database Upgrade Commands for Metavus 1.0.2
--
-- Copyright 2023 Edward Almasy and Internet Scout Research Group
-- http://metavus.net
--
-- IMPORTANT:  All commands must be idempotent and/or produce errors that
--      are covered by Bootloader::$SqlErrorsWeCanIgnore.
--

-- recreate possibly-awry index for Recommender
DROP INDEX Index_AB ON RecContentCorrelations;
CREATE INDEX Index_AB ON RecContentCorrelations(ItemIdA, ItemIdB);
-- add indexes to help Recommender with data retrieval and cleanups
CREATE INDEX Index_A ON RecContentCorrelations(ItemIdA);
CREATE INDEX Index_B ON RecContentCorrelations(ItemIdB);
CREATE INDEX Index_C ON RecContentCorrelations(Correlation);

-- clear invalid DefaultQualifier settings from MetadataFields
UPDATE MetadataFields SET DefaultQualifier = NULL WHERE DefaultQualifier IS NOT NULL AND DefaultQualifier NOT IN (SELECT QualifierID FROM Qualifiers);

-- add directory cache columns to PluginInfo
ALTER TABLE PluginInfo ADD COLUMN DirectoryCache BLOB;
ALTER TABLE PluginInfo ADD COLUMN DirectoryCacheLastUpdatedAt TIMESTAMP;
