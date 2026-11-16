-- Correct the spelling of a coach's surname (Bayley, not Bailey). Safe to run more than once.
UPDATE coaches SET name = 'Trevor Bayley' WHERE name = 'Trevor Bailey';
