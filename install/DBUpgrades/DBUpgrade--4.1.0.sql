ALTER TABLE SystemConfiguration DROP COLUMN SecureLogin;
UPDATE SystemConfiguration SET DefaultActiveUI = "default" WHERE DefaultActiveUI = "SPTUI--CWIS";
