-- 003: followup enhancements
-- 1) Soft-delete + search support on customer_timeline (nothing new for followups needed:
--    followups already has deleted_at, description, result, due_date, status).
-- 2) New timeline event types used by the followup center:
--    'followup'        -> result recorded (existing)
--    'followup_created'-> auto followup created (existing)
--    'followup_done'   -> followup marked as performed (completed) [new]
--    'followup_scheduled' -> next followup scheduled N days later [new]
ALTER TABLE customer_timeline
  ADD COLUMN deleted_at DATETIME NULL AFTER created_at,
  ADD INDEX idx_timeline_active (customer_id, created_at);
