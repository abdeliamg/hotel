<?php
require_once __DIR__ . '/../check.php';
/****************************************************************
 * filemanage9.php – مدير ملفات بسيط مع ترقيم صفحات على الخادم   *
 ****************************************************************/

$DIR        = __DIR__;              // المجلد المُدار (نفس المجلد)
$SELF       = basename(__FILE__);   // اسم هذا السكربت
$LIMIT      = 10;                   // عدد الملفات في كل صفحة
$MAX_SIZE_B = 1 * 1024 * 1024;      // 1 ميجابايت بالبايت
$skipped    = [];                   // الملفات المرفوضة (>1 م.ب) في آخر رفع

/* ---------- 1. حذف عبر AJAX ---------- */
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    $target = basename($_POST['file'] ?? '');
    $path   = "$DIR/$target";
    if ($target && is_file($path) && $target !== $SELF) {
        echo unlink($path) ? 'OK' : 'FAIL';
    } else {
        http_response_code(400);
        echo 'FAIL';
    }
    exit;
}

/* ---------- 2. الرفع ---------- */
if (isset($_POST['action']) && $_POST['action'] === 'upload' && !empty($_FILES['files'])) {
    foreach ($_FILES['files']['name'] as $i => $name) {
        $size = $_FILES['files']['size'][$i];
        if ($size > $MAX_SIZE_B) {           // تجاهل الملفات الكبيرة
            $skipped[] = $name;
            continue;
        }
        $tmp = $_FILES['files']['tmp_name'][$i];
        move_uploaded_file($tmp, "$DIR/" . basename($name));
    }
}

/* ---------- 3. إنشاء القائمة مع الترقيم ---------- */
$all = array_values(array_filter(scandir($DIR), function ($f) use ($SELF) {
    return is_file($f) && $f !== $SELF;
}));
$total  = count($all);
$page   = max(1, (int)($_GET['page'] ?? 1));
$pages  = max(1, ceil($total / $LIMIT));
$page   = min($page, $pages);
$offset = ($page - 1) * $LIMIT;
$list   = array_slice($all, $offset, $LIMIT);

/* تابع لإنشاء روابط الصفحات */
function plink(int $p, int $cur, string $lbl = null): string
{
    $lbl = $lbl ?? $p;
    $cls = $p === $cur ? 'class="active page-item"' : 'class="page-item"';
    return "<li $cls><a class=\"page-link\" href=\"?page=$p\">$lbl</a></li>";
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>إدارة الملفات</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<style>
  body{padding:2rem;font-family:'Cairo',sans-serif;background:#f8f9fa}
  .card{border:0;border-radius:1rem}
  .card-header{background:#0d6efd;color:#fff;border-top-right-radius:1rem;border-top-left-radius:1rem}
  li.active>.page-link{background:#0d6efd;color:#fff}
  .table>thead{vertical-align:middle}
</style>
</head>
<body>
<div class="container-lg">
<div class="card shadow-sm">
  <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">ملفات المجلد: <span class="fw-normal"><?= htmlspecialchars(basename($DIR)) ?></span></h5>
      <!-- زر اختيار الملفات -->
      <form id="uploadForm" method="post" enctype="multipart/form-data" class="m-0">
          <input type="file" name="files[]" id="fileInput" multiple hidden>
          <input type="hidden" name="action" value="upload">
          <button type="button" id="chooseBtn" class="btn btn-light text-primary fw-bold">
              <i class="bi bi-cloud-arrow-up"></i> رفع ملفات
          </button>
      </form>
  </div>
  <div class="card-body">
      <!-- قائمة الملفات المرفوضة بعد التحقق على الخادم -->
      <?php if ($skipped): ?>
      <div class="alert alert-warning">
          <strong>لم يتم رفعها (الحجم أكبر من 1&nbsp;م.ب):</strong>
          <ul class="mb-0">
            <?php foreach ($skipped as $n) echo '<li>'.htmlspecialchars($n).'</li>'; ?>
          </ul>
      </div>
      <?php endif; ?>

      <!-- جدول البيانات -->
      <div class="table-responsive mb-3">
      <table class="table table-bordered align-middle text-center" id="filesTable">
        <thead class="table-dark">
          <tr><th>اسم الملف</th><th style="width:140px">الإجراءات</th></tr>
        </thead>
        <tbody>
        <?php if (!$list): ?>
            <tr><td colspan="2"><em>لا توجد ملفات في هذه الصفحة.</em></td></tr>
        <?php else: foreach ($list as $f): ?>
            <tr>
              <td class="text-start"><?= htmlspecialchars($f) ?></td>
              <td>
                  <button class="btn btn-sm btn-danger deleteBtn" data-file="<?= htmlspecialchars($f) ?>">
                      <i class="bi bi-trash"></i> حذف
                  </button>
              </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      </div>

      <!-- الترقيم -->
      <nav>
        <ul class="pagination justify-content-center">
          <?= plink(1, $page, '«') ?>
          <?php for ($p=1;$p<=$pages;$p++) echo plink($p,$page); ?>
          <?= plink($pages, $page, '»') ?>
        </ul>
      </nav>
  </div>
</div>
</div>

<!-- نافذة استعراض الملفات المختارة -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">الملفات المختارة</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <table class="table table-sm mb-0 text-center">
        <thead class="table-light"><tr><th>الملف</th><th>الحجم (ك.ب)</th></tr></thead>
        <tbody id="modalList"></tbody>
      </table>
      <div id="noEligible" class="text-danger mt-3 d-none fw-bold">
          لا توجد ملفات صالحة ≤ 1&nbsp;م.ب. الرجاء اختيار ملفات أخرى.
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
      <button type="button" id="confirmBtn" class="btn btn-success">
          <i class="bi bi-check-circle"></i> رفع
      </button>
    </div>
  </div></div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script>
const MAX_B = <?= $MAX_SIZE_B ?>;
let allowed = [];         // الملفات ≤1 م.ب

/* اختيار الملفات */
$('#chooseBtn').on('click', () => $('#fileInput').click());

/* بناء نافذة المعاينة */
$('#fileInput').on('change', function () {
    const rows = $('#modalList').empty();
    allowed    = [];
    const bigClass  = 'bg-danger text-white';
    Array.from(this.files).forEach(f => {
        const tr = $('<tr>');
        const big = f.size > MAX_B;
        if (big) tr.addClass(bigClass);
        tr.append($('<td>').text(f.name));
        tr.append($('<td>').text((f.size/1024).toFixed(1)));
        rows.append(tr);
        if (!big) allowed.push(f);
    });
    $('#confirmBtn').prop('disabled', allowed.length === 0);
    $('#noEligible').toggleClass('d-none', allowed.length > 0);
    new bootstrap.Modal('#uploadModal').show();
});

/* رفع الملفات المقبولة */
$('#confirmBtn').on('click', function () {
    if (!allowed.length) return;
    const fd = new FormData();
    fd.append('action', 'upload');
    allowed.forEach(f => fd.append('files[]', f, f.name));
    fetch('', {method: 'POST', body: fd})
        .then(() => location.reload())
        .catch(() => alert('فشل الرفع'));
});

/* حذف ملف (ثم تحديث نفس الصفحة) */
$('#filesTable').on('click', '.deleteBtn', function () {
    const name = this.dataset.file;
    if (!confirm('حذف '+ name +' ؟')) return;
    fetch('', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=delete&file=' + encodeURIComponent(name)
    })
    .then(r => r.text())
    .then(t => {
        if (t.trim() === 'OK') location.href = location.href; else alert('تعذّر الحذف');
    });
});
</script>
</body>
</html>
