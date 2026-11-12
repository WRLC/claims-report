-- Preserve case and activity history when an account is permanently deleted.
ALTER TABLE claims_cases MODIFY created_by BIGINT UNSIGNED NULL;
ALTER TABLE claims_cases DROP FOREIGN KEY fk_case_creator;
ALTER TABLE claims_cases ADD CONSTRAINT fk_case_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE claims_cases DROP FOREIGN KEY fk_case_assignee;
ALTER TABLE claims_cases ADD CONSTRAINT fk_case_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE case_events DROP FOREIGN KEY fk_event_actor;
ALTER TABLE case_events ADD CONSTRAINT fk_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL;
