-- ============================================
-- SQLite Database Schema for Hajj Management System
-- Database: hajj_data.db
-- Generated: 2026-04-26
-- ============================================

-- ============================================
-- Table: flight
-- Stores flight information (ذهاب/إياب)
-- ============================================
CREATE TABLE IF NOT EXISTS flight (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    num TEXT,
    date TEXT,
    time TEXT,
    type TEXT,
    flight_id TEXT UNIQUE
);

-- ============================================
-- Table: group
-- Stores group information including hotels and locations
-- ============================================
CREATE TABLE IF NOT EXISTS "group" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    "group" TEXT,
    master_group TEXT,
    group_phone TEXT,
    mecca_hotel TEXT,
    mecca_location TEXT,
    medina_hotel TEXT,
    medina_location TEXT,
    mutawwef TEXT,
    mutawwef_location TEXT,
    mina TEXT,
    mina_location TEXT,
    arafa TEXT,
    arafa_location TEXT,
    -- When 1, every group sharing this master_group may assign pilgrims to ANY
    -- room of a hotel it is reserved in (added via migration 2026_05_30).
    all_rooms INTEGER NOT NULL DEFAULT 0
);

-- ============================================
-- Table: hotel
-- Stores hotel master data
-- ============================================
CREATE TABLE IF NOT EXISTS hotel (
    id INTEGER PRIMARY KEY ASC AUTOINCREMENT,
    hotel_name TEXT UNIQUE,
    address TEXT,
    note TEXT
);

-- ============================================
-- Table: hotel_pilgrim
-- Junction table linking pilgrims to hotel rooms
-- ============================================
CREATE TABLE IF NOT EXISTS hotel_pilgrim (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hotel_name TEXT REFERENCES hotel (hotel_name),
    floor INTEGER REFERENCES res (floor),
    room_num INTEGER REFERENCES res (room_num),
    barcode TEXT REFERENCES pilgrim (barcode),
    group_name TEXT,
    note TEXT
);

-- ============================================
-- Table: hotels
-- Simple group to hotel mapping
-- ============================================
CREATE TABLE IF NOT EXISTS hotels (
    group_n TEXT PRIMARY KEY,
    hotel TEXT
);

-- ============================================
-- Table: pilgrim
-- Stores pilgrim (حاج) information
-- Note: type field has default value with encoding issue
-- ============================================
CREATE TABLE IF NOT EXISTS pilgrim (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    national TEXT,
    name TEXT,
    "group" TEXT,
    master_group TEXT,
    barcode TEXT,
    phone TEXT,
    passport TEXT,
    visa TEXT,
    app_id TEXT,
    flight_id_out TEXT,
    flight_id_in TEXT,
    type TEXT DEFAULT '؟',
    office TEXT
);

-- ============================================
-- Table: res (reservations)
-- Stores room reservations by group
-- ============================================
CREATE TABLE IF NOT EXISTS "res" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hotel_name TEXT,
    floor INTEGER,
    room_num INTEGER,
    group_name TEXT,
    start_date TEXT,
    end_date TEXT,
    note TEXT
);

-- ============================================
-- Table: room
-- Stores room inventory and details
-- ============================================
CREATE TABLE IF NOT EXISTS "room" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hotel_name TEXT,
    floor INTEGER,
    room_num INTEGER,
    room_type INTEGER,
    note TEXT,
    date_from DATE NULL,
    date_to DATE NULL,
    contract TEXT NULL
);

-- ============================================
-- RECOMMENDED INDEXES FOR PERFORMANCE
-- ============================================

-- Pilgrim indexes (most frequently queried table)
CREATE INDEX IF NOT EXISTS idx_pilgrim_barcode ON pilgrim(barcode);
CREATE INDEX IF NOT EXISTS idx_pilgrim_passport ON pilgrim(passport);
CREATE INDEX IF NOT EXISTS idx_pilgrim_group ON pilgrim("group");
CREATE INDEX IF NOT EXISTS idx_pilgrim_master_group ON pilgrim(master_group);
CREATE INDEX IF NOT EXISTS idx_pilgrim_national ON pilgrim(national);

-- Group indexes
CREATE INDEX IF NOT EXISTS idx_group_name ON "group"("group");
CREATE INDEX IF NOT EXISTS idx_group_master ON "group"(master_group);

-- Flight indexes
CREATE INDEX IF NOT EXISTS idx_flight_type ON flight(type);
CREATE INDEX IF NOT EXISTS idx_flight_date ON flight(date);

-- Hotel pilgrim indexes (for room assignment queries)
CREATE INDEX IF NOT EXISTS idx_hotel_pilgrim_barcode ON hotel_pilgrim(barcode);
CREATE INDEX IF NOT EXISTS idx_hotel_pilgrim_hotel ON hotel_pilgrim(hotel_name);
CREATE INDEX IF NOT EXISTS idx_hotel_pilgrim_group ON hotel_pilgrim(group_name);
CREATE INDEX IF NOT EXISTS idx_hotel_pilgrim_room ON hotel_pilgrim(hotel_name, floor, room_num);

-- Room indexes
CREATE INDEX IF NOT EXISTS idx_room_hotel ON room(hotel_name);
CREATE INDEX IF NOT EXISTS idx_room_hotel_floor ON room(hotel_name, floor);
CREATE INDEX IF NOT EXISTS idx_room_hotel_floor_num ON room(hotel_name, floor, room_num);

-- Res (reservation) indexes
CREATE INDEX IF NOT EXISTS idx_res_hotel ON res(hotel_name);
CREATE INDEX IF NOT EXISTS idx_res_group ON res(group_name);
CREATE INDEX IF NOT EXISTS idx_res_hotel_floor_room ON res(hotel_name, floor, room_num);

-- ============================================
-- NOTES AND ISSUES
-- ============================================

-- 1. ENCODING ISSUE: pilgrim.type default value shows as '���' (corrupted UTF-8)
--    Should be '؟' (Arabic question mark)
--    Fix: ALTER TABLE pilgrim ALTER COLUMN type SET DEFAULT '؟';

-- 2. MISSING FOREIGN KEY CONSTRAINTS: References exist but not enforced
--    SQLite requires PRAGMA foreign_keys = ON; to enable FK enforcement

-- 3. DATA TYPE INCONSISTENCIES:
--    - res.floor has no type specified (should be INTEGER)
--    - Dates stored as TEXT instead of proper DATE/DATETIME types

-- 4. DUPLICATE TABLES: 'hotel' and 'hotels' serve similar purposes
--    Consider consolidating or clarifying their distinct roles

-- 5. MISSING UNIQUE CONSTRAINTS:
--    - pilgrim.barcode should likely be UNIQUE
--    - pilgrim.passport should likely be UNIQUE
--    - pilgrim.national should likely be UNIQUE

-- ============================================
-- SUGGESTED IMPROVEMENTS
-- ============================================

-- Enable foreign key enforcement (run at connection time):
-- PRAGMA foreign_keys = ON;

-- Add unique constraints (requires migration):
-- CREATE UNIQUE INDEX idx_pilgrim_barcode_unique ON pilgrim(barcode);
-- CREATE UNIQUE INDEX idx_pilgrim_passport_unique ON pilgrim(passport);
-- CREATE UNIQUE INDEX idx_pilgrim_national_unique ON pilgrim(national);
