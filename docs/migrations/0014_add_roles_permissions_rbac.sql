-- Role-Based Access Control: replaces migration 0007's hardcoded
-- admins.role ENUM('admin','staff','sales') with a normalized, data-driven
-- model (roles / permissions / role_permissions) so new roles or
-- permissions can be added later with INSERT statements, never a schema
-- change or code deploy. See docs/rbac.md for the full design.
--
-- Run against an existing tree_qr_system database that predates these
-- tables (a fresh install via docs/install.sql already includes them).
USE tree_qr_system;

CREATE TABLE roles (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_key    VARCHAR(40) NOT NULL UNIQUE,
    name_th     VARCHAR(100) NOT NULL,
    name_en     VARCHAR(100) NOT NULL,
    description TEXT NULL,
    is_system   TINYINT(1) NOT NULL DEFAULT 0, -- built-in roles — can't be deleted via admin UI
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(60) NOT NULL UNIQUE,
    module         VARCHAR(40) NOT NULL,
    description    TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id       BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role       FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE admins
  ADD COLUMN role_id BIGINT UNSIGNED NULL AFTER password_hash,
  ADD CONSTRAINT fk_admins_role FOREIGN KEY (role_id) REFERENCES roles(id);

-- ---------------------------------------------------------------
-- Seed the 3 built-in roles
-- ---------------------------------------------------------------
INSERT INTO roles (role_key, name_th, name_en, description, is_system) VALUES
('programmer', 'โปรแกรมเมอร์', 'Programmer', 'สิทธิ์สูงสุด เข้าถึงได้ทุกส่วนของระบบ CRUD ทุกอย่าง', 1),
('executive', 'ผู้บริหาร', 'Executive', 'ดูแดชบอร์ดและรายงานเพื่อการตัดสินใจ อ่านอย่างเดียว แก้ไข/ลบข้อมูลไม่ได้', 1),
('tree_admin', 'ผู้ดูแลข้อมูลต้นไม้', 'Tree Admin', 'จัดการข้อมูลต้นไม้ ชนิดพันธุ์ โซน แหล่งที่มา และ QR Code ประจำวัน', 1);

-- ---------------------------------------------------------------
-- Seed the permission catalog (see docs/rbac.md §3 for the full taxonomy)
-- ---------------------------------------------------------------
INSERT INTO permissions (permission_key, module, description) VALUES
('tree.view', 'tree', 'ดูรายการ/รายละเอียดต้นไม้ในระบบแอดมิน'),
('tree.create', 'tree', 'เพิ่มต้นไม้ใหม่'),
('tree.update', 'tree', 'แก้ไขข้อมูลต้นไม้ที่มีอยู่'),
('tree.status.manage', 'tree', 'สลับสถานะ Active/Inactive (ใช้แทนการลบถาวร)'),
('tree.image.manage', 'tree', 'อัปโหลด/เปลี่ยนรูปต้นไม้'),
('tree.order.manage', 'tree', 'จัดลำดับการแสดงผล (Previous/Next)'),
('tree.map.manage', 'tree', 'ตั้งค่าแผนที่เฉพาะต้น (ภาพ/ลิงก์)'),
('tree.relationship.manage', 'tree', 'จัดการความสัมพันธ์ต้นก่อนหน้า/ถัดไป'),
('tree.location.manage', 'tree', 'ย้ายต้นไม้ไปตำแหน่ง/โซน/แหล่งที่มาใหม่'),
('category.manage', 'master_data', 'จัดการประเภทพืช'),
('species.manage', 'master_data', 'จัดการชนิดพันธุ์พืช'),
('zone.manage', 'master_data', 'จัดการโซน/ตำแหน่ง'),
('origin.manage', 'master_data', 'จัดการแหล่งที่มาของต้นไม้'),
('qrcode.manage', 'qr', 'สร้าง/พิมพ์ QR Code'),
('translation.request', 'translation', 'สั่งให้ AI แปลข้อความ'),
('translation.review', 'translation', 'ตรวจสอบ/อนุมัติคำแปลของ AI'),
('admin.manage', 'admin_accounts', 'จัดการบัญชีผู้ดูแลระบบและบทบาท'),
('gemini.config.manage', 'admin_accounts', 'ตั้งค่า Gemini API key/model'),
('scan.view', 'visitor_data', 'ดูประวัติ/สถิติการสแกน'),
('visitor.view', 'visitor_data', 'ดูข้อมูลผู้เข้าชม (Visitor ID, IP, User-Agent)'),
('interest.view', 'visitor_data', 'ดูรายชื่ออีเมลผู้สนใจ'),
('interest.manage', 'visitor_data', 'อัปเดตสถานะการติดตามผู้สนใจ (Lead)'),
('dashboard.view', 'reporting', 'เข้าถึงแดชบอร์ดผู้บริหาร'),
('stats.tree.view', 'reporting', 'สถิติรายต้น'),
('stats.category.view', 'reporting', 'สถิติรายประเภทพืช'),
('stats.zone.view', 'reporting', 'สถิติรายโซน'),
('trends.view', 'reporting', 'แนวโน้มผู้เข้าชม/การสแกนตามช่วงเวลา'),
('revenue.view', 'reporting', 'ข้อมูลราคา/ยอดขาย (ถ้ามี)'),
('reports.export', 'reporting', 'ส่งออกรายงานเป็น CSV'),
('map.settings.manage', 'system', 'ตั้งค่าแผนที่เริ่มต้นของทั้งระบบ'),
('settings.manage', 'system', 'ตั้งค่าระดับระบบ (โลโก้ ฯลฯ)');

-- ---------------------------------------------------------------
-- Grant permissions per role (see docs/rbac.md §4 matrix)
-- ---------------------------------------------------------------

-- Programmer: everything.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.role_key = 'programmer';

-- Executive: read-only dashboard/reporting.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.role_key = 'executive'
  AND p.permission_key IN (
    'scan.view', 'visitor.view', 'interest.view',
    'dashboard.view', 'stats.tree.view', 'stats.category.view', 'stats.zone.view',
    'trends.view', 'revenue.view', 'reports.export'
  );

-- Tree Admin: day-to-day plant-asset data management, no admin/system access.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.role_key = 'tree_admin'
  AND p.permission_key IN (
    'tree.view', 'tree.create', 'tree.update', 'tree.status.manage', 'tree.image.manage',
    'tree.order.manage', 'tree.map.manage', 'tree.relationship.manage', 'tree.location.manage',
    'category.manage', 'species.manage', 'zone.manage', 'origin.manage', 'qrcode.manage',
    'translation.request', 'translation.review',
    'scan.view', 'stats.tree.view'
  );

-- ---------------------------------------------------------------
-- Backfill existing admin accounts from the old ENUM, then drop it.
-- admin -> programmer, staff -> tree_admin, sales -> tree_admin (closest
-- fit until a dedicated Sales role is added later — see docs/rbac.md §12).
-- ---------------------------------------------------------------
UPDATE admins a
JOIN roles r ON r.role_key = CASE a.role
    WHEN 'admin' THEN 'programmer'
    WHEN 'staff' THEN 'tree_admin'
    WHEN 'sales' THEN 'tree_admin'
    ELSE 'tree_admin'
END
SET a.role_id = r.id;

ALTER TABLE admins
  MODIFY COLUMN role_id BIGINT UNSIGNED NOT NULL,
  DROP COLUMN role;
