-- Club website/social link on the application, and a record when an application is declined.
ALTER TABLE clubs ADD COLUMN web_link VARCHAR(250) NULL;
ALTER TABLE clubs ADD COLUMN declined_at DATETIME NULL;
ALTER TABLE clubs ADD COLUMN decline_reason VARCHAR(500) NULL;
