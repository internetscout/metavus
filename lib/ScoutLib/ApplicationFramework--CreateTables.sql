
CREATE TABLE IF NOT EXISTS ApplicationFrameworkSettings (
    BasePath                        TEXT DEFAULT NULL,
    BasePathCheck                   TEXT DEFAULT NULL,
    DbSlowQueryThresholdBackground  INT DEFAULT 12,
    DbSlowQueryThresholdForeground  INT DEFAULT 3,
    GenerateCompactCss              INT DEFAULT 1,
    HighMemoryUsageThreshold        INT DEFAULT 90,
    JavascriptMinimizationEnabled   INT DEFAULT 1,
    LastTaskRunAt                   DATETIME DEFAULT NULL,
    LogHighMemoryUsage              INT DEFAULT 0,
    LogPhpNotices                   INT DEFAULT 0,
    LogDBCachePruning               INT DEFAULT 0,
    LogSlowPageLoads                INT DEFAULT 0,
    LoggingLevel                    INT DEFAULT 4,
    MaxExecTime                     INT DEFAULT 300,
    MaxTasksRunning                 INT DEFAULT 2,
    ObjectLocationCache             MEDIUMBLOB DEFAULT NULL,
    ObjectLocationCacheExpiration   DATETIME DEFAULT NULL,
    ObjectLocationCacheInterval     INT DEFAULT 1440,
    PageCacheEnabled                INT DEFAULT 1,
    PageCacheExpirationPeriod       INT DEFAULT 1440,
    ScssSupportEnabled              INT DEFAULT 1,
    SessionLifetime                 INT DEFAULT 1800,
    SlowPageLoadThreshold           INT DEFAULT 10,
    TaskExecutionEnabled            INT DEFAULT 1,
    TemplateLocationCache           MEDIUMBLOB DEFAULT NULL,
    TemplateLocationCacheExpiration DATETIME DEFAULT NULL,
    TemplateLocationCacheInterval   INT DEFAULT 1440,
    UrlFingerprintingEnabled        INT DEFAULT 1,
    UseMinimizedJavascript          INT DEFAULT 1
);

CREATE TABLE IF NOT EXISTS TaskQueue (
    TaskId      INT NOT NULL AUTO_INCREMENT,
    Callback    BLOB DEFAULT NULL,
    Parameters  MEDIUMBLOB DEFAULT NULL,
    Priority    INT DEFAULT 3,
    Description TEXT DEFAULT NULL,
    INDEX       Index_I (TaskId),
    INDEX       Index_PI (Priority, TaskId),
    INDEX       Index_CM (Callback(64), Parameters(256))
);

-- (RunningTasks table must match TaskQueue table except for StartedAt and CrashInfo)
CREATE TABLE IF NOT EXISTS RunningTasks (
    TaskId      INT NOT NULL,
    Callback    BLOB DEFAULT NULL,
    Parameters  MEDIUMBLOB DEFAULT NULL,
    Priority    INT DEFAULT 3,
    Description TEXT DEFAULT NULL,
    StartedAt   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CrashInfo   MEDIUMBLOB DEFAULT NULL,
    INDEX       (TaskId),
    INDEX       (Callback(64), Parameters(256))
);

CREATE TABLE IF NOT EXISTS PeriodicEvents (
    Signature   TEXT DEFAULT NULL,
    LastRunAt   DATETIME DEFAULT NULL,
    INDEX       Index_S (Signature(24))
);

CREATE TABLE IF NOT EXISTS AF_CachedPages (
    CacheId         INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Fingerprint     TEXT DEFAULT NULL,
    PageContent     MEDIUMBLOB DEFAULT NULL,
    CachedAt        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ExpirationDate  DATETIME DEFAULT NULL,
    INDEX           Index_F (Fingerprint(48)),
    INDEX           Index_A (CachedAt),
    INDEX           Index_E (ExpirationDate)
);

CREATE TABLE IF NOT EXISTS AF_CachedPageTags (
    TagId       INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Tag         TEXT DEFAULT NULL,
    INDEX       Index_T (Tag(16)),
    INDEX       Index_I (TagId)
);

CREATE TABLE IF NOT EXISTS AF_CachedPageTagInts (
    TagId       INT NOT NULL,
    CacheId     INT NOT NULL,
    INDEX       Index_T (TagId),
    INDEX       Index_CT (CacheId, TagId)
);

CREATE TABLE IF NOT EXISTS AF_CachedPageCallbacks (
    CacheId     INT NOT NULL PRIMARY KEY,
    Callbacks   MEDIUMBLOB DEFAULT NULL,
    INDEX       Index_C (CacheId)
);

CREATE TABLE IF NOT EXISTS AF_Locks (
    LockName    TEXT DEFAULT NULL,
    ObtainedAt  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX       Index_N (LockName(16)),
    INDEX       Index_A (ObtainedAt)
);

