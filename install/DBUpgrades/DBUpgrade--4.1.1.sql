ALTER TABLE ApplicationFrameworkSettings ADD COLUMN DbSlowQueryThresholdForeground INT DEFAULT 3;
ALTER TABLE ApplicationFrameworkSettings ADD COLUMN DbSlowQueryThresholdBackground INT DEFAULT 12;
