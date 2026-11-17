-- Private calendar subscription links (iCal feeds). Created on first use; "new link" replaces the old one.
ALTER TABLE clubs   ADD COLUMN feed_token VARCHAR(40) NULL;
ALTER TABLE coaches ADD COLUMN feed_token VARCHAR(40) NULL;
CREATE UNIQUE INDEX clubs_feed_token_uq   ON clubs (feed_token);
CREATE UNIQUE INDEX coaches_feed_token_uq ON coaches (feed_token);
