<?php
require_once __DIR__ . '/check.php';
require_once __DIR__ . '/includes/root_nav.php';
// update_group_phone.php

// Open SQLite connection
$db = new SQLite3('hajj_data.db');

// Fetch unique groups for the select2 dropdown
//$groups_result = $db->query('SELECT DISTINCT "group" FROM "group" ORDER BY "group" ASC');
// ------------------------------------------------------------------
// FETCH groups with their phone numbers
// ------------------------------------------------------------------
$groups = [];          // array of ['group' => ..., 'phone' => ...]
$groupNames = [];      // simple array of group names for validation

$res = $db->query(
    'SELECT "group", COALESCE(group_phone,"") AS phone
     FROM "group"
     ORDER BY "group" ASC'
);

while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $groups[]     = $row;
    $groupNames[] = $row['group'];
}


// Handle form submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_group = $_POST['group'] ?? '';
    $phone = $_POST['group_phone'] ?? '';

    // Basic backend validation (frontend does the main validation)
    if (in_array($selected_group, $groupNames, true)      // <— use $groupNames
    && preg_match('/^\\+966\\d{8,9}$/', $phone)
    && strlen($phone) <= 13
) {
        // Update group_phone for the selected group
        $stmt = $db->prepare('UPDATE "group" SET group_phone = :phone WHERE "group" = :group');
        $stmt->bindValue(':phone', $phone, SQLITE3_TEXT);
        $stmt->bindValue(':group', $selected_group, SQLITE3_TEXT);
        $res = $stmt->execute();
        if ($res) {
            $message = 'تم تحديث رقم التواصل بنجاح.';
        } else {
            $message = 'حدث خطأ أثناء التحديث.';
        }
    } else {
        $message = 'بيانات غير صحيحة، الرجاء التحقق من المدخلات.';
    }
}

?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>تحديد أرقام المجموعات</title>

<!-- Include Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
    :root {
    --primary:       #005ba1;
    --primary-dark:  #003e72;
    --success-bg:    #e8f5e9;
    --success-fg:    #1b5e20;
    --error-bg:      #ffebee;
    --error-fg:      #b00020;
    --radius:        12px;
    --shadow:        0 6px 18px rgba(0,0,0,.1);
}

* {
    box-sizing: border-box;
    margin: 0;
}

body {
    font-family: "Tajawal", sans-serif;
    background: #f3f6fb;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    padding: 1rem;
}

.card {
    background: #fff;
    width: 100%;
    max-width: 420px;
    padding: 2rem 1.5rem;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
}

h1 {
    font-size: 1.5rem;
    color: var(--primary);
    text-align: center;
    margin-bottom: 1.5rem;
}

label {
    font-weight: 500;
    margin: 0.75rem 0 0.25rem;
    display: block;
}

input[type="text"],
.select2-selection--single {
    width: 100% !important;
    padding: 0.7rem 1rem !important;
    font-size: 1rem !important;
    border: 2px solid #d0d7e2 !important;
    border-radius: var(--radius) !important;
    transition: border-color 0.25s;
}

input[type="text"]:focus,
.select2-container--focus .select2-selection--single {
    border-color: var(--primary) !important;
    outline: none;
}

button {
    margin-top: 1.75rem;
    width: 100%;
    padding: 0.75rem 1rem;
    font-size: 1.1rem;
    font-weight: 600;
    color: #fff;
    background: var(--primary);
    border: none;
    border-radius: var(--radius);
    cursor: pointer;
    transition: background 0.25s;
}

button:disabled {
    background: #a5b4c4;
    cursor: not-allowed;
}

button:not(:disabled):hover {
    background: var(--primary-dark);
}

.alert {
    margin-top: 1rem;
    padding: 0.65rem 1rem;
    border-radius: var(--radius);
    font-weight: 500;
}

.alert.success {
    background: var(--success-bg);
    color: var(--success-fg);
}

.alert.error {
    background: var(--error-bg);
    color: var(--error-fg);
}
/* ——— Select2 height & alignment fix ——— */
.select2-container--default .select2-selection--single {
    min-height: 48px;          /* equal to the input’s visual height */
    padding: 0.7rem 1rem;      /* match the text-input padding */
    border: 2px solid #d0d7e2; /* keep the same border style */
    border-radius: var(--radius);
    display: flex;             /* let us vertically-center the text */
    align-items: center;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    flex: 1;                   /* take remaining width */
    line-height: 1.3;          /* reset default huge line-height */
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 100%;              /* arrow fills the selection height */
    right: 0.75rem;            /* nudge arrow inwards a bit */
}
/* ── Select2: correct text alignment & arrow for RTL ───────────────────── */
.select2-container--default[dir="rtl"] .select2-selection--single {
    direction: rtl;                /* make the whole selection RTL-aware   */
    padding-right: 1rem !important;/* space for the text                   */
    padding-left: 2.2rem !important;/* extra room because arrow is left    */
}

.select2-container--default[dir="rtl"] .select2-selection--single
        .select2-selection__rendered {
    text-align: right;             /* start text from the right edge       */
    line-height: 1.6;              /* same visual height as the input      */
    overflow: hidden;              /* prevent long labels spilling out     */
    white-space: nowrap;
    text-overflow: ellipsis;       /* add … if the label is too long       */
}

.select2-container--default[dir="rtl"] .select2-selection--single
        .select2-selection__arrow {
    right: auto;                   /* move arrow away from the text        */
    left: 0.6rem;                  /* arrow now sits on the far-left side  */
}
/* ── Fix: shrink the “clear” (✕) icon so it doesn’t cover the whole field ── */
.select2-container--default[dir="rtl"] .select2-selection--single
        .select2-selection__clear {
    position: absolute;       /* keep it inside the selection */
    left: 0.6rem;             /* sit next to the arrow on the far-left */
    right: auto;
    top: 50%;
    transform: translateY(-50%);
    width: 1.2em;             /* just big enough for the ✕ */
    height: 1.2em;
    line-height: 1.2em;
    padding: 0;
    cursor: pointer;
    background: transparent;  /* no unwanted background */
    z-index: 2;               /* stay above text but not block it */
}

</style>
</head>
<body>
<div class="container-fluid pt-2">
    <?php render_root_navbar('phones'); ?>
</div>
<div class="container">
    <h1>تحديد أرقام المجموعات</h1>

    <form id="phoneForm" method="POST" novalidate>
        <label for="groupSelect">المجموعة</label>
        <select name="group" id="groupSelect" required>
            <option value="" disabled selected>اختر المجموعة</option>
            <?php foreach ($groups as $g): ?>
    <option
        value="<?= htmlspecialchars($g['group']) ?>"
        data-phone="<?= htmlspecialchars($g['phone']) ?>"
    >
        <?= htmlspecialchars($g['group']) ?>
    </option>
<?php endforeach; ?>
        </select>

        <label for="phoneInput" style="margin-top:20px;">رقم تواصل المجموعة (سعودي حصرا مع الرمز الدولي +966)</label>
        <input dir="ltr"
            type="text" 
            id="phoneInput" 
            name="group_phone" 
            placeholder="+966xxxxxxxxx" 
            maxlength="13" 
            autocomplete="off" 
            required 
            pattern="^\+966\d{8,9}$" 
            title="يجب أن يبدأ الرقم بـ +966 ويليه 8 أو 9 أرقام بدون مسافات" 
        />

        <button type="submit" id="submitBtn" disabled>تحديث الرقم</button>
    </form>

    <?php if ($message): ?>
        <div class="message <?= strpos($message, 'خطأ') !== false ? 'error' : '' ?>">
            <?=htmlspecialchars($message)?>
        </div>
    <?php endif; ?>
</div>

<!-- jQuery & Select2 -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize select2 with RTL
    $('#groupSelect').select2({
        placeholder: "اختر المجموعة",
        dir: "rtl",
        width: '100%',
        allowClear: true,
        language: {
            noResults: function() {
                return "لا توجد نتائج";
            }
        }
    });

    // Validation function for phone input
    function validatePhone(phone) {
        const regex = /^\+966\d{8,9}$/;
        return regex.test(phone);
    }

    // Enable submit button only if both inputs valid
    function toggleSubmit() {
        const groupSelected = $('#groupSelect').val() !== null && $('#groupSelect').val() !== '';
        const phoneVal = $('#phoneInput').val();
        const phoneValid = validatePhone(phoneVal);

        $('#submitBtn').prop('disabled', !(groupSelected && phoneValid));
    }

    // Validate phone live on input
    $('#phoneInput').on('input', function() {
        let val = $(this).val();

        $(this).val(val);

        toggleSubmit();
    });

    // Enable submit when group changes
    // ——— Populate phone field when a group is chosen ———
    $('#groupSelect').on('change', function () {
        const phone = $(this).find('option:selected').data('phone') || '';
        $('#phoneInput').val(phone);   // set exactly what’s in the database
        toggleSubmit();                // re-evaluate submit button
    });

    // Initial disable submit
    toggleSubmit();

    // Prevent invalid form submission
    $('#phoneForm').on('submit', function(e) {
        const phoneVal = $('#phoneInput').val();
        const groupSelected = $('#groupSelect').val();

        if (!groupSelected) {
            alert('الرجاء اختيار المجموعة.');
            e.preventDefault();
            return false;
        }
        if (!validatePhone(phoneVal)) {
            alert('رقم التواصل غير صحيح. يجب أن يبدأ بـ +966 ويليه 8 أو 9 أرقام بدون مسافات.');
            e.preventDefault();
            return false;
        }
        return true;
    });
});
</script>
</body>
</html>
