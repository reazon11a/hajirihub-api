-- 1. Join codes: join_code column already exists in classes table, skipping ALTER

-- 2. Leave requests: new table
CREATE TABLE leave_requests (
  id            VARCHAR(36)  NOT NULL PRIMARY KEY DEFAULT (UUID()),
  student_id    VARCHAR(36)  NOT NULL,
  class_id      VARCHAR(36)  NOT NULL,
  date          DATE         NOT NULL,
  reason        TEXT         NOT NULL,
  status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (class_id)   REFERENCES classes(id) ON DELETE CASCADE
);

-- Index for quick lookups
CREATE INDEX idx_leave_requests_student ON leave_requests(student_id);
CREATE INDEX idx_leave_requests_class   ON leave_requests(class_id);
CREATE INDEX idx_leave_requests_status  ON leave_requests(status);