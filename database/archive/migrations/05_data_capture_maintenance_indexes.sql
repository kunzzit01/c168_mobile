-- 05_data_capture_maintenance_indexes.sql
-- Speeds Maintenance - Transaction Data Capture branch (company + capture_date filter).

ALTER TABLE data_captures
  ADD INDEX idx_maint_company_capture_date (company_id, capture_date);

ALTER TABLE data_capture_details
  ADD INDEX idx_maint_company_capture (company_id, capture_id);
