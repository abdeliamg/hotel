<?php
if (!function_exists('render_hotel_pilgrim_navbar')) {
    function render_hotel_pilgrim_navbar(string $active = '', string $masterGroup = ''): void
    {
        $items = [
            'group' => ['label' => 'إسكان المجموعة', 'href' => '/hotel_pilgrim/hotel_pilgrim.php'],
            'all' => ['label' => 'كل الإسكان', 'href' => '/hotel_pilgrim/hotel_all_pilgrim.php'],
        ];
        ?>
        <style>
            .hp-nav { background: #1f2937; border-radius: 10px; margin: 14px 0; padding: 8px 12px; }
            .hp-nav .nav-link { color: #e5e7eb; border-radius: 8px; }
            .hp-nav .nav-link.active { background: #4f46e5; color: #fff; }
            .hp-nav .nav-link:hover { color: #fff; background: rgba(148, 163, 184, 0.2); }
            .hp-nav .hp-group { color: #cbd5e1; font-size: 13px; padding: 6px 10px; }
        </style>
        <nav class="hp-nav">
            <ul class="nav align-items-center">
                <?php foreach ($items as $key => $item): ?>
                    <li class="nav-item">
                        <a class="nav-link<?= $active === $key ? ' active' : '' ?>" href="<?= htmlspecialchars($item['href']) ?>">
                            <?= htmlspecialchars($item['label']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
                <li class="nav-item ms-auto hp-group">المجموعة: <?= htmlspecialchars($masterGroup !== '' ? $masterGroup : '-') ?></li>
                <li class="nav-item"><a class="nav-link" href="/hotel_pilgrim/login.php">تبديل مستخدم المجموعة</a></li>
            </ul>
        </nav>
        <?php
    }
}
?>
