-- Record whether the confirmed DBS check was an Enhanced check including the children's barred list.
ALTER TABLE coaches ADD COLUMN dbs_enhanced TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE dbs_confirmations ADD COLUMN enhanced_barred TINYINT(1) NOT NULL DEFAULT 0;
