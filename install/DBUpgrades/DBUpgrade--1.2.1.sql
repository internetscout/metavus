--
-- Database Upgrade Commands for Metavus 1.2.1
--
-- Copyright 2025 Edward Almasy and Internet Scout Research Group
-- http://metavus.net
--
-- IMPORTANT:  All commands must be idempotent and/or produce errors that
--      are covered by Bootloader::$SqlErrorsWeCanIgnore.
--

-- add columns to store sort info for folders
ALTER TABLE Folders ADD COLUMN SortFieldId INT DEFAULT NULL;
ALTER TABLE Folders ADD COLUMN ReverseSortFlag TINYINT DEFAULT 0;
