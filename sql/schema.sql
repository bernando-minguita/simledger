-- ============================================================================
--  SIMLedger - database schema
--  MySQL 8+ (uses generated columns for active-assignment uniqueness guards)
--
--  PREFERRED INSTALL: open install.php in a browser. It creates the database,
--  runs this file, seeds terminals/carriers/roles/settings and creates the
--  administrator account.
--
--  MANUAL INSTALL:  mysql -u root -p < sql/schema.sql
-- ============================================================================

CREATE DATABASE IF NOT EXISTS simledger
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE simledger;

-- Drop in foreign-key safe order --------------------------------------------
DROP TABLE IF EXISTS api_tokens;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS driver_contact_numbers;
DROP TABLE IF EXISTS driver_sim_assignments;
DROP TABLE IF EXISTS company_sims;
DROP TABLE IF EXISTS drivers;
DROP TABLE IF EXISTS agencies;
DROP TABLE IF EXISTS carriers;
DROP TABLE IF EXISTS user_permissions;
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS permissions;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS terminals;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS app_settings;

-- ----------------------------------------------------------------------------
-- app_settings : key/value configuration (never hard-code business options)
-- ----------------------------------------------------------------------------
CREATE TABLE app_settings (
    `key`   VARCHAR(64)  NOT NULL,
    `value` VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- roles : role master (admin / manager / dispatcher)
-- ----------------------------------------------------------------------------
CREATE TABLE roles (
    id         INT         NOT NULL AUTO_INCREMENT,
    `key`      VARCHAR(30) NOT NULL,
    name       VARCHAR(60) NOT NULL,
    is_active  TINYINT(1)  NOT NULL DEFAULT 1,
    created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- terminals : configurable branch master (Dammam / Riyadh / Jeddah seeded by
-- install.php). Never hard-code terminal names in application code.
-- ----------------------------------------------------------------------------
CREATE TABLE terminals (
    id         INT         NOT NULL AUTO_INCREMENT,
    name       VARCHAR(60) NOT NULL,
    code       VARCHAR(10) NOT NULL,
    is_active  TINYINT(1)  NOT NULL DEFAULT 1,
    created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_terminals_name (name),
    UNIQUE KEY uq_terminals_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- carriers : configurable carrier master (STC / Mobily / Zain / Other)
-- ----------------------------------------------------------------------------
CREATE TABLE carriers (
    id         INT         NOT NULL AUTO_INCREMENT,
    name       VARCHAR(60) NOT NULL,
    is_active  TINYINT(1)  NOT NULL DEFAULT 1,
    created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_carriers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- agencies : driver outsourcing providers (SMASCO / SRACO / TAMKEEN / RT).
-- RT is the owning company's own drivers. Drivers always belong to one agency.
-- ------------------------------------------------------------------------------
CREATE TABLE agencies (
    id         INT          NOT NULL AUTO_INCREMENT,
    name       VARCHAR(60)  NOT NULL,
    code       VARCHAR(10)  NOT NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_agencies_name (name),
    UNIQUE KEY uq_agencies_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- users : application accounts (session based login, secure password hash)
-- ----------------------------------------------------------------------------
CREATE TABLE users (
    id            INT          NOT NULL AUTO_INCREMENT,
    username      VARCHAR(50)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name          VARCHAR(100) NOT NULL DEFAULT '',
    role_id       INT          NOT NULL,
    terminal_id   INT          DEFAULT NULL,   -- NULL = all terminals (admin/manager)
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at TIMESTAMP    DEFAULT NULL,
    totp_secret   VARCHAR(64)  DEFAULT NULL,   -- TOTP base32 secret (2FA enrolment)
    totp_enabled  TINYINT(1)   NOT NULL DEFAULT 0,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0, -- admin reset: change at next login
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_role (role_id),
    KEY idx_users_terminal (terminal_id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id),
    CONSTRAINT fk_users_terminal FOREIGN KEY (terminal_id) REFERENCES terminals (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- drivers : driver master reference.
-- external_driver_id is the stable identifier reserved for future
-- synchronization with the Driver Address Book / HR system.
-- ----------------------------------------------------------------------------
CREATE TABLE drivers (
    id                 INT          NOT NULL AUTO_INCREMENT,
    badge_number       VARCHAR(30)  NOT NULL,
    name               VARCHAR(120) NOT NULL,
    terminal_id        INT          NOT NULL,
    status             ENUM('active','resigned','terminated','inactive','transferred')
                                      NOT NULL DEFAULT 'active',
    external_driver_id VARCHAR(60)  DEFAULT NULL,
    agency_id          INT          DEFAULT NULL,     -- outsourcing agency (RT = own company)
    iqama_number       VARCHAR(20)  DEFAULT NULL,     -- residency permit number
    nationality        VARCHAR(60)  DEFAULT NULL,
    is_active          TINYINT(1)   NOT NULL DEFAULT 1,
    notes              VARCHAR(255) DEFAULT NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_drivers_badge (badge_number),
    KEY idx_drivers_name (name),
    KEY idx_drivers_terminal (terminal_id),
    KEY idx_drivers_status (status),
    KEY idx_drivers_external (external_driver_id),
    UNIQUE KEY uq_drivers_iqama (iqama_number),
    KEY idx_drivers_agency (agency_id),
    CONSTRAINT fk_drivers_terminal FOREIGN KEY (terminal_id) REFERENCES terminals (id),
    CONSTRAINT fk_drivers_agency FOREIGN KEY (agency_id) REFERENCES agencies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- company_sims : master inventory of COMPANY-OWNED SIM cards.
-- The mobile number lives here (company asset), never on the driver record.
-- normalized_mobile_number is the uniqueness key so 0551234567,
-- +966551234567 and 966551234567 cannot create duplicates.
-- ----------------------------------------------------------------------------
CREATE TABLE company_sims (
    id                       INT          NOT NULL AUTO_INCREMENT,
    sim_reference            VARCHAR(20)  DEFAULT NULL,     -- e.g. SIM-000123
    mobile_number            VARCHAR(20)  NOT NULL,         -- display form 05XXXXXXXX
    normalized_mobile_number VARCHAR(20)  NOT NULL,         -- +9665XXXXXXXX
    iccid                    VARCHAR(25)  DEFAULT NULL,
    carrier_id               INT          NOT NULL,
    terminal_id              INT          NOT NULL,
    status                   ENUM('available','assigned','suspended','lost','damaged','retired')
                                          NOT NULL DEFAULT 'available',
    notes                    VARCHAR(255) DEFAULT NULL,
    created_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by               INT          DEFAULT NULL,
    updated_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by               INT          DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sims_normalized_mobile (normalized_mobile_number),
    UNIQUE KEY uq_sims_iccid (iccid),
    KEY idx_sims_mobile (mobile_number),
    KEY idx_sims_terminal (terminal_id),
    KEY idx_sims_status (status),
    KEY idx_sims_carrier (carrier_id),
    KEY idx_sims_reference (sim_reference),
    CONSTRAINT fk_sims_carrier FOREIGN KEY (carrier_id) REFERENCES carriers (id),
    CONSTRAINT fk_sims_terminal FOREIGN KEY (terminal_id) REFERENCES terminals (id),
    CONSTRAINT fk_sims_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_sims_updated_by FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- driver_sim_assignments : append-only assignment history. A row is never
-- deleted or rewritten once closed; closures set returned_at / returned_by /
-- return_reason. The generated guard columns give database-level enforcement:
--   sim_active_guard    -> only ONE open assignment per SIM
--   driver_active_guard -> only ONE open assignment per driver
-- (Both evaluate to NULL when the assignment is closed; MySQL UNIQUE permits
--  multiple NULLs. Allowing several SIMs per driver would require a
--  coordinated schema + service migration; no runtime switch exists.)
-- ----------------------------------------------------------------------------
CREATE TABLE driver_sim_assignments (
    id                INT          NOT NULL AUTO_INCREMENT,
    driver_id         INT          NOT NULL,
    sim_id            INT          NOT NULL,
    terminal_id       INT          NOT NULL,
    issued_at         DATETIME     NOT NULL,
    issued_by         INT          NOT NULL,
    returned_at       DATETIME     DEFAULT NULL,
    returned_by       INT          DEFAULT NULL,
    return_reason     ENUM('resignation','transfer','vacation','replacement',
                           'lost_damaged','sim_change','other') DEFAULT NULL,
    assignment_reason VARCHAR(60)  DEFAULT NULL,   -- issue/transfer/replacement context
    remarks           VARCHAR(255) DEFAULT NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sim_active_guard    INT AS (IF(returned_at IS NULL, 1, NULL)) STORED,
    driver_active_guard INT AS (IF(returned_at IS NULL, 1, NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assign_active_sim (sim_id, sim_active_guard),
    UNIQUE KEY uq_assign_active_driver (driver_id, driver_active_guard),
    KEY idx_assign_driver (driver_id),
    KEY idx_assign_sim (sim_id),
    KEY idx_assign_terminal (terminal_id),
    KEY idx_assign_issued (issued_at),
    KEY idx_assign_returned (returned_at),
    KEY idx_assign_issued_by (issued_by),
    KEY idx_assign_returned_by (returned_by),
    CONSTRAINT fk_assign_driver FOREIGN KEY (driver_id) REFERENCES drivers (id),
    CONSTRAINT fk_assign_sim FOREIGN KEY (sim_id) REFERENCES company_sims (id),
    CONSTRAINT fk_assign_terminal FOREIGN KEY (terminal_id) REFERENCES terminals (id),
    CONSTRAINT fk_assign_issued_by FOREIGN KEY (issued_by) REFERENCES users (id),
    CONSTRAINT fk_assign_returned_by FOREIGN KEY (returned_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- driver_contact_numbers : driver-owned PERSONAL contact numbers.
-- These are contact details only - never company inventory, never a
-- substitute for a company SIM assignment.
-- ----------------------------------------------------------------------------
CREATE TABLE driver_contact_numbers (
    id                    INT          NOT NULL AUTO_INCREMENT,
    driver_id             INT          NOT NULL,
    contact_type          ENUM('personal','emergency','other') NOT NULL DEFAULT 'personal',
    phone_number          VARCHAR(20)  NOT NULL,   -- display form 05XXXXXXXX
    normalized_phone_number VARCHAR(20) NOT NULL,  -- +9665XXXXXXXX
    is_primary            TINYINT(1)   NOT NULL DEFAULT 0,
    is_active             TINYINT(1)   NOT NULL DEFAULT 1,
    notes                 VARCHAR(255) DEFAULT NULL,
    created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by            INT          DEFAULT NULL,
    updated_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by            INT          DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_contacts_driver (driver_id),
    KEY idx_contacts_normalized (normalized_phone_number),
    KEY idx_contacts_type (contact_type),
    CONSTRAINT fk_contacts_driver FOREIGN KEY (driver_id) REFERENCES drivers (id),
    CONSTRAINT fk_contacts_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_contacts_updated_by FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- audit_logs : append-only trail of WHO / WHAT / WHEN / WHERE / WHY.
-- No application code updates or deletes rows in this table.
-- ----------------------------------------------------------------------------
CREATE TABLE audit_logs (
    id          BIGINT       NOT NULL AUTO_INCREMENT,
    user_id     INT          DEFAULT NULL,
    username    VARCHAR(50)  DEFAULT NULL,
    action      VARCHAR(40)  NOT NULL,
    entity_type VARCHAR(40)  NOT NULL,
    entity_id   INT          DEFAULT NULL,
    old_values  JSON         DEFAULT NULL,
    new_values  JSON         DEFAULT NULL,
    terminal_id INT          DEFAULT NULL,
    ip_address  VARCHAR(45)  DEFAULT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_user (user_id),
    KEY idx_audit_action (action),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_terminal (terminal_id),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- permissions / role_permissions / user_permissions : fine-grained access.
-- Roles carry a default permission set (group-level assignment); individual
-- users can be fine-tuned on top with per-permission allow/deny overrides.
-- The admin role implicitly holds every permission. The catalog and default
-- role grants are seeded by includes/permissions.php (permissionsBootstrap).
-- ----------------------------------------------------------------------------

CREATE TABLE permissions (
    id          INT          NOT NULL AUTO_INCREMENT,
    `key`       VARCHAR(60)  NOT NULL,
    name        VARCHAR(80)  NOT NULL,
    group_name  VARCHAR(60)  NOT NULL,
    description VARCHAR(200) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id       INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_permissions (
    user_id       INT NOT NULL,
    permission_id INT NOT NULL,
    effect        ENUM('allow','deny') NOT NULL,
    PRIMARY KEY (user_id, permission_id),
    CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_up_perm FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- api_tokens : bearer tokens for the read-only external API (api/).
-- One named token per consuming system (Leave Management, payroll...). Only
-- the SHA-256 hash of the token is stored - the plaintext is displayed once,
-- immediately after an administrator mints it in Admin -> API Tokens.
-- Revoking is a soft action (is_active = 0); rows are never deleted so the
-- audit trail keeps naming them.
-- ----------------------------------------------------------------------------
CREATE TABLE api_tokens (
    id           INT          NOT NULL AUTO_INCREMENT,
    name         VARCHAR(64)  NOT NULL,          -- e.g. "Leave Management"
    token_hash   CHAR(64)     NOT NULL,          -- sha256(plaintext token)
    prefix       VARCHAR(16)  NOT NULL,          -- display-only first chars
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_by   INT          DEFAULT NULL,      -- users.id of the minter
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME     DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    KEY idx_api_tokens_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
