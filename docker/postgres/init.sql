-- Runs once, when the pgdata volume is first created.
-- Separate database for automated tests so `make test` never touches dev data.
CREATE DATABASE videoplatform_test OWNER videoplatform;
