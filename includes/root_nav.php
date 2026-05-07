<?php
if (!function_exists('render_root_navbar')) {
    function render_root_navbar(string $active = ''): void
    {
        $items = [
            'home' => ['label' => 'الرئيسية', 'href' => '/med_hotels.php'],
            'hotels' => ['label' => 'الفنادق', 'href' => '/hotel.php'],
            'rooms' => ['label' => 'الغرف', 'href' => '/room.php'],
            'reservations' => ['label' => 'الحجوزات', 'href' => '/res.php'],
            'reports' => ['label' => 'التقارير', 'href' => '/hotel_room.php'],
            'phones' => ['label' => 'أرقام المجموعات', 'href' => '/update_group_phone.php'],
        ];
        ?>
        <style>
            .root-navbar {
                background: #0f172a;
                padding: 10px 14px;
                margin-bottom: 18px;
                border-radius: 12px;
            }
            .root-navbar .nav-link {
                color: #e2e8f0;
                border-radius: 8px;
                padding: 6px 12px;
            }
            .root-navbar .nav-link.active {
                background: #2563eb;
                color: #fff;
            }
            .root-navbar .nav-link:hover {
                background: rgba(148, 163, 184, 0.2);
                color: #fff;
            }
        </style>
        <nav class="root-navbar">
            <ul class="nav flex-wrap gap-1 align-items-center">
                <?php foreach ($items as $key => $item): ?>
                    <li class="nav-item">
                        <a class="nav-link<?= $active === $key ? ' active' : '' ?>" href="<?= htmlspecialchars($item['href']) ?>">
                            <?= htmlspecialchars($item['label']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
                <li class="nav-item ms-auto">
                    <a class="nav-link" href="/logout.php">تسجيل الخروج</a>
                </li>
            </ul>
        </nav>
        <?php
    }
}
?>
