--
-- Database Upgrade Commands for Metavus 1.3.1
--
-- Copyright 2026 Edward Almasy and Internet Scout Research Group
-- http://metavus.net
--
-- IMPORTANT:  All commands must be idempotent and/or produce errors that
--      are covered by Bootloader::$SqlErrorsWeCanIgnore.
--

-- disable maximum length for existing paragraph metadata fields with current default
UPDATE MetadataFields SET MaxLength = 0 WHERE FieldType = 'Paragraph' AND MaxLength = 100;
