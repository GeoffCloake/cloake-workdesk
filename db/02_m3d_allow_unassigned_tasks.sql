-- M3D staging-only structural adjustment. Backup staging before executing.
-- Run once, AFTER preflight; needed because original tasks include unallocated client IDs.
-- (USE statement removed for test use)
ALTER TABLE `tasks` MODIFY COLUMN `client_id` BIGINT UNSIGNED NULL;
