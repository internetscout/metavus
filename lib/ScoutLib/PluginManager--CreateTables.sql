
CREATE TABLE IF NOT EXISTS PluginInfo (
    PluginId    INT NOT NULL AUTO_INCREMENT,
    BaseName    TEXT,
    Version     TEXT,
    Cfg         MEDIUMTEXT,
    Installed   SMALLINT DEFAULT 0,
    Enabled     SMALLINT DEFAULT 0,
    DirectoryCache BLOB,
    DirectoryCacheLastUpdatedAt TIMESTAMP,
    INDEX       Index_P (PluginId),
    INDEX       Index_B (BaseName(12))
);

