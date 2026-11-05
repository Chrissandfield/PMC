-- Penshurst Park Cricket Club: the sponsored partner and first club.
-- The commission is 10% by default and is changed per club or coach in the admin area.
SET NAMES utf8mb4;

INSERT INTO clubs (slug, name, town, county, postcode, phone, email, sponsored_partner, crest_path,
                   safeguarding_name, safeguarding_contact, status)
VALUES ('penshurst-park', 'Penshurst Park Cricket Club', 'Penshurst', 'Kent', 'TN11 8DG',
        '+44 7917 730761', 'jake_cheeseman@yahoo.com', 1, 'penshurst-crest.png',
        'Laurie Hudson', 'laurie.hudson@bishopsgateinsurance.co.uk', 'pending');

SET @club = LAST_INSERT_ID();

INSERT INTO nets (club_id, name, kind, member_price_pence, nonmember_price_pence, machine_price_pence, machine_coach_only)
VALUES (@club, 'Net 1', 'outdoor', 0, 1000, 1200, 1);

SET @net = LAST_INSERT_ID();

INSERT INTO opening_hours (net_id, weekday, is_closed, open_hour, close_hour) VALUES
  (@net,0,0,8,20),(@net,1,0,8,20),(@net,2,0,8,20),(@net,3,0,8,20),(@net,4,0,8,20),(@net,5,0,8,20),(@net,6,0,8,20);

INSERT INTO coaches (club_id, name, hourly_price_pence, qualification, dbs_checked, dbs_checked_on) VALUES
  (@club, 'Jake Cheeseman', 3300, 'ECB Support Coach (Level 1)',    1, '2026-11-03'),
  (@club, 'Trevor Bailey',  3300, 'ECB Foundation Coach (Level 2)',  1, '2026-11-03'),
  (@club, 'Adam Ramson',    4400, 'Australian Level 1',              1, '2026-11-03');
