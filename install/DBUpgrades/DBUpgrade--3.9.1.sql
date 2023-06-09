UPDATE MetadataFields SET UpdateMethod="OnRecordCreate"
    WHERE SchemaId=0 AND FieldName="Added By Id";

UPDATE MetadataFields SET UpdateMethod="OnRecordChange"
    WHERE SchemaId=0 AND FieldName="Last Modified By Id";

CREATE TABLE IF NOT EXISTS AF_Locks (
    LockName    TEXT DEFAULT NULL,
    ObtainedAt  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX       Index_N (LockName(16)),
    INDEX       Index_A (ObtainedAt)
);

