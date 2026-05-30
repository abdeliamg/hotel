<?php
// Helpers controlling the "all rooms" access mode for a master_group.
//
// When a master_group has the `all_rooms` flag enabled (set from
// /pages/groups.php), it may assign pilgrims to ANY room of a hotel it holds
// at least one reservation for in `res` — not only the rooms reserved under its
// own group_name. These helpers are shared by get_floors.php, get_rooms.php and
// hotel_pilgrim_action.php.

if (!function_exists('group_has_all_rooms')) {
    /**
     * True when any group row belonging to this master_group has all_rooms = 1.
     */
    function group_has_all_rooms(PDO $pdo, string $masterGroup): bool
    {
        if ($masterGroup === '') {
            return false;
        }
        try {
            $stmt = $pdo->prepare('SELECT MAX(all_rooms) FROM "group" WHERE master_group = :mg');
            $stmt->execute([':mg' => $masterGroup]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            // Column may not exist yet on very old databases — treat as disabled.
            return false;
        }
    }

    /**
     * True when the master_group holds at least one reservation in the hotel.
     */
    function group_assigned_to_hotel(PDO $pdo, string $masterGroup, string $hotelName): bool
    {
        if ($masterGroup === '' || $hotelName === '') {
            return false;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM res WHERE hotel_name = :h AND group_name = :g");
        $stmt->execute([':h' => $hotelName, ':g' => $masterGroup]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * True when the group should see/use every room of the given hotel:
     * the all_rooms flag is on AND the group is reserved in that hotel.
     */
    function group_can_use_all_rooms(PDO $pdo, string $masterGroup, string $hotelName): bool
    {
        return group_has_all_rooms($pdo, $masterGroup)
            && group_assigned_to_hotel($pdo, $masterGroup, $hotelName);
    }
}
