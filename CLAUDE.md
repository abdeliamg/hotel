# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Hajj pilgrims management system built with PHP and SQLite. The application manages pilgrims (حجاج), groups (مجموعات), flights (رحلات الطيران), and hotel accommodations for Hajj pilgrimage operations. The interface is in Arabic (RTL layout) and uses Bootstrap 5 with DataTables for data management.

## Database Architecture

**Database**: SQLite (`hajj_data.db` in root directory)

**Core Tables**:
- `pilgrim` - Pilgrim records with national ID, name, group, barcode, passport, visa, app_id, flight assignments
- `group` - Group information including master_group (تكتل), hotels in Mecca/Medina, mutawwef, mina, arafa locations
- `flight` - Flight records with num, date, time, type (ذهاب/إياب), flight_id
- `hotel` - Hotel information
- `hotel_pilgrim` - Junction table linking pilgrims to hotel rooms via barcode

**Database Connection**: All pages use `includes/db.php` which creates a PDO connection to `hajj_data.db`

## Project Structure

```
/
├── hajj_data.db              # Main SQLite database
├── login.php                 # Authentication (admin/123456)
├── check.php                 # Auth middleware (cookie-based)
├── pages/                    # Main application pages
│   ├── pilgrims.php         # Pilgrim management (CRUD + CSV import)
│   ├── groups.php           # Group management (CRUD + CSV import)
│   ├── flights.php          # Flight management (CRUD + CSV import)
│   └── preview*.php         # Preview/reporting pages
├── includes/                 # Server-side processing
│   ├── db.php               # Database connection
│   ├── pilgrims_server.php  # DataTables server-side for pilgrims
│   ├── groups_server.php    # DataTables server-side for groups
│   ├── flights_server.php   # DataTables server-side for flights
│   ├── all_groups.php       # JSON endpoint for group dropdown
│   └── all_flights.php      # JSON endpoint for flight dropdown
├── hotel_pilgrim/           # Hotel assignment subsystem
│   ├── login.php            # Separate login (master_group cookie)
│   ├── hotel_pilgrim.php    # Hotel room assignment interface
│   ├── hotel_pilgrim_action.php # CRUD operations
│   ├── pilgrims_data.php    # Select2 AJAX endpoint
│   └── get_rooms.php        # Dynamic room loading
└── query/                   # Query/lookup subsystem + quiz module
    ├── index.php            # Quiz interface
    ├── query*.php           # Various passport lookup endpoints
    ├── hajj_data.db         # Separate database copy
    └── db.php               # Separate DB connection
```

## Authentication

**Main System**:
- Username: `admin`
- Password: `123456`
- Auth mechanism: SHA1 hash stored in `auth` cookie
- Middleware: `check.php` (included at top of protected pages)

**Hotel Pilgrim Subsystem**:
- Uses `master_group` cookie for group-based access
- Separate login at `/hotel_pilgrim/login.php`

## Common Development Tasks

### Running the Application

This is a PHP application that requires a web server:

```bash
# Using PHP built-in server
php -S localhost:8000

# Access at http://localhost:8000/login.php
```

### CSV Import Format

All CSV imports use **semicolon (;) as delimiter**:

**Groups CSV** (13 columns):
```
group;master_group;group_phone;mecca_hotel;mecca_location;medina_hotel;medina_location;mutawwef;mutawwef_location;mina;mina_location;arafa;arafa_location
```

**Pilgrims CSV** (10 columns):
```
national;name;group;barcode;phone;passport;visa;app_id;flight_id_out;flight_id_in
```

**Flights CSV** (5 columns):
```
num;date;time;type;flight_id
```

### Database Operations

**Accessing the database**:
```bash
sqlite3 hajj_data.db
```

**Common queries**:
```sql
-- View all pilgrims
SELECT * FROM pilgrim;

-- View groups with pilgrim counts
SELECT g.group, g.master_group, COUNT(p.id) as pilgrim_count 
FROM `group` g 
LEFT JOIN pilgrim p ON g.group = p.group 
GROUP BY g.group;

-- Check hotel assignments
SELECT * FROM hotel_pilgrim;
```

### Performance Optimization for Large Imports

The CSV import code uses SQLite-specific optimizations:
```php
$pdo->exec("PRAGMA synchronous = OFF");
$pdo->exec("PRAGMA journal_mode = MEMORY");
// ... batch inserts with transactions every 2000 rows
$pdo->exec("PRAGMA synchronous = NORMAL");
```

Keep these optimizations when modifying import functionality.

## Key Technical Patterns

### DataTables Server-Side Processing

All main tables use server-side processing with custom search behavior:
- Search triggers only on **Enter key press** (not on every keystroke)
- Implemented via `initComplete` callback that removes default `.DT` listeners
- Server-side scripts in `includes/*_server.php` handle pagination, search, sorting

### Select2 Integration

Dynamic dropdowns use Select2 with AJAX:
- Groups: `/includes/all_groups.php`
- Flights: `/includes/all_flights.php`
- Pilgrims (hotel assignment): `/hotel_pilgrim/pilgrims_data.php`

### Modal-Based CRUD

All CRUD operations use Bootstrap modals with jQuery AJAX:
- Add: Modal with empty form
- Edit: Modal pre-populated via DataTables row data
- Delete: SweetAlert2 confirmation dialog
- Forms submit via AJAX, reload table without page refresh

### RTL (Right-to-Left) Layout

All pages use `dir="rtl" lang="ar"` with:
- Bootstrap RTL CSS: `bootstrap.rtl.min.css`
- Arabic DataTables language file
- Select2 RTL configuration: `dir: "rtl"`

## Important Notes

- **No test suite exists** - manual testing required
- **No build process** - direct PHP execution
- **SQLite limitations**: Not suitable for high-concurrency production use
- **Security**: Hardcoded credentials in `login.php` and `check.php` - change for production
- **Two separate database files**: Root `hajj_data.db` and `query/hajj_data.db` may need sync
- **No input sanitization** on many endpoints - vulnerable to SQL injection if user input not properly handled
- **Session management**: Cookie-based, no server-side session storage

## Code Style Conventions

- PHP opening tags: `<?php` (full tag, not short tags)
- Database queries: Use PDO prepared statements
- JavaScript: jQuery-based, no modern framework
- CSS: Inline styles and Bootstrap classes, minimal custom CSS
- Arabic text: UTF-8 encoding required, use Arabic labels in forms
- File naming: snake_case for PHP files, lowercase

## Subsystems

### Query Module (`/query/`)

Separate quiz/questionnaire system with its own database and MVC-like structure. Includes multiple passport lookup endpoints for different verification scenarios (by group, service center, bank, etc.).

### Hotel Pilgrim Module (`/hotel_pilgrim/`)

Group-based hotel room assignment system with cascading dropdowns (hotel → floor → room) and barcode-based pilgrim selection.
