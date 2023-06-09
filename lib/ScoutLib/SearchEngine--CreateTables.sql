
-- list of search words
CREATE TABLE IF NOT EXISTS SearchWords (
    WordId          INT NOT NULL AUTO_INCREMENT,
    WordText        TEXT,
    INDEX           Index_T (WordText(16)),
    INDEX           Index_I (WordId)
);

-- list of search word stems
CREATE TABLE IF NOT EXISTS SearchStems (
    WordId          INT NOT NULL AUTO_INCREMENT,
    WordText        TEXT,
    INDEX           Index_T (WordText(16)),
    INDEX           Index_I (WordId)
);

-- lookup table for counts of word occurences
CREATE TABLE IF NOT EXISTS SearchWordCounts (
    WordId          INT NOT NULL,
    ItemId          INT NOT NULL,
    FieldId         SMALLINT NOT NULL,
    Count           SMALLINT,
    INDEX           Index_I (ItemId),
    UNIQUE          UIndex_WFI (WordId, FieldId, ItemId)
);

-- list of link between synonyms
CREATE TABLE IF NOT EXISTS SearchWordSynonyms (
    WordIdA         INT NOT NULL,
    WordIdB         INT NOT NULL,
    INDEX           Index_AB (WordIdA, WordIdB)
);

-- list of item types
CREATE TABLE IF NOT EXISTS SearchItemTypes (
    ItemId          INT NOT NULL,
    ItemType        SMALLINT NOT NULL,
    INDEX           Index_IT (ItemId, ItemType)
);

