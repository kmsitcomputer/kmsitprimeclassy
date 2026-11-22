-- IMP-010 / 001 — Go session storage.
--
-- WHY THIS EXISTS
-- The Go service cannot share Laravel's `sessions` rows: Laravel's `payload` column holds a
-- base64-encoded PHP-serialised session array, and reproducing PHP's serialisation + cookie encryption just
-- to read a session is a permanent coupling with no benefit. Go therefore keeps its own session rows in its
-- own table and its own cookie (pc_sid), while the browser-facing contract stays identical (HttpOnly session
-- cookie + readable XSRF-TOKEN cookie). See docs/21-IMP010-GO-BACKEND.md §Session interoperability.
--
-- SAFETY
--   * Additive only: it creates ONE new table and touches no existing table, column, index or row.
--   * Reversible: `DROP TABLE pc_go_sessions;` restores the previous schema exactly (nothing references it).
--   * Idempotent: CREATE TABLE IF NOT EXISTS, so re-running is a no-op.
--   * No foreign key to `users`: Laravel's own sessions table has none either, and a cascading FK would make
--     a user delete silently destroy audit-relevant session history.
CREATE TABLE IF NOT EXISTS pc_go_sessions (
    id            VARCHAR(64)  NOT NULL,
    user_id       BIGINT UNSIGNED NULL,
    csrf_token    VARCHAR(64)  NOT NULL,
    ip_address    VARCHAR(45)  NULL,
    user_agent    VARCHAR(255) NULL,
    last_activity INT          NOT NULL,
    expires_at    INT          NOT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY pc_go_sessions_user_id_index (user_id),
    KEY pc_go_sessions_expires_at_index (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
