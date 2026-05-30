-- MSP-Vault dev database init
-- Creates a second test database alongside the main one
CREATE DATABASE IF NOT EXISTS mspvault_test;
GRANT ALL PRIVILEGES ON mspvault_test.* TO 'mspvault'@'%';
FLUSH PRIVILEGES;
