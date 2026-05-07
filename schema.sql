CREATE TABLE flight (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    num TEXT,
    date TEXT,
    time TEXT,
    type TEXT,
    flight_id TEXT UNIQUE
)

CREATE TABLE "group" (
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
    arafa_location TEXT
)

CREATE TABLE hotel (id INTEGER PRIMARY KEY ASC AUTOINCREMENT, hotel_name TEXT UNIQUE, address TEXT, note TEXT)

CREATE TABLE hotel_pilgrim (id INTEGER PRIMARY KEY AUTOINCREMENT, hotel_name TEXT REFERENCES hotel (hotel_name), floor INTEGER REFERENCES res (floor), room_num INTEGER REFERENCES res (room_num), barcode TEXT REFERENCES pilgrim (barcode), group_name TEXT, note TEXT)

CREATE TABLE hotels (group_n TEXT PRIMARY KEY, hotel TEXT)

CREATE TABLE pilgrim (id INTEGER PRIMARY KEY AUTOINCREMENT, national TEXT, name TEXT, "group" TEXT, master_group TEXT, barcode TEXT, phone TEXT, passport TEXT, visa TEXT, app_id TEXT, flight_id_out TEXT, flight_id_in TEXT, type TEXT DEFAULT ÍÇÌ, office TEXT)

CREATE TABLE "res" (id INTEGER PRIMARY KEY AUTOINCREMENT, hotel_name TEXT , floor , room_num INTEGER , group_name TEXT , start_date TEXT, end_date TEXT, note TEXT)

CREATE TABLE "room" (id INTEGER PRIMARY KEY AUTOINCREMENT, hotel_name TEXT , floor INTEGER, room_num INTEGER, room_type INTEGER, note TEXT, date_from DATE NULL, date_to DATE NULL, contract TEXT NULL)

CREATE TABLE sqlite_sequence(name,seq)

