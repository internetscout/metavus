--
-- Database Upgrade Commands for Metavus 1.3.0
--
-- Copyright 2026 Edward Almasy and Internet Scout Research Group
-- http://metavus.net
--
-- IMPORTANT:  All commands must be idempotent and/or produce errors that
--      are covered by Bootloader::$SqlErrorsWeCanIgnore.
--

-- add table to store user settings
CREATE TABLE IF NOT EXISTS APUserSettings (
  UserId            INT NOT NULL,
  OwnerName         TEXT DEFAULT NULL,
  SettingName       TEXT DEFAULT NULL,
  SettingValue      BLOB DEFAULT NULL,
  TimeLastUpdated   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX             Index_U (UserId),
  INDEX             Index_W (OwnerName(24)),
  UNIQUE            Index_UWS (UserId, OwnerName(24), SettingName(24))
);

-- add AF setting for toggling logging of DB locking activity
ALTER TABLE ApplicationFrameworkSettings ADD COLUMN LogDBLocking INT DEFAULT 0;
ALTER TABLE ApplicationFrameworkSettings ADD COLUMN LongDBLockThreshold INT DEFAULT 100;
-- drop AF setting for time at which last task was run (no longer used)
ALTER TABLE ApplicationFrameworkSettings DROP COLUMN LastTaskRunAt;

-- add RunAfter time for queued and running background tasks
ALTER TABLE TaskQueue ADD COLUMN RunAfter DATETIME DEFAULT NULL;
ALTER TABLE RunningTasks ADD COLUMN RunAfter DATETIME DEFAULT NULL;

-- add index to support claiming scheduled tasks when their RunAfter arrives
CREATE INDEX Index_RPT ON TaskQueue(RunAfter, Priority, TaskId);

-- add VocabularyEditable MField attribute
ALTER TABLE MetadataFields ADD COLUMN VocabularyEditable INT DEFAULT 1;

-- set values for VocabularyEditable
UPDATE MetadataFields SET VocabularyEditable = 1;

-- add delete privileges to metadata schemas table
ALTER TABLE MetadataSchemas ADD COLUMN DeletingPrivileges BLOB DEFAULT NULL;
