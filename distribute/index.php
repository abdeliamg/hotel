<?php
require_once __DIR__ . '/../check.php';
require_once __DIR__ . '/../includes/root_nav.php';

// Only admins may edit the shared fallback rules; everyone else sees them
// read-only. Mirror the same role check used by save_fallback_rules.php.
$__current_user = $GLOBALS['current_user'] ?? null;
$canEditFallbackRules = $__current_user
    && role_meets_requirement($__current_user['role'] ?? '', 'admin');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>توزيع غرف الفندق</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">

    <style>
        :root {
            --bg: #f1f5f9;
            --surface: #ffffff;
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --accent: #0f172a;
            --success: #16a34a;
            --warning: #f59e0b;
            --danger: #dc2626;
            --muted: #64748b;
            --border: #e2e8f0;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: var(--bg);
            color: #0f172a;
            margin: 0;
            padding: 0 0 40px;
        }

        .page-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
            color: #fff;
            padding: 22px 0;
            margin-bottom: 24px;
            box-shadow: 0 6px 20px rgba(15, 23, 42, 0.18);
        }

        .page-header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-header h1 i { color: #fbbf24; font-size: 28px; }

        .page-header .header-link {
            color: #e2e8f0;
            text-decoration: none;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 8px;
            transition: background 0.2s;
        }

        .page-header .header-link:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #fff;
        }

        .card-surface {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
        }

        .input-card { padding: 18px; height: 100%; }

        .input-card .card-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--accent);
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
        }

        .input-card .card-title i { color: var(--primary); }

        .input-card .hint {
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 10px;
        }

        .input-card textarea {
            width: 100%;
            height: 200px;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px;
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.6;
            resize: vertical;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .input-card textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .input-card .counter {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 8px;
            font-size: 12px;
            color: var(--muted);
        }

        .input-card .counter .badge { font-size: 12px; }

        .action-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            padding: 14px 18px;
            margin: 18px 0;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            position: sticky;
            top: 0;
            z-index: 50;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
        }

        .action-bar .btn { padding: 10px 18px; font-weight: 600; }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
        }

        .stat-card .icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: #fff;
            flex-shrink: 0;
        }

        .stat-card .icon.assigned { background: linear-gradient(135deg, #16a34a, #22c55e); }
        .stat-card .icon.unassigned { background: linear-gradient(135deg, #dc2626, #ef4444); }
        .stat-card .icon.groups-ok { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .stat-card .icon.groups-bad { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .stat-card .icon.bed-waste { background: linear-gradient(135deg, #7c3aed, #a78bfa); }
        .stat-card .icon.fragmentation { background: linear-gradient(135deg, #0891b2, #22d3ee); }

        .stat-card .label { font-size: 13px; color: var(--muted); margin-bottom: 2px; }
        .stat-card .value { font-size: 22px; font-weight: 700; color: var(--accent); }

        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--accent);
            margin: 0 0 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title i { color: var(--primary); }

        .results-panel { padding: 18px; margin-bottom: 18px; }

        .result-card {
            border: 1px solid var(--border);
            border-right: 4px solid var(--success);
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 10px;
            background: #fdfefe;
            transition: box-shadow 0.2s;
        }

        .result-card:hover { box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06); }
        .result-card.partial { border-right-color: var(--warning); background: #fffbeb; }
        .result-card.failed { border-right-color: var(--danger); background: #fef2f2; }

        .master-group-chip {
            display: inline-block;
            background: #ede9fe;
            color: #5b21b6;
            border: 1px solid #ddd6fe;
            border-radius: 999px;
            padding: 1px 8px;
            font-size: 11px;
            margin-top: 4px;
        }

        .master-group-header {
            display: flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            color: #fff;
            border-radius: 10px;
            padding: 8px 14px;
            margin: 14px 0 8px;
            font-size: 14px;
            font-weight: 600;
        }

        .master-group-header:first-child { margin-top: 0; }

        .master-group-header.neutral {
            background: linear-gradient(135deg, #475569 0%, #64748b 100%);
        }

        .master-group-header .mg-count {
            background: rgba(255, 255, 255, 0.18);
            border-radius: 999px;
            padding: 1px 8px;
            font-size: 12px;
            margin-inline-start: auto;
        }

        .result-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dashed var(--border);
            font-size: 12px;
            color: var(--muted);
        }

        .result-meta i { color: var(--primary); }

        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 8px;
        }

        .result-header h6 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            color: var(--accent);
        }

        .result-header small { font-size: 12px; color: var(--muted); }

        .floor-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            padding: 6px 0;
            border-top: 1px dashed var(--border);
        }

        .floor-row:first-child { border-top: none; }

        .floor-badge {
            background: #eff6ff;
            color: var(--primary-dark);
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 3px 10px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .room-count {
            font-size: 12px;
            color: var(--muted);
            white-space: nowrap;
        }

        .room-list {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            flex: 1;
        }

        .room-code {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 2px 8px;
            font-size: 12px;
            color: var(--accent);
            font-family: 'Consolas', monospace;
        }

        .missing-row {
            margin-top: 8px;
            padding: 6px 10px;
            background: #fef3c7;
            color: #92400e;
            border-radius: 8px;
            font-size: 13px;
        }

        .missing-row.failed {
            background: #fee2e2;
            color: #991b1b;
        }

        .settings-panel { padding: 14px 18px; margin: 18px 0 0; }

        .settings-header {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 700;
            color: var(--accent);
            margin-bottom: 10px;
        }

        .settings-header i.bi-sliders { color: var(--primary); }

        .settings-header .toggle-settings {
            margin-inline-start: auto;
            border: none;
            background: transparent;
            color: var(--muted);
            cursor: pointer;
            padding: 2px 8px;
            font-size: 14px;
        }

        .settings-body { transition: max-height 0.3s ease; }
        .settings-body.collapsed { display: none; }

        .settings-body label.form-label {
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 4px;
        }

        .settings-body .form-check-label { font-size: 13px; }

        .fallback-rules {
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px dashed var(--border);
        }

        .fallback-rules .fr-head {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            cursor: pointer;
            user-select: none;
        }

        .fallback-rules .fr-head h6 {
            margin: 0;
            font-size: 13px;
            font-weight: 700;
            color: var(--accent);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .fallback-rules .fr-head h6 i { color: var(--primary); }

        .fallback-rules .fr-toggle {
            margin-inline-start: auto;
            border: none;
            background: transparent;
            color: var(--muted);
            cursor: pointer;
            padding: 2px 8px;
            font-size: 14px;
            border-radius: 6px;
            transition: background 0.15s;
        }

        .fallback-rules .fr-toggle:hover { background: #f1f5f9; }

        .fallback-rules.collapsed .fr-body { display: none; }
        .fallback-rules.collapsed { margin-bottom: -4px; }

        .fallback-rules.collapsed .fr-toggle i {
            transform: rotate(0deg);
        }

        .fallback-rules:not(.collapsed) .fr-toggle i {
            transform: rotate(180deg);
        }

        .fallback-rules .fr-toggle i { transition: transform 0.2s; }

        /* Read-only mode for non-admin users: keep rules visible but block
           interaction with every input/button rendered by the script. */
        .fallback-rules.fr-readonly .fr-list,
        .fallback-rules.fr-readonly .fr-save-bar button {
            pointer-events: none;
            user-select: none;
        }
        .fallback-rules.fr-readonly .fr-list {
            opacity: 0.85;
            filter: grayscale(0.15);
        }
        .fallback-rules.fr-readonly .fr-list input,
        .fallback-rules.fr-readonly .fr-list select,
        .fallback-rules.fr-readonly .fr-list button,
        .fallback-rules.fr-readonly .fr-list textarea {
            background-color: #f8fafc !important;
            color: #475569 !important;
            cursor: not-allowed !important;
        }

        .fr-subhead {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .fr-subhead .fr-hint {
            font-size: 12px;
            color: var(--muted);
            flex: 1 1 100%;
        }

        .fr-subhead .fr-actions {
            margin-inline-start: auto;
            display: flex;
            gap: 6px;
        }

        .fr-subhead .fr-actions .btn { font-size: 12px; padding: 4px 10px; }

        .fr-save-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px dashed var(--border);
        }

        .fr-save-bar .btn { font-size: 13px; padding: 6px 14px; margin-inline-start: auto; }

        .fr-status {
            font-size: 12px;
            color: var(--muted);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .fr-status.dirty { color: var(--warning); font-weight: 600; }
        .fr-status.saved { color: var(--success); font-weight: 600; }
        .fr-status.saving { color: var(--primary); }
        .fr-status.error { color: var(--danger); font-weight: 600; }
        .fr-status.loading { color: var(--muted); }

        .fr-list { display: flex; flex-direction: column; gap: 10px; }

        .fr-rule {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .fr-rule-head {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .fr-rule-head label {
            font-size: 12px;
            color: var(--muted);
            margin: 0;
            white-space: nowrap;
            font-weight: 600;
        }

        .fr-rule-head .fr-rule-arrow {
            color: var(--muted);
            font-size: 13px;
            margin-inline-start: 4px;
        }

        .fr-rule-head .fr-rule-tag {
            font-size: 12px;
            color: var(--primary-dark);
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 999px;
            padding: 2px 10px;
            font-weight: 600;
        }

        .fr-rule-head .fr-del-rule {
            margin-inline-start: auto;
            background: transparent;
            border: 1px solid #fecaca;
            color: var(--danger);
            border-radius: 6px;
            padding: 3px 10px;
            font-size: 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: background 0.15s;
        }

        .fr-rule-head .fr-del-rule:hover { background: #fee2e2; }

        .fr-bundles {
            display: flex;
            flex-direction: column;
            gap: 6px;
            padding-inline-start: 12px;
            border-inline-start: 2px solid #e2e8f0;
        }

        .fr-bundles-empty {
            font-size: 12px;
            color: var(--muted);
            padding: 4px 8px;
            font-style: italic;
        }

        .fr-bundle {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 5px 8px;
        }

        .fr-bundle label {
            font-size: 12px;
            color: var(--muted);
            margin: 0;
            white-space: nowrap;
        }

        .fr-bundle .fr-times {
            color: var(--muted);
            font-weight: 700;
        }

        .fr-bundle .fr-del-bundle {
            margin-inline-start: auto;
            background: transparent;
            border: 1px solid #fecaca;
            color: var(--danger);
            border-radius: 6px;
            padding: 2px 8px;
            font-size: 11px;
            cursor: pointer;
            transition: background 0.15s;
        }

        .fr-bundle .fr-del-bundle:hover { background: #fee2e2; }

        .fr-rule input[type="number"] {
            width: 64px;
            padding: 3px 6px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 13px;
            text-align: center;
        }

        .fr-rule .fr-add-bundle {
            align-self: flex-start;
            background: transparent;
            border: 1px dashed var(--primary);
            color: var(--primary-dark);
            border-radius: 8px;
            padding: 4px 12px;
            font-size: 12px;
            cursor: pointer;
            transition: background 0.15s;
            margin-inline-start: 12px;
        }

        .fr-rule .fr-add-bundle:hover { background: #eff6ff; }

        .fr-empty {
            text-align: center;
            color: var(--muted);
            font-size: 12px;
            padding: 12px;
            background: #f8fafc;
            border: 1px dashed var(--border);
            border-radius: 8px;
        }

        .results-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 18px;
        }

        @media (max-width: 992px) {
            .results-grid { grid-template-columns: 1fr; }
        }

        .table-panel { padding: 18px; }
        .table-panel + .table-panel { margin-top: 18px; }

        .table-panel table { margin: 0; }
        .table-panel th { background: #f8fafc; font-size: 13px; }
        .table-panel td { font-size: 14px; vertical-align: middle; }

        .hidden { display: none !important; }

        .empty-state {
            text-align: center;
            padding: 30px 10px;
            color: var(--muted);
            font-size: 14px;
        }

        .empty-state i { font-size: 36px; display: block; margin-bottom: 8px; opacity: 0.5; }

        #toastContainer {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .rooms-stats-fab {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2563eb, #1e3a8a);
            color: #fff;
            border: none;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.25);
            cursor: pointer;
            z-index: 200;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            transition: transform 0.2s, box-shadow 0.2s, background 0.2s;
        }

        .rooms-stats-fab:hover {
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.3);
        }

        .rooms-stats-fab.active {
            background: linear-gradient(135deg, #16a34a, #15803d);
        }

        .rooms-stats-fab .fab-badge {
            position: absolute;
            top: -4px;
            right: -6px;
            background: #f59e0b;
            color: #fff;
            border-radius: 12px;
            padding: 1px 7px;
            font-size: 11px;
            font-weight: 700;
            min-width: 22px;
            text-align: center;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.18);
        }

        .rooms-stats-fab .fab-badge:empty { display: none; }

        .rooms-stats-panel {
            position: fixed;
            bottom: 96px;
            right: 24px;
            width: 440px;
            max-width: calc(100vw - 48px);
            max-height: calc(100vh - 140px);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.22);
            z-index: 199;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: stats-pop 0.22s ease-out;
        }

        @keyframes stats-pop {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .rooms-stats-header {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 16px;
            background: linear-gradient(135deg, #0f172a, #1e3a8a);
            color: #fff;
        }

        .rooms-stats-header h5 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
        }

        .rooms-stats-header h5 i { color: #fbbf24; }

        .btn-close-stats {
            border: none;
            background: rgba(255, 255, 255, 0.14);
            color: #fff;
            width: 28px;
            height: 28px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.15s;
        }

        .btn-close-stats:hover { background: rgba(255, 255, 255, 0.28); }

        .rooms-stats-body {
            overflow-y: auto;
            padding: 14px 16px;
        }

        .stats-section + .stats-section { margin-top: 18px; }

        .stats-section-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--accent);
            margin: 0 0 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .stats-section-title i { color: var(--primary); }

        .stats-table { font-size: 13px; margin: 0; width: 100%; }
        .stats-table th { background: #f8fafc; font-weight: 700; font-size: 12px; padding: 6px 8px; }
        .stats-table td { vertical-align: middle; padding: 6px 8px; }
        .stats-table .total-row { background: #f1f5f9; font-weight: 700; }
        .stats-table .total-row td { border-top: 2px solid var(--border); }

        .type-pill {
            display: inline-block;
            background: #eff6ff;
            color: var(--primary-dark);
            border: 1px solid #bfdbfe;
            border-radius: 999px;
            padding: 2px 10px;
            font-size: 12px;
            font-weight: 600;
        }

        .cell-count {
            display: inline-block;
            min-width: 26px;
            background: #ecfdf5;
            color: #047857;
            border-radius: 6px;
            padding: 1px 6px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
        }

        .floor-pill {
            display: inline-block;
            background: #f1f5f9;
            color: var(--accent);
            border-radius: 6px;
            padding: 1px 8px;
            font-size: 12px;
            font-weight: 700;
        }

        @media (max-width: 576px) {
            .rooms-stats-panel {
                right: 12px;
                left: 12px;
                width: auto;
                bottom: 88px;
            }
            .rooms-stats-fab { right: 16px; bottom: 16px; }
        }
    </style>
</head>
<body>

<div class="container-fluid pt-2">
    <?php render_root_navbar('distribute'); ?>
</div>



<div class="container">

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card-surface input-card">
                <div class="card-title"><i class="bi bi-door-closed"></i> بيانات الغرف</div>
                <div class="hint">صيغة كل سطر: <code>رقم الغرفة</code> &lt;Tab&gt; <code>نوع الغرفة</code></div>
                <div class="mb-2">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btnOpenAutoFillRooms">
                        <i class="bi bi-magic"></i> تعبئة تلقائية من الغرف المتاحة
                    </button>
                </div>
                <textarea id="roomsInput" placeholder="مثال:&#10;101&#9;2&#10;102&#9;2&#10;201&#9;3"></textarea>
                <div class="counter">
                    <span>الأسطر المكتشفة:</span>
                    <span class="badge bg-primary" id="roomsCount">0</span>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card-surface input-card">
                <div class="card-title"><i class="bi bi-people-fill"></i> بيانات المجموعات</div>
                <div class="hint">صيغة كل سطر: <code>اسم المجموعة</code> &lt;Tab&gt; <code>نوع الغرفة</code> &lt;Tab&gt; <code>عدد الغرف</code> <span class="text-muted">(اختياري: &lt;Tab&gt; <code>التكتل</code>)</span></div>
                <textarea id="groupsInput" placeholder="مثال:&#10;المجموعة أ&#9;2&#9;3&#10;المجموعة ب&#9;3&#9;2"></textarea>
                <div class="counter">
                    <span>الأسطر المكتشفة:</span>
                    <span class="badge bg-primary" id="groupsCount">0</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card-surface settings-panel">
        <div class="settings-header">
            <i class="bi bi-sliders"></i> إعدادات التوزيع
            <button type="button" class="toggle-settings" id="btnToggleSettings" aria-label="تبديل">
                <i class="bi bi-chevron-up"></i>
            </button>
        </div>
        <div class="settings-body" id="settingsBody">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="setGroupOrder">ترتيب المجموعات</label>
                    <select class="form-select form-select-sm" id="setGroupOrder">
                        <option value="mostConstrained">الأقل توفرًا أولًا</option>
                        <option value="largestDemand">الأكثر طلبًا أولًا</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="setSingleFloorPref">تفضيل الطابق الواحد</label>
                    <select class="form-select form-select-sm" id="setSingleFloorPref">
                        <option value="tightestFit">أضيق ملاءمة</option>
                        <option value="lowestAccessible">الطابق الأدنى</option>
                        <option value="masterGroupCohesive">تجميع التكتل</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="setMultiFloorPref">تفضيل عدة الطوابق</label>
                    <select class="form-select form-select-sm" id="setMultiFloorPref">
                        <option value="adjacent">طوابق متجاورة</option>
                        <option value="largestFirst">الأكثر غرفًا أولًا</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="setNoSplit">
                        <label class="form-check-label" for="setNoSplit">عدم تقسيم المجموعة بين الطوابق</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="setAllowUpgrade">
                        <label class="form-check-label" for="setAllowUpgrade">السماح بترقية النوع (إسناد غرف أكبر عند نفاد النوع المطلوب)</label>
                    </div>
                </div>
            </div>

            <?php if (!$canEditFallbackRules): ?>
            <script>
            // Non-admin lockdown: the obfuscated/external script re-renders the
            // rules list whenever fallback rules change. Disable every input and
            // button inside the list each time the DOM updates, so users cannot
            // tab into a field and edit it with the keyboard.
            document.addEventListener('DOMContentLoaded', function () {
                const list = document.getElementById('fallbackRulesList');
                if (!list) return;
                function lockNodes(root) {
                    root.querySelectorAll('input, select, textarea, button').forEach(function (el) {
                        el.disabled = true;
                        el.setAttribute('tabindex', '-1');
                        if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
                            el.readOnly = true;
                        }
                    });
                }
                lockNodes(list);
                new MutationObserver(function () { lockNodes(list); })
                    .observe(list, { childList: true, subtree: true });
            });
            </script>
            <?php endif; ?>
            <div class="fallback-rules collapsed<?= $canEditFallbackRules ? '' : ' fr-readonly' ?>" id="fallbackRules" data-can-edit="<?= $canEditFallbackRules ? '1' : '0' ?>">
                <div class="fr-head">
                    <h6><i class="bi bi-arrow-left-right"></i> قواعد البدائل بين أنواع الغرف<?= $canEditFallbackRules ? '' : ' <span class="badge bg-secondary fw-normal ms-2">للقراءة فقط</span>' ?></h6>
                    <button type="button" class="fr-toggle" id="btnToggleFallbackRules" aria-label="تبديل">
                        <i class="bi bi-chevron-down"></i>
                    </button>
                </div>
                <div class="fr-body" id="fallbackRulesBody">
                    <div class="fr-subhead">
                        <?php if ($canEditFallbackRules): ?>
                        <div class="fr-actions">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="btnAddFallbackRule">
                                <i class="bi bi-plus-lg"></i> إضافة قاعدة
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnResetFallbackRules">
                                <i class="bi bi-arrow-counterclockwise"></i> الافتراضي
                            </button>
                        </div>
                        <?php endif; ?>
                        <div class="fr-hint">
                            حدّد لكل نوع غرفة "بدائل" يمكن استخدامها لتغطية وحدة طلب واحدة عند نفاد النوع المطلوب. كل بديل عبارة عن (عدد) × (نوع).
                            مثلًا: لتغطية وحدة من نوع 4، يمكن استخدام بديل <code>2 × نوع 2</code> (غرفتان من النوع 2).
                            يتطلب تفعيل خيار "السماح بترقية النوع" أعلاه. القواعد محفوظة على الخادم ومشتركة بين جميع المستخدمين.
                            <?php if (!$canEditFallbackRules): ?>
                            <div class="text-muted mt-1"><i class="bi bi-info-circle"></i> صلاحيات تعديل هذه القواعد متاحة للمدير فقط.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="fr-list" id="fallbackRulesList"></div>
                    <div class="fr-save-bar">
                        <span class="fr-status" id="fallbackRulesStatus">
                            <i class="bi bi-cloud-arrow-down"></i> جارٍ تحميل القواعد من الخادم...
                        </span>
                        <?php if ($canEditFallbackRules): ?>
                        <button type="button" class="btn btn-success btn-sm" id="btnSaveFallbackRules" disabled>
                            <i class="bi bi-cloud-arrow-up"></i> حفظ على الخادم
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="action-bar">
        <button class="btn btn-primary" id="btnDistribute">
            <i class="bi bi-play-fill"></i> توزيع الغرف
        </button>
        <button class="btn btn-success" id="btnExport" disabled>
            <i class="bi bi-file-earmark-excel"></i> تصدير Excel
        </button>
        <button class="btn btn-outline-secondary" id="btnSample">
            <i class="bi bi-file-earmark-text"></i> تحميل عينة
        </button>
        <button class="btn btn-outline-danger ms-auto" id="btnClear">
            <i class="bi bi-trash"></i> مسح
        </button>
    </div>

    <div id="resultsSection" class="hidden">

        <div class="stat-grid">
            <div class="stat-card">
                <div class="icon assigned"><i class="bi bi-check2-circle"></i></div>
                <div>
                    <div class="label">غرف مخصصة</div>
                    <div class="value" id="statAssignedRooms">0</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon unassigned"><i class="bi bi-x-circle"></i></div>
                <div>
                    <div class="label">غرف غير مخصصة</div>
                    <div class="value" id="statUnassignedRooms">0</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon groups-ok"><i class="bi bi-check2-all"></i></div>
                <div>
                    <div class="label">مجموعات مكتملة</div>
                    <div class="value" id="statGroupsOk">0</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon groups-bad"><i class="bi bi-exclamation-triangle"></i></div>
                <div>
                    <div class="label">مجموعات ناقصة</div>
                    <div class="value" id="statGroupsBad">0</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon bed-waste"><i class="bi bi-exclamation-circle"></i></div>
                <div>
                    <div class="label">هدر أسرّة (ترقية النوع)</div>
                    <div class="value" id="statBedWaste">0</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon fragmentation"><i class="bi bi-arrows-vertical"></i></div>
                <div>
                    <div class="label">تشتت الطوابق</div>
                    <div class="value" id="statFragmentation">0</div>
                </div>
            </div>
        </div>

        <div class="results-grid">
            <div class="card-surface results-panel">
                <h6 class="section-title"><i class="bi bi-list-check"></i> نتائج التوزيع</h6>
                <div id="resultsList"></div>
            </div>

            <div>
                <div class="card-surface table-panel">
                    <h6 class="section-title"><i class="bi bi-door-open"></i> الغرف غير المخصصة</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>رقم الغرفة</th>
                                    <th>النوع</th>
                                    <th>الطابق</th>
                                </tr>
                            </thead>
                            <tbody id="unassignedRoomsTbody"></tbody>
                        </table>
                    </div>
                </div>

                <div class="card-surface table-panel">
                    <h6 class="section-title"><i class="bi bi-exclamation-triangle"></i> المجموعات الناقصة</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>المجموعة</th>
                                    <th>النوع</th>
                                    <th>المتبقي</th>
                                </tr>
                            </thead>
                            <tbody id="unassignedGroupsTbody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>

</div>

<div id="toastContainer"></div>

<div class="rooms-stats-panel hidden" id="roomsStatsPanel" role="dialog" aria-label="إحصائيات الغرف">
    <div class="rooms-stats-header">
        <h5><i class="bi bi-clipboard-data"></i> إحصائيات الغرف</h5>
        <button type="button" class="btn-close-stats" id="btnCloseRoomsStats" aria-label="إغلاق">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <div class="rooms-stats-body" id="roomsStatsBody"></div>
</div>

<button type="button" class="rooms-stats-fab" id="btnToggleRoomsStats" title="إحصائيات الغرف" aria-label="إحصائيات الغرف">
    <i class="bi bi-bar-chart-line"></i>
    <span class="fab-badge" id="fabCount"></span>
</button>

<!-- Export rooms modal -->
<div class="modal fade" id="exportRoomsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #0f172a 0%, #16a34a 100%); color: #fff;">
                <h5 class="modal-title"><i class="bi bi-download"></i> تصدير الغرف</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    اختر الفندق ثم تاريخ البداية لاستخراج الطابق وتاريخي البداية والنهاية من قاعدة البيانات لكل غرفة.
                    سيتم استخراج التكتل (master_group) تلقائيًا من جدول المجموعات.
                </p>

                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label fw-bold" for="exportHotel">
                            <i class="bi bi-building"></i> الفندق
                        </label>
                        <select class="form-select" id="exportHotel">
                            <option value="">اختر فندقًا</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-bold" for="exportDateFrom">
                            <i class="bi bi-calendar-event"></i> تاريخ البداية
                        </label>
                        <select class="form-select" id="exportDateFrom" disabled>
                            <option value="">اختر الفندق أولًا</option>
                        </select>
                    </div>
                </div>

                <div class="mt-3 d-flex align-items-center gap-2">
                    <span id="exportRoomsStatus" class="text-muted small"></span>
                </div>

                <hr>

                <div class="table-responsive" style="max-height: 320px;">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light" style="position: sticky; top: 0;">
                            <tr>
                                <th>#</th>
                                <th>رقم الغرفة</th>
                                <th>الطابق</th>
                                <th>من</th>
                                <th>إلى</th>
                            </tr>
                        </thead>
                        <tbody id="exportRoomsPreviewBody">
                            <tr><td colspan="5" class="text-center text-muted py-4">
                                اختر الفندق وتاريخ البداية لمعاينة الغرف المرتبطة
                            </td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary me-auto" id="btnExportSkip">
                    <i class="bi bi-skip-forward"></i> تصدير بدون بيانات الفندق
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> إلغاء
                </button>
                <button type="button" class="btn btn-success" id="btnExportConfirm" disabled>
                    <i class="bi bi-download"></i> تصدير
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Auto-fill rooms modal -->
<div class="modal fade" id="autoFillRoomsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #0f172a 0%, #2563eb 100%); color: #fff;">
                <h5 class="modal-title"><i class="bi bi-magic"></i> تعبئة تلقائية من الغرف المتاحة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label fw-bold" for="autoFillHotel">
                            <i class="bi bi-building"></i> الفندق
                        </label>
                        <select class="form-select" id="autoFillHotel">
                            <option value="">اختر فندقًا</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-bold" for="autoFillDateFrom">
                            <i class="bi bi-calendar-event"></i> تاريخ البداية
                        </label>
                        <input type="date" class="form-control" id="autoFillDateFrom">
                    </div>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="button" class="btn btn-primary" id="btnAutoFillValidate">
                        <i class="bi bi-search"></i> تحقق
                    </button>
                    <button type="button" class="btn btn-success" id="btnAutoFillApply" disabled>
                        <i class="bi bi-check2-circle"></i> تعبئة الحقل
                    </button>
                    <span id="autoFillStatus" class="text-muted align-self-center small"></span>
                </div>

                <hr>

                <div class="table-responsive" style="max-height: 360px;">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light" style="position: sticky; top: 0;">
                            <tr>
                                <th>#</th>
                                <th>رقم الغرفة</th>
                                <th>النوع</th>
                                <th>الطابق</th>
                                <th>من</th>
                                <th>إلى</th>
                            </tr>
                        </thead>
                        <tbody id="autoFillPreviewBody">
                            <tr><td colspan="6" class="text-center text-muted py-4">
                                اختر الفندق وتاريخ البداية ثم اضغط <strong>تحقق</strong> لمعاينة الغرف
                            </td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> إغلاق
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="script.js"></script>
</body>
</html>
