<?php
include('../check.php');
include("../includes/db.php");

/* دالّة جلب الحاج مع تحضير ثابت */
function getPilgrim(PDO $pdo, string $field, $value): ?array
{
    static $stmts = [];                          // تُنشأ مرّة واحدة وتُعاد استخدامها
    $field = filter_var($field, FILTER_SANITIZE_STRING); // Basic sanitization
    if (!in_array($field, ['app_id', 'id','barcode'])) { // Allow only specific fields
         return null;
    }
    if (!isset($stmts[$field])) {
        // Use backticks for column names if they might be reserved words or contain special chars
        // Although 'id' and 'app_id' are likely fine without them.
        $stmts[$field] = $pdo->prepare(
            "SELECT * FROM pilgrim WHERE `{$field}` = :val LIMIT 1"
        );
    }
    // Ensure $value is appropriate type if needed (e.g., int for 'id')
    $stmts[$field]->execute([':val' => $value]);
    return $stmts[$field]->fetch(PDO::FETCH_ASSOC) ?: null;
}


if(isset($_GET['group'])){
    $group = $_GET['group'];
$stmt = $pdo->prepare("SELECT * FROM `group` WHERE `group` = ?");
$stmt->execute([$group]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo json_encode($row);
die();
}


/* اختيار طريقة البحث */
$row = null;
if (!empty($_GET['app_id'])) {
    $row = getPilgrim($pdo, 'app_id', $_GET['app_id']);
} elseif (!empty($_GET['id'])) {
    // Assuming 'id' is numeric, might add validation/casting
    $row = getPilgrim($pdo, 'id', filter_var($_GET['id'], FILTER_SANITIZE_NUMBER_INT));
}elseif (!empty($_GET['barcode'])) {
    // Assuming 'id' is numeric, might add validation/casting
    $row = getPilgrim($pdo, 'barcode', filter_var($_GET['barcode']));
}

if (!$row) {
    // It's better to show an error page or a more structured message
    // than echoing directly before the HTML starts.
    // For simplicity here, we keep the echo but ideally handle this differently.
    echo "<!DOCTYPE html><html dir='rtl' lang='ar'><head><meta charset='UTF-8'><title>خطأ</title><link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css' rel='stylesheet'></head><body class='text-center p-5'><div class='alert alert-danger fs-4'>❌ لا يوجد حاج بالمُعرّف المطلوب لعرضه.</div></body></html>";
    exit;
}

// Clean phone number for WhatsApp link
$phoneClean = preg_replace('/[^0-9]/', '', $row['phone'] ?? '');
// Ensure it has a country code prefix if needed for wa.me (assuming Saudi Arabia +966)
// You might need a more robust way to determine the correct prefix
if ($phoneClean && !str_starts_with($phoneClean, '966')) { // Example prefix
    // $phoneClean = '966' . ltrim($phoneClean, '0'); // Adjust based on how numbers are stored
}

?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <title>تفاصيل الحاج - <?= htmlspecialchars($row['name'] ?? 'غير متوفر') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bs-primary-rgb: 13, 110, 253; /* Standard Bootstrap Blue */
            --bs-secondary-rgb: 108, 117, 125;
            --card-bg: #ffffff;
            --body-bg: #f2f4f6;
            --text-muted: #6c757d;
            --border-color: #dee2e6;
            --dashed-border-color: #e9ecef;
            --primary-accent: #0d6efd;
            --secondary-accent: #6610f2; /* A purple accent */
            --success-accent: #198754;
        }

        body {
            background-color: var(--body-bg);
            font-family: 'Tajawal', sans-serif;
            color: #333;
        }

        .profile-card {
            border-radius: 20px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            background-color: var(--card-bg);
            margin-top: 30px;
            margin-bottom: 30px;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .profile-header {
            background: linear-gradient(135deg, var(--primary-accent) 0%, #0a58ca 100%); /* Gradient background */
            color: #ffffff;
            text-align: center;
            padding: 40px 20px;
            position: relative;
        }
        .profile-header::before { /* Subtle pattern overlay */
           content: "";
           position: absolute; /* Keep pattern */
           top: 0; left: 0; width: 100%; height: 100%;
           background-image: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><path d="M12.5 0 L0 12.5 L12.5 25 L25 12.5 Z M37.5 0 L25 12.5 L37.5 25 L50 12.5 Z M62.5 0 L50 12.5 L62.5 25 L75 12.5 Z M87.5 0 L75 12.5 L87.5 25 L100 12.5 Z M0 37.5 L12.5 50 L0 62.5 L-12.5 50 Z M25 37.5 L37.5 50 L25 62.5 L12.5 50 Z M50 37.5 L62.5 50 L50 62.5 L37.5 50 Z M75 37.5 L87.5 50 L75 62.5 L62.5 50 Z M12.5 75 L0 87.5 L12.5 100 L25 87.5 Z M37.5 75 L25 87.5 L37.5 100 L50 87.5 Z M62.5 75 L50 87.5 L62.5 100 L75 87.5 Z M87.5 75 L75 87.5 L87.5 100 L100 87.5 Z" fill="%23FFF" fill-opacity="0.05"/></svg>');
           opacity: 0.3; /* Reduced pattern opacity */
           pointer-events: none;
        }


        .profile-name {
            font-size: 2rem; /* Increased size */
            font-weight: 700;
            margin-bottom: 10px;
            letter-spacing: -0.5px;
        }

        .profile-subtitle span, .profile-subtitle a {
            font-size: 1rem;
            padding: 6px 15px; /* Slightly larger padding */
            margin: 5px 4px; /* Slightly more horizontal margin */
            border-radius: 50px; /* Fully rounded pills */
            display: inline-flex; /* Align icon and text */
            align-items: center;
            gap: 5px; /* Space between icon and text */
            text-decoration: none;
            transition: background-color 0.2s ease, color 0.2s ease;
        }
        .profile-subtitle .badge-group {
             background-color: rgba(255, 255, 255, 0.2);
             color: #fff;
             border: 1px solid rgba(255, 255, 255, 0.4);
        }
         .profile-subtitle .badge-whatsapp {
             background-color: var(--success-accent);
             color: #fff;
         }
        .profile-subtitle .badge-whatsapp:hover {
             background-color: #146c43; /* Darker green on hover */
             color: #fff; /* Ensure text remains white */
             transform: translateY(-1px); /* Subtle lift */
         }


        .profile-body {
            padding: 30px; /* Increased padding */
        }

        .info-section-title {
             font-size: 1.3rem;
             font-weight: 600;
             color: var(--primary-accent);
             margin-bottom: 20px;
             padding-bottom: 8px;
             border-bottom: 2px solid var(--primary-accent);
             display: inline-block;
        }

        .info-line {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px; /* Increased margin */
            border-bottom: 1px dashed var(--dashed-border-color);
            padding-bottom: 15px; /* Increased padding */
            font-size: 1rem;
        }
         .info-line:last-child {
             margin-bottom: 0;
             border-bottom: none;
             padding-bottom: 0;
         }

        .info-label {
            /* flex: 0 0 35%; Removed fixed flex basis */
            font-weight: 500; /* Slightly less bold */
            color: var(--text-muted);
            text-align: right;
             margin-left: 15px; /* Space between label and value */
        }
        .info-label i { margin-left: 6px; color: var(--primary-accent); width: 18px; text-align: center; vertical-align: middle; } /* Icon styling */

        .info-value {
            /* flex: 1; Takes remaining space */
            color: #343a40; /* Darker text for value */
            text-align: left;
            font-weight: 500;
            word-break: break-word;
        }
         .info-value.highlight { /* Special highlight for certain values */
             color: var(--primary-accent);
             font-weight: 700;
         }

        .image-grid { margin-top: 25px; }

        .thumb-card {
            position: relative;
            border-radius: 12px;
            overflow: hidden;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out, border-color 0.2s ease-in-out;
             border: 1px solid var(--border-color);
        }
        .thumb-card:hover {
             transform: translateY(-5px);
             box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
             border-color: var(--primary-accent); /* Highlight border on hover */
        }

        .thumb-card img {
            display: block;
            width: 100%;
            height: 150px; /* Increased height */
            object-fit: cover;
        }

        .thumb-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(transparent, rgba(var(--bs-primary-rgb), 0.8)); /* Gradient overlay */
            color: #fff;
            font-size: 0.9rem;
            font-weight: 500;
            text-align: center;
            padding: 8px 5px; /* Adjusted padding */
            transition: background 0.2s ease;
        }
         .thumb-card:hover .thumb-overlay {
             background: linear-gradient(transparent, rgba(var(--bs-primary-rgb), 0.95));
         }
         .thumb-overlay i { margin-left: 5px; } /* Space for icon */

        .no-img-box {
            border: 2px dashed var(--dashed-border-color);
            border-radius: 12px;
            text-align: center;
            padding: 20px; /* Reduced padding */
            height: 100%; /* Match height of image card */
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            font-size: 0.9rem;
            background-color: #f8f9fa; /* Light background */
            min-height: 174px; /* = img height + overlay padding + border */
        }
         .no-img-box i {
             font-size: 1.8rem; /* Larger icon */
             margin-bottom: 8px;
             color: #adb5bd; /* Lighter icon color */
         }

        .action-buttons { margin-top: 30px; }
        .action-buttons .btn {
             padding: 10px 20px;
             font-size: 1rem;
             font-weight: 500;
             border-radius: 8px;
             display: flex;
             align-items: center;
             justify-content: center;
             gap: 8px; /* Space between icon and text in button */
             transition: all 0.2s ease;
        }
         .action-buttons .btn:hover {
             transform: translateY(-2px);
             box-shadow: 0 6px 12px rgba(0,0,0,0.15); /* Slightly stronger shadow */
         }

         .btn-group-details {
             background-color: var(--secondary-accent);
             border-color: var(--secondary-accent);
             color: #fff;
         }
         .btn-group-details:hover {
             background-color: #560bad;
             background-image: linear-gradient(to bottom, #6610f2, #560bad); /* Add gradient on hover */
             border-color: #500a9e;
              color: #fff;
         }
         .btn-flight-details {
             background-color: var(--primary-accent);
             border-color: var(--primary-accent);
             color: #fff;
         }
         .btn-flight-details:hover {
             background-color: #0b5ed7; /* Slightly darker blue */
             background-image: linear-gradient(to bottom, #0d6efd, #0b5ed7); /* Add gradient on hover */
             border-color: #0a58ca;
             color: #fff;
         }


        /* Modal Styling */
        .modal-dialog-centered { min-height: calc(100% - 3.5rem); }
        #imgModal .modal-content { background: transparent; border: none; box-shadow: none; }
        #imgModal .btn-close { filter: brightness(0) invert(1); opacity: 0.8; position: absolute; top: -30px; right: 5px; font-size: 1.2rem;}
        #imgModalSrc {
            max-width: 100%;
            max-height: 85vh; /* More vertical space */
            object-fit: contain;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        /* SweetAlert Customizations */
        .swal2-popup {
             border-radius: 15px !important; /* Ensure override */
             box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15) !important;
             font-family: 'Tajawal', sans-serif !important;
        }
        .swal2-title {
            font-weight: 700 !important;
            font-size: 1.6rem !important;
            color: #333 !important;
        }
        .swal2-html-container {
             text-align: right !important;
             margin: 20px 0 !important;
             line-height: 1.8 !important;
             font-size: 1rem !important;
        }
        .swal2-confirm {
             border-radius: 8px !important;
             padding: 10px 25px !important;
             font-size: 1rem !important;
             font-weight: 500 !important;
        }

        /* Custom classes for elements inside SweetAlert HTML */
        .swal-info-line {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0; /* Increased padding */
            border-bottom: 1px dashed var(--dashed-border-color);
            margin-bottom: 10px;
        }
        .swal-info-line:last-child { border-bottom: none; margin-bottom: 0; }
        .swal-info-label { font-weight: 600; color: #555; margin-left: 10px; }
        .swal-info-value { color: #333; display: flex; align-items: center; gap: 5px; }
        .swal-info-value .badge { font-size: 0.8rem; font-weight: 500; }

        .flight-block {
             border: 1px solid var(--border-color);
             border-radius: 10px;
             padding: 15px;
             margin-bottom: 15px;
             background-color: #fdfdfd; /* Slightly off-white */
             box-shadow: 0 2px 5px rgba(0,0,0,0.05);
             transition: box-shadow 0.2s ease;
        }
        .flight-block:hover {
             box-shadow: 0 4px 10px rgba(0,0,0,0.08);
        }

        .flight-title {
             font-size: 1.3rem;
             font-weight: 700;
             margin-bottom: 15px;
             display: flex;
             align-items: center;
             gap: 8px;
        }
         .flight-title-out { color: var(--primary-accent); }
         .flight-title-back { color: var(--secondary-accent); }

        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .profile-name { font-size: 1.8rem; }
            .profile-body { padding: 20px; }
            .info-line { flex-direction: column; align-items: flex-start; }
            .info-label { margin-bottom: 5px; margin-left: 0; text-align: right; }
            .info-value { text-align: right; width: 100%; }
            .image-grid .col-6 { margin-bottom: 15px; } /* Add space between image rows on small screens */
            .action-buttons .btn { width: 100%; } /* Full width buttons */
            .action-buttons .btn:first-child { margin-bottom: 10px; }
        }
        @media (max-width: 576px) {
             .profile-header { padding: 30px 15px; }
             .profile-name { font-size: 1.6rem; }
             .profile-subtitle span, .profile-subtitle a { font-size: 0.9rem; padding: 5px 12px; }
             .info-section-title { font-size: 1.15rem; }
             .thumb-card img { height: 120px; }
             .no-img-box { min-height: 144px; }
             .flight-title { font-size: 1.15rem;}
        }

    </style>
</head>
<body class="container">

    <div class="profile-card">
        <div class="profile-header">
            <div class="profile-name"><?= htmlspecialchars($row['name'] ?? 'اسم غير متوفر') ?></div>
            <div class="profile-subtitle" id="subtitle_det">
                <?php if (!empty($row['group'])): ?>
                <span class="badge badge-group badge-pill"><i class="bi bi-people-fill"></i> <?= htmlspecialchars($row['group']) ?></span>
                <?php endif; ?>
                <?php if ($phoneClean): ?>
                
                <?php endif; ?>
            </div>
        </div>

        <div class="profile-body">

            <h3 class="info-section-title"><i class="bi bi-person-badge"></i> المعلومات الأساسية</h3>
            <?php
            $basic_info = [
                "app_id" => "رقم الجواز"
            ];
            $basic_icons = [ // Icons for basic info
                "app_id" => "bi-upc-scan"
            ];
            foreach ($basic_info as $k => $lbl) {
                if (!empty($row[$k])) {
                    $icon_class = $basic_icons[$k] ?? 'bi-info-circle'; // Default icon
                    echo '<div class="info-line">
                            <span class="info-label"><i class="bi '.$icon_class.'"></i>' . $lbl . ' :</span>
                            <span class="info-value' . ($k === 'app_id' ? ' highlight' : '') . '">' . htmlspecialchars($row[$k]) . '</span>
                          </div><div id="g_data"></div>';
                }
            }
            ?>
<!--
             <h3 class="info-section-title mt-4"><i class="bi bi-file-earmark-text"></i> المستندات</h3>
            <div class="row g-3 image-grid">
                <?php
                foreach (['passport' => 'جواز السفر', 'visa' => 'الفيزا'] as $k => $lbl):
                    $imgPath = $row[$k] ?? null;
                    $hasImg = !empty($imgPath) && filter_var($imgPath, FILTER_VALIDATE_URL); // Basic URL check
                ?>
                <div class="col-md-6 col-12">
                    <?php if ($hasImg): ?>
                        <div class="thumb-card view-img" data-src="<?= htmlspecialchars($imgPath) ?>" data-title="<?= htmlspecialchars($lbl) ?>">
                            <img src="<?= htmlspecialchars($imgPath) ?>" alt="<?= htmlspecialchars($lbl) ?>"
                                 onerror="this.onerror=null; this.src='https://media.istockphoto.com/id/1179991026/tr/vekt%C3%B6r/404-y%C4%B1rt%C4%B1lm%C4%B1%C5%9F-sayfal%C4%B1-kitap-veya-not-defteri.jpg?s=612x612&w=0&k=20&c=zZ7WdZlX25rAOwCwd7wOM4GH2V_y2PMh2PmaSg3QbMg='; this.closest('.thumb-card').classList.add('load-error'); this.parentNode.querySelector('.thumb-overlay').innerHTML = '<i class=&quot;bi bi-exclamation-triangle-fill&quot;></i> خطأ في تحميل الصورة';">
                            <div class="thumb-overlay"><i class="bi bi-image"></i> <?= htmlspecialchars($lbl) ?></div>
                        </div>
                    <?php else: ?>
                        <div class="no-img-box">
                             <i class="bi bi-image-alt"></i>
                             <div><?= htmlspecialchars($lbl) ?></div>
                             <div>(لا يوجد صورة مرفوعة)</div>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
                    -->
            <div class="action-buttons row mt-4 pt-3">
                
                <div class="col-md-6">
                    <button class="btn btn-flight-details w-100" onclick="showFlights()">
                        <i class="bi bi-airplane-fill"></i> عرض تفاصيل الرحلات
                    </button>
                 </div>
                 <div class="col-md-6">
                    <a href="https://visa.mofa.gov.sa/visaservices/searchvisa" class="btn btn-group-details w-100">
                        <i class="bi bi-airplane-fill"></i> اضغط للحصول على الفيزا
                    </a>
                 </div>
            </div>

        </div> </div> <div class="modal fade" id="imgModal" tabindex="-1" aria-labelledby="imgModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                 <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                 <img id="imgModalSrc" src="" class="img-fluid" alt="صورة مكبرة">
                 </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        /* --- Image Lightbox --- */
        $(document).on('click', '.view-img', function() {
            const imgSrc = $(this).data('src');
            // const imgTitle = $(this).data('title'); // Optional: get title
            if(imgSrc) {
                $('#imgModalSrc').attr('src', imgSrc);
                // $('#imgModalLabel').text(imgTitle || ''); // Optional: set title
                new bootstrap.Modal('#imgModal').show();
            }
        });

        /* --- Helper to create Location Link Badge --- */
        function createLocationLink(url, text = 'الموقع') {
             if (!url) return '';
             // Basic validation for URL
             try {
                 new URL(url); // Checks if it's a valid URL structure
                 return `<a href="${url}" target="_blank" class="badge rounded-pill bg-primary-subtle text-primary-emphasis ms-2" style="font-size:0.85rem; text-decoration:none;">
                             <i class="bi bi-geo-alt-fill"></i> ${text}
                         </a>`;
             } catch (_) {
                 return '<span class="badge rounded-pill bg-light text-muted ms-2"><i class="bi bi-geo-alt"></i> رابط غير صالح</span>'; // Indicate invalid link
             }
        }

        /* --- Display Group Details --- */
        function showGroup() {
            // Use template literals for cleaner variable injection
            fetch(`preview.php?group=<?= urlencode($row['group'] ?? '') ?>`)
                .then(response => {
                    if (!response.ok) {
                         throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                 })
                .then(d => {
                    if (!d || typeof d !== 'object') {
                        Swal.fire({
                            icon: 'info',
                            title: 'لا توجد بيانات',
                            text: 'لم يتم العثور على تفاصيل للمجموعة المطلوبة.',
                            confirmButtonText: 'إغلاق'
                        });
                        return;
                    }

                    // Clean group phone for WhatsApp link
                    let groupPhoneClean = '';
                    if (d.group_phone) {
                        groupPhoneClean = d.group_phone.replace(/[^0-9]/g, '');
                        // Add prefix logic if needed, similar to pilgrim's phone
                         // if (groupPhoneClean && !groupPhoneClean.startsWith('966')) {
                         //    groupPhoneClean = '966' + groupPhoneClean.replace(/^0+/, '');
                         // }
                    }

                    let html = `
                        <div class="text-center mb-3">
                            <span class="fs-5 fw-bold text-primary">${d.master_group || 'الفوج الرئيسي'}</span>
                            ${groupPhoneClean ? `
                                <a href="https://wa.me/${groupPhoneClean}" target="_blank"
                                   class="badge rounded-pill bg-success-subtle text-success-emphasis ms-2"
                                   style="font-size:0.9rem; text-decoration:none;">
                                   <i class="bi bi-whatsapp"></i> تواصل مع رئيس المجموعة
                                </a>` : ''
                            }
                        </div>
                        <hr class="my-3">`;

                    const pairs = [
                        { valueKey: "mecca_hotel", locationKey: "mecca_location", label: "فندق مكة" },
                        { valueKey: "medina_hotel", locationKey: "medina_location", label: "فندق المدينة" },
                        { valueKey: "mutawwef", locationKey: "mutawwef_location", label: "المطوف" },
                        { valueKey: "mina", locationKey: "mina_location", label: "مخيم منى" },
                        { valueKey: "arafa", locationKey: "arafa_location", label: "مخيم عرفات" }
                    ];

                    let detailsHtml = '';
                    pairs.forEach(pair => {
                        const val = d[pair.valueKey] || '';
                        const loc = d[pair.locationKey] || '';
                        if (val || loc) {
                            detailsHtml += `
                                <div class="swal-info-line">
                                    <span class="swal-info-label">${pair.label}:</span>
                                    <span class="swal-info-value">
                                       ${val ? val : ''}
                                       ${createLocationLink(loc)}
                                    </span>
                                </div>`;
                        }
                    });

                     html += detailsHtml || '<div class="text-center text-muted mt-3">لا توجد تفاصيل إضافية متاحة حالياً.</div>';


                    Swal.fire({
                        title: `تفاصيل: ${<?= htmlspecialchars($row['group'] || ''); ?>}`,
                        html: html,
                        width: '90%',
                        customClass: { popup: 'rounded-4 shadow' },
                        confirmButtonText: 'إغلاق',
                        confirmButtonColor: '#0d6efd' // Match primary button color
                    });
                })
                .catch(error => {
                     console.error('Error fetching group info:', error);
                     Swal.fire({
                         icon: 'error',
                         title: 'خطأ في الاتصال',
                         text: 'لا يمكن تحميل بيانات المجموعة حالياً. يرجى المحاولة مرة أخرى.',
                         confirmButtonText: 'إغلاق'
                     });
                });
        }


        /* --- Display Flight Details --- */
        function showFlights() {
            const outFlightId = "<?= htmlspecialchars($row['flight_id_out'] ?? '') ?>";
            const inFlightId = "<?= htmlspecialchars($row['flight_id_in'] ?? '') ?>";

             // Only fetch if at least one ID exists
            if (!outFlightId && !inFlightId) {
                 Swal.fire({
                     icon: 'info',
                     title: 'لا توجد رحلات',
                     text: 'لم يتم تسجيل رحلات ذهاب أو عودة لهذا الحاج.',
                     confirmButtonText: 'إغلاق'
                 });
                 return;
            }

            fetch(`../includes/flight_info.php?out=${outFlightId}&back=${inFlightId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                     // Check content type before parsing as JSON
                    const contentType = response.headers.get("content-type");
                    if (contentType && contentType.indexOf("application/json") !== -1) {
                         return response.json();
                    } else {
                         throw new Error("Received non-JSON response from server");
                    }
                })
                .then(data => {
                    if (!data || (typeof data === 'object' && !data.out && !data.back)) {
                         Swal.fire({
                             icon: 'info',
                             title: 'لا توجد تفاصيل',
                             text: 'لم يتم العثور على تفاصيل للرحلات المسجلة.',
                             confirmButtonText: 'إغلاق'
                         });
                         return;
                     }

                    let html = '';
                    if (data.out) {
                        html += `<h5 class="flight-title flight-title-out"><i class="bi bi-airplane-fill"></i> رحلة الذهاب</h5>`;
                        html += flightBlock(data.out, 'ذهاب');
                    }
                    if (data.back) {
                         html += `<h5 class="flight-title flight-title-back mt-4"><i class="bi bi-airplane-engines-fill"></i> رحلة العودة</h5>`;
                        html += flightBlock(data.back, 'إياب');
                    }

                    if (!html) { // Double check if somehow both were null after fetch
                        html = '<div class="text-center text-muted">لم يتم العثور على تفاصيل الرحلات.</div>';
                    }


                    Swal.fire({
                        title: 'تفاصيل الرحلات',
                        html: `<div style="max-height: 60vh; overflow-y: auto; padding-right: 10px;">${html}</div>`, // Add scroll for long content
                        width: '90%',
                        customClass: { popup: 'rounded-4 shadow' },
                        confirmButtonText: 'إغلاق',
                        confirmButtonColor: '#0d6efd' // Match primary button color
                    });
                })
                 .catch(error => {
                    console.error('Error fetching flight info:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'خطأ في الاتصال',
                        text: 'لا يمكن تحميل بيانات الرحلات حالياً. يرجى المحاولة مرة أخرى.',
                        confirmButtonText: 'إغلاق'
                    });
                });
        }

        /* --- Generate HTML for a Single Flight Block --- */
        function flightBlock(flight, type) {
            if (!flight || typeof flight !== 'object') return "";

            const iconClass = type === 'ذهاب'
                ? 'bi-arrow-up-circle text-primary'
                : 'bi-arrow-down-circle text-info'; // Use text-info for return

            // Helper to format date/time if available
            const formatDate = (dateStr) => {
                 if (!dateStr) return 'غير محدد';
                 try {
                     // Attempt to format nicely, fallback to original string
                     return new Date(dateStr).toLocaleDateString('ar-SA', { year: 'numeric', month: 'long', day: 'numeric' });
                 } catch {
                     return dateStr;
                 }
            };
            const formatTime = (timeStr) => {
                 if (!timeStr) return ''; // Often time is combined with date or not needed separately
                 // Simple return, maybe add formatting if needed 'hh:mm A'
                 return timeStr;
            };


            return `
            <div class="flight-block">
                 <div class="d-flex justify-content-between align-items-start">
                     <div class="flex-fill text-end me-3">
                         <div class="mb-2 fs-5 fw-bold text-dark">
                            <i class="bi bi-airplane me-1"></i> ${flight.num || 'غير محدد'}
                         </div>
                          <div class="mb-1 text-muted">
                             <i class="bi bi-calendar-event me-1"></i> ${formatDate(flight.date)}
                             ${flight.time ? `<span class="ms-2"><i class="bi bi-clock me-1"></i> ${formatTime(flight.time)}</span>` : ''}
                         </div>
                          <div class="text-muted small">
                            <i class="bi bi-fingerprint me-1"></i> ID: ${flight.flight_id || 'N/A'}
                            ${flight.type ? `<span class="ms-2 badge bg-light text-dark border">${flight.type}</span>` : ''}
                          </div>
                      </div>
                      <div class="text-center">
                          <i class="bi ${iconClass} fs-2"></i>
                          <div class="fw-bold small mt-1">${type}</div>
                     </div>
                 </div>
            </div>`;
        }

        document.addEventListener('DOMContentLoaded',()=>{
            fetch(`preview.php?group=<?= urlencode($row['group'] ?? '') ?>`)
                .then(response => {
                    if (!response.ok) {
                         throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                 })
                .then(d => {
                    if (!d || typeof d !== 'object') {
                        Swal.fire({
                            icon: 'info',
                            title: 'لا توجد بيانات',
                            text: 'لم يتم العثور على تفاصيل للمجموعة المطلوبة.',
                            confirmButtonText: 'إغلاق'
                        });
                        return;
                    }

                    // Clean group phone for WhatsApp link
                    let groupPhoneClean = '';
                    if (d.group_phone) {
                        groupPhoneClean = d.group_phone.replace(/[^0-9]/g, '');
                        // Add prefix logic if needed, similar to pilgrim's phone
                         // if (groupPhoneClean && !groupPhoneClean.startsWith('966')) {
                         //    groupPhoneClean = '966' + groupPhoneClean.replace(/^0+/, '');
                         // }
                    }

                    if(groupPhoneClean){
                        setTimeout(function(){
                        document.getElementById('subtitle_det').innerHTML += `<a href="tel:+${groupPhoneClean}" target="_blank" class="badge badge-pill badge-group ms-2" style="font-size:0.9rem; text-decoration:none;"><i class="bi bi-telephone-fill"></i> اتصال مع رئيس المجموعة</a>`
                    },100)
                    }
                    let html = `
                        <div class="text-center mb-3">
                            <span class="fs-5 fw-bold text-primary">${d.master_group || 'الفوج الرئيسي'}</span>
                            ${groupPhoneClean ? `
                                <a href="https://wa.me/${groupPhoneClean}" target="_blank"
                                   class="badge rounded-pill bg-success-subtle text-success-emphasis ms-2"
                                   style="font-size:0.9rem; text-decoration:none;">
                                   <i class="bi bi-whatsapp"></i> تواصل مع رئيس المجموعة
                                </a>` : ''
                            }
                        </div>
                        <hr class="my-3">`;

                    const pairs = [
                        { valueKey: "mecca_hotel", locationKey: "mecca_location", label: "فندق مكة" },
                        { valueKey: "medina_hotel", locationKey: "medina_location", label: "فندق المدينة" },
                        { valueKey: "mutawwef", locationKey: "mutawwef_location", label: "المطوف" },
                        { valueKey: "mina", locationKey: "mina_location", label: "مخيم منى" },
                        { valueKey: "arafa", locationKey: "arafa_location", label: "مخيم عرفات" }
                    ];

                    let detailsHtml = '';
                    pairs.forEach(pair => {
                        const val = d[pair.valueKey] || '';
                        const loc = d[pair.locationKey] || '';
                        if (val || loc) {
                            detailsHtml += `
                                <div class="swal-info-line">
                                    <span class="swal-info-label">${pair.label}:</span>
                                    <span class="swal-info-value">
                                       ${val ? val : ''}
                                       ${createLocationLink(loc)}
                                    </span>
                                </div>`;
                        }
                    });

                     html += detailsHtml || '<div class="text-center text-muted mt-3">لا توجد تفاصيل إضافية متاحة حالياً.</div>';

                    document.getElementById('g_data').innerHTML += `<h3 class="info-section-title"><i class="bi bi-person-badge"></i> معلومات المجموعة</h3>`+html;
                    
                })
                .catch(error => {
                     console.error('Error fetching group info:', error);
                     Swal.fire({
                         icon: 'error',
                         title: 'خطأ في الاتصال',
                         text: 'لا يمكن تحميل بيانات المجموعة حالياً. يرجى المحاولة مرة أخرى.',
                         confirmButtonText: 'إغلاق'
                     });
                });
        })
    </script>

</body>
</html>