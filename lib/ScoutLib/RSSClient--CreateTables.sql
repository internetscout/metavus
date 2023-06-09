
-- RSS feed cache
CREATE TABLE IF NOT EXISTS RSSClientCache (
    ServerUrl      TEXT,
    CachedXml      TEXT,
    Type           TEXT,
    Charset        TEXT,
    LastQueryTime  DATETIME
);
