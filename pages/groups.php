
<?php
include('../check.php');
 include("../includes/db.php"); ?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
  <meta charset="UTF-8">
  <title>إدارة المجموعات</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="container">
  <h2 class="my-3 text-center">إدارة المجموعات</h2>

  <div class="row g-2 mb-2 align-items-center">
    <div class="col-12 col-md-9 d-flex gap-2 flex-wrap">
      <button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#addGroupModal">
        <i class="bi bi-plus-circle"></i> إضافة مجموعة
      </button>
      <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#bulkAddModal">
        <i class="bi bi-clipboard-data"></i> إضافة من إكسل (لصق)
      </button>
    </div>
    <div class="col-12 col-md-3 text-md-end">
      <button type="button" id="btnDeleteAllGroups" class="btn btn-danger">
        <i class="bi bi-trash-fill"></i> حذف الكل
      </button>
    </div>
  </div>

  <table id="groupsTable" class="table table-striped table-bordered w-100">
    <thead class="table-light">
      <tr>
        <th>اسم المجموعة</th>
        <th>التكتل</th>
        <th>رقم المجموعة</th>
        <th>فندق مكة</th>
        <th>العمليات</th>
      </tr>
    </thead>
  </table>

<?php
// استيراد CSV مفصول بفاصلة
/* استيراد CSV مفصول بفاصلة */
if (false && isset($_POST['csv_import'])) { // legacy CSV file-upload import disabled — replaced by paste-from-Excel bulk modal
  set_time_limit(0);                      // منع انتهاء المهلة في الملفات الضخمة
  $tmp = $_FILES['csv_file']['tmp_name'];

  if (($h = fopen($tmp, 'r')) !== false) {

      /* خيارات تسريع خاصة بـ SQLite – احذف الأسطر الثلاثة إذا كنتَ على MySQL */
      $pdo->exec("PRAGMA synchronous = OFF");
      $pdo->exec("PRAGMA journal_mode = MEMORY");

      /* أمر INSERT مُعدّ مرة واحدة */
      $insert = $pdo->prepare("
          INSERT INTO `group`
          (`group`, master_group, group_phone, mecca_hotel, mecca_location,
           medina_hotel, medina_location, mutawwef, mutawwef_location,
           mina, mina_location, arafa, arafa_location)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
      ");

      $pdo->beginTransaction();           // معاملة جماعية
      $i = 0;

      while (($row = fgetcsv($h, 1000, ';')) !== false) {
          if (count($row) < 13) { continue; }   // تخطَّ الصفوف الناقصة

          $insert->execute($row);
          $i++;

          /* التزام مرحلي كل 2000 صف (اختياري للتعافي السريع إذا انقطع الاستيراد) */
          if ($i % 2000 === 0) {
              $pdo->commit();
              $pdo->beginTransaction();
          }
      }
      fclose($h);
      $pdo->commit();                     // التزام نهائي

      /* إعادة إعدادات SQLite لطبيعتها (اختياري) */
      $pdo->exec("PRAGMA synchronous = NORMAL");

      echo "<script>
              Swal.fire('تم','تم استيراد CSV بنجاح','success')
                   .then(()=>location.reload());
            </script>";
  }
}

?>

<!-- ================= BULK PASTE FROM EXCEL ================= -->
<style>
.format-card { border: 1px solid #e3e6ea; border-radius: 14px; padding: 18px 20px; background: linear-gradient(180deg,#fff,#f8fbff); margin-bottom: 14px; }
.format-card h6 { font-weight: 700; margin: 0 0 4px; color: #14365d; display:flex; align-items:center; gap:8px; }
.format-card .subtitle { color:#5b6b80; font-size:.88rem; margin-bottom: 12px; }
.format-card .cols-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; }
.format-card .col-chip { background:#fff; border:1px solid #e1e7ef; border-radius:10px; padding:8px 10px; font-size:.85rem; display:flex; flex-direction:column; gap:2px; }
.format-card .col-chip .col-name { font-weight:700; color:#1c4e80; display:flex; align-items:center; gap:6px; }
.format-card .col-chip .col-hint { color:#7a8a9c; font-size:.78rem; }
.format-card .col-chip.required .col-name::after { content:"*"; color:#d33; }
.format-card .example { background:#0f172a; color:#e2e8f0; padding:10px 12px; border-radius:8px; font-family: ui-monospace, Menlo, monospace; font-size:.82rem; overflow-x:auto; direction:ltr; text-align:left; margin-top:10px; }
.format-card .tip { background:#fff7ed; border:1px dashed #f59e0b; color:#7c2d12; padding:8px 12px; border-radius:8px; font-size:.85rem; margin-top:10px; display:flex; align-items:flex-start; gap:8px; }
#bulkResults .list-group-item.error { background:#fff5f5; border-color:#f8d7da; }
.bulk-textarea { font-family: ui-monospace, Menlo, monospace; font-size:.86rem; min-height:160px; direction:ltr; text-align:left; }
</style>
<div class="modal fade" id="bulkAddModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-clipboard-data"></i> إضافة مجموعات بالجملة (لصق من إكسل)</h5>
        <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
      </div>
      <div class="modal-body">

        <div class="format-card">
          <h6><i class="bi bi-info-circle-fill text-primary"></i> صيغة البيانات المتوقعة</h6>
          <div class="subtitle">انسخ الأعمدة من ملف إكسل والصقها مباشرة في المربع أدناه. يمكنك أيضًا تحميل ملف القالب وتعبئته ثم نسخه.</div>

          <div class="d-flex flex-wrap gap-2 mb-2">
            <a class="btn btn-outline-primary btn-sm" href="../includes/groups_server.php?action=download_template">
              <i class="bi bi-download"></i> تحميل ملف القالب (CSV)
            </a>
            <span class="badge text-bg-light align-self-center">الفاصل في الملف: فاصلة منقوطة <code>;</code></span>
            <span class="badge text-bg-light align-self-center">عند اللصق من إكسل: الفاصل تبويب <kbd>Tab</kbd></span>
          </div>

          <div class="cols-grid">
            <div class="col-chip required"><span class="col-name"><i class="bi bi-people"></i> اسم المجموعة</span><span class="col-hint">group</span></div>
            <div class="col-chip required"><span class="col-name"><i class="bi bi-diagram-3"></i> التكتل</span><span class="col-hint">master_group</span></div>
            <div class="col-chip"><span class="col-name"><i class="bi bi-telephone"></i> رقم المجموعة</span><span class="col-hint">group_phone</span></div>
            <div class="col-chip"><span class="col-name"><i class="bi bi-building"></i> فندق مكة</span><span class="col-hint">يجب أن يكون موجوداً في جدول الفنادق</span></div>
            <div class="col-chip"><span class="col-name"><i class="bi bi-geo-alt"></i> موقع فندق مكة</span><span class="col-hint">mecca_location</span></div>
            <div class="col-chip"><span class="col-name"><i class="bi bi-building"></i> فندق المدينة</span><span class="col-hint">يجب أن يكون موجوداً في جدول الفنادق</span></div>
            <div class="col-chip"><span class="col-name"><i class="bi bi-geo-alt"></i> موقع فندق المدينة</span><span class="col-hint">medina_location</span></div>
            <div class="col-chip"><span class="col-name"><i class="bi bi-person-badge"></i> رقم المطوف</span><span class="col-hint">mutawwef</span></div>
            <div class="col-chip"><span class="col-name"><i class="bi bi-geo-alt"></i> موقع المطوف</span><span class="col-hint">mutawwef_location</span></div>
            <div class="col-chip"><span class="col-name"><i class="bi bi-house"></i> رقم مخيم منى</span><span class="col-hint">mina</span></div>
            <div class="col-chip"><span class="col-name"><i class="bi bi-geo-alt"></i> موقع مخيم منى</span><span class="col-hint">mina_location</span></div>
            <div class="col-chip"><span class="col-name"><i class="bi bi-house"></i> رقم مخيم عرفات</span><span class="col-hint">arafa</span></div>
            <div class="col-chip"><span class="col-name"><i class="bi bi-geo-alt"></i> موقع مخيم عرفات</span><span class="col-hint">arafa_location</span></div>
          </div>

          <div class="example">
اسم المجموعة&#9;التكتل&#9;رقم المجموعة&#9;فندق مكة&#9;موقع فندق مكة&#9;فندق المدينة&#9;موقع فندق المدينة&#9;رقم المطوف&#9;موقع المطوف&#9;رقم مخيم منى&#9;موقع مخيم منى&#9;رقم مخيم عرفات&#9;موقع مخيم عرفات
مجموعة 1&#9;تكتل أ&#9;0500000000&#9;فندق هيلتون مكة&#9;الشارع 1&#9;فندق دار التقوى&#9;الشارع 2&#9;المطوف 1&#9;موقع المطوف&#9;مخيم منى 1&#9;موقع منى&#9;مخيم عرفات 1&#9;موقع عرفات
          </div>

          <div class="tip">
            <i class="bi bi-lightbulb-fill mt-1"></i>
            <div>
              الحقول المطلوبة مُشار إليها بـ <span class="text-danger">*</span>. الحقول الاختيارية يمكن تركها فارغة.
              يتم التحقق من وجود أسماء فنادق مكة والمدينة في جدول الفنادق — الصفوف التي تحتوي على فنادق غير موجودة لن تُضاف.
            </div>
          </div>
        </div>

        <div class="mb-2">
          <label class="form-label fw-bold"><i class="bi bi-clipboard"></i> الصق البيانات هنا</label>
          <textarea id="bulkRowsInput" class="form-control bulk-textarea" placeholder="الصق هنا الأعمدة المنسوخة من إكسل..."></textarea>
        </div>

        <div id="bulkSummary" class="alert alert-info d-none" role="alert"></div>
        <div id="bulkResults" class="list-group small" style="max-height:220px; overflow:auto;"></div>

      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" id="bulkValidateBtn">
          <i class="bi bi-check2-circle"></i> تحقق من البيانات
        </button>
        <button class="btn btn-primary" type="button" id="bulkInsertBtn" disabled>
          <i class="bi bi-cloud-upload"></i> إضافة الكل
        </button>
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">إغلاق</button>
      </div>
    </div>
  </div>
</div>
<!-- ================= /BULK PASTE ================= -->

<!-- مودالات الإضافة والتعديل (كما في الإصدار المستقر السابق) -->
<div class="modal fade" id="addGroupModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
   <div class="modal-header"><h5 class="modal-title">إضافة مجموعة</h5>
     <button class="btn-close" data-bs-dismiss="modal"></button></div>
   <form method="post"><div class="modal-body row g-2">
<?php
$labels=[
 'group'=>'اسم المجموعة','master_group'=>'التكتل','group_phone'=>'رقم المجموعة',
 'mecca_hotel'=>'فندق مكة','mecca_location'=>'موقع فندق مكة',
 'medina_hotel'=>'فندق المدينة','medina_location'=>'موقع فندق المدينة',
 'mutawwef'=>'رقم المطوف','mutawwef_location'=>'موقع المطوف',
 'mina'=>'رقم مخيم منى','mina_location'=>'موقع مخيم منى',
 'arafa'=>'رقم مخيم عرفات','arafa_location'=>'موقع مخيم عرفات'
];
if(isset($_POST['add_group'])){
    $pdo->prepare("INSERT INTO `group`
        (`group`,master_group,group_phone,mecca_hotel,mecca_location,medina_hotel,medina_location,
         mutawwef,mutawwef_location,mina,mina_location,arafa,arafa_location)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
         ->execute(array_map(fn($k)=>$_POST[$k],array_keys($labels)));
    echo "<script>Swal.fire('تم','تمت الإضافة','success').then(()=>location.reload());</script>";
}
foreach($labels as $n=>$l){
  echo "<div class='col-12 col-md-6'><label class='form-label'>$l</label><input name='$n' class='form-control'></div>";
}
?>
   </div>
   <div class="modal-footer">
      <button name="add_group" class="btn btn-primary">حفظ</button>
      <button class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
   </div></form>
  </div></div>
</div>

<!-- ================= MASTER_GROUP CREDENTIALS (triple-click) ================= -->
<style>
  .mg-cred-row { display:flex; gap:8px; align-items:center; }
  .mg-cred-row input { font-family: ui-monospace, Menlo, monospace; }
</style>
<div class="modal fade" id="mgCredentialsModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-key-fill"></i> بيانات الدخول للتكتل</h5>
        <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
      </div>
      <div class="modal-body">
        <div class="text-muted small mb-2">
          هذه البيانات تُستخدم لتسجيل الدخول على صفحة <code>/hotel_pilgrim/login.php</code>.
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">اسم المستخدم (التكتل)</label>
          <div class="mg-cred-row">
            <input type="text" id="mgCredUsername" class="form-control" readonly>
            <button class="btn btn-outline-secondary" type="button" data-copy="#mgCredUsername" title="نسخ">
              <i class="bi bi-clipboard"></i>
            </button>
          </div>
        </div>
        <div class="mb-1">
          <label class="form-label fw-bold">كلمة المرور</label>
          <div class="mg-cred-row">
            <input type="text" id="mgCredPassword" class="form-control" readonly>
            <button class="btn btn-outline-secondary" type="button" data-copy="#mgCredPassword" title="نسخ">
              <i class="bi bi-clipboard"></i>
            </button>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">إغلاق</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="editGroupModal" tabindex="-1">
 <div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">تعديل مجموعة</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <form id="editGroupForm"><div class="modal-body row g-2">
    <input type="hidden" name="edit_id" id="edit_id">
<?php
foreach($labels as $n=>$l){
  echo "<div class='col-12 col-md-6'><label class='form-label'>$l</label>
        <input name='edit_$n' id='edit_$n' class='form-control'></div>";
}
?>
  </div>
  <div class="modal-footer">
    <button class="btn btn-primary" type="submit">حفظ</button>
    <button class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
  </div></form>
 </div></div>
</div>

<script>
$(function(){
  const table = $('#groupsTable').DataTable({
    serverSide:true,processing:true,
    ajax:{url:'../includes/groups_server.php',type:'POST'},
    language:{url:'https://cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json'},
    columns:[
      {data:'group'},
      {data:'master_group', createdCell: function(td, cellData, rowData){
          $(td).attr('data-col','master_group')
               .attr('data-mg', cellData == null ? '' : String(cellData));
      }},
      {data:'group_phone'},{data:'mecca_hotel'},
      {data:'actions',orderable:false,searchable:false}
    ],
    initComplete: function () {
        const api    = this.api();
        const $input = $('#groupsTable_filter input');

        /* 1️⃣ أزل مستمعات DataTables الافتراضية (keyup.DT + input.DT) */
        $input.off('.DT');

        /* 2️⃣ أضف مستمعًا يستجيب لـ Enter فقط */
        $input.on('keypress', function (e) {
            if (e.which === 13) {          // 13 = Enter
                api.search(this.value).draw();
            }
        });
    }
  });
  $('#btnDeleteAllGroups').on('click', function () {
    Swal.fire({
        title: 'حذف كل المجموعات؟',
        text: 'سيؤدي هذا إلى مسح الجدول بالكامل!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'نعم، احذف الكل',
        cancelButtonText: 'إلغاء'
    }).then(res => {
        if (res.isConfirmed) {
            $.post('../includes/groups_server.php', { delete_all_groups: 1 }, () => {
                table.ajax.reload(null, false);
                Swal.fire('تم', 'حُذفت جميع المجموعات', 'success');
            });
        }
    });
});
  $(document).on('click','.delete-btn',function(){
    const id=$(this).data('id');
    Swal.fire({title:'حذف؟',icon:'warning',showCancelButton:true}).then(r=>{
      if(r.isConfirmed){
        $.post('../includes/groups_server.php',{delete_id:id},()=>table.ajax.reload(null,false));
      }
    });
  });

  $(document).on('click','.edit-btn',function(){
    const d=table.row($(this).parents('tr')).data();
    $('#edit_id').val(d.id);
    for(const k in d){ $('#edit_'+k).val(d[k]); }
    new bootstrap.Modal('#editGroupModal').show();
  });

  // ===== Triple-click on master_group cell -> credentials modal =====
  // The three clicks must all happen within a single 0.7s window from the first click.
  const MG_TRIPLE_WINDOW_MS = 700;
  let mgClickCount = 0;
  let mgWindowTimer = null;
  let mgPending = false;
  $('#groupsTable').on('click', 'td[data-col="master_group"]', function(e){
      const mg = $(this).attr('data-mg') || '';
      if (!mg) return;
      if (mgClickCount === 0) {
          if (mgWindowTimer) clearTimeout(mgWindowTimer);
          mgWindowTimer = setTimeout(function(){
              mgClickCount = 0;
              mgWindowTimer = null;
          }, MG_TRIPLE_WINDOW_MS);
      }
      mgClickCount++;
      if (mgClickCount >= 3) {
          mgClickCount = 0;
          if (mgWindowTimer) { clearTimeout(mgWindowTimer); mgWindowTimer = null; }
          if (mgPending) return;
          mgPending = true;
          if (window.getSelection) { try { window.getSelection().removeAllRanges(); } catch (ignore) {} }
          $('#mgCredUsername').val('جاري التحميل...');
          $('#mgCredPassword').val('جاري التحميل...');
          new bootstrap.Modal('#mgCredentialsModal').show();
          $.post('../includes/groups_server.php', { action: 'mg_credentials', master_group: mg }, function(resp){
              if (!resp || resp.status !== 'ok') {
                  Swal.fire('خطأ', (resp && resp.message) ? resp.message : 'تعذّر جلب بيانات الدخول.', 'error');
                  bootstrap.Modal.getInstance(document.getElementById('mgCredentialsModal')).hide();
                  return;
              }
              $('#mgCredUsername').val(resp.username || '');
              $('#mgCredPassword').val(resp.password || '');
          }, 'json').fail(function(xhr){
              let msg = 'تعذّر الاتصال بالخادم.';
              try { msg = JSON.parse(xhr.responseText).message || msg; } catch(_){}
              Swal.fire('خطأ', msg, 'error');
              bootstrap.Modal.getInstance(document.getElementById('mgCredentialsModal')).hide();
          }).always(function(){ mgPending = false; });
      }
  });

  $(document).on('click', '#mgCredentialsModal [data-copy]', function(){
      const sel = $(this).data('copy');
      const $inp = $(sel);
      const val = $inp.val();
      if (!val) return;
      const done = () => {
          const $b = $(this);
          const html = $b.html();
          $b.html('<i class="bi bi-check2"></i>');
          setTimeout(() => $b.html(html), 900);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(val).then(done, () => {
              $inp[0].select(); document.execCommand('copy'); done();
          });
      } else {
          $inp[0].select(); document.execCommand('copy'); done();
      }
  });

  // ===== Bulk paste-from-Excel handlers =====
  let bulkValidatedRows = [];
  function bulkRenderResults(errors, total) {
      const $list = $('#bulkResults').empty();
      const $summary = $('#bulkSummary').removeClass('d-none alert-info alert-success alert-danger alert-warning');
      if (!errors.length) {
          $summary.addClass('alert-success').text('تم التحقق من ' + total + ' صف بنجاح. جاهزة للإضافة.');
      } else {
          $summary.addClass('alert-warning').text(
              'تم التحقق: ' + total + ' صف، ' + errors.length + ' خطأ. صحّح الأخطاء قبل الإضافة.'
          );
          errors.forEach(function(er){
              const $i = $('<div class="list-group-item error"></div>')
                  .text('سطر ' + (Number(er.index) + 1) + ': ' + er.message);
              $list.append($i);
          });
      }
  }
  $('#bulkValidateBtn').on('click', function(){
      const raw = $('#bulkRowsInput').val();
      if (!raw || !raw.trim()) { Swal.fire('تنبيه','الرجاء لصق بيانات أولاً.','warning'); return; }
      const $btn = $(this).prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> جاري التحقق...');
      $('#bulkInsertBtn').prop('disabled', true);
      $.post('../includes/groups_server.php', { action: 'bulk_validate', rows_text: raw }, function(resp){
          if (!resp || resp.status !== 'ok') {
              Swal.fire('خطأ', (resp && resp.message) ? resp.message : 'تعذّر التحقق.', 'error');
              if (resp && resp.missing_headers && resp.missing_headers.length) {
                  $('#bulkResults').html('<div class="list-group-item error">أعمدة مفقودة: ' + resp.missing_headers.join('، ') + '</div>');
              }
              return;
          }
          bulkValidatedRows = resp.rows || [];
          bulkRenderResults(resp.errors || [], resp.count || bulkValidatedRows.length);
          $('#bulkInsertBtn').prop('disabled', (resp.errors && resp.errors.length > 0) || bulkValidatedRows.length === 0);
      }, 'json').fail(function(xhr){
          let msg = 'تعذّر الاتصال بالخادم.';
          try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e) {}
          Swal.fire('خطأ', msg, 'error');
      }).always(function(){
          $btn.prop('disabled', false).html('<i class="bi bi-check2-circle"></i> تحقق من البيانات');
      });
  });
  $('#bulkInsertBtn').on('click', function(){
      if (!bulkValidatedRows.length) { Swal.fire('تنبيه','الرجاء التحقق من البيانات أولاً.','warning'); return; }
      const $btn = $(this).prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> جاري الإضافة...');
      $.post('../includes/groups_server.php', { action: 'bulk_insert', rows: JSON.stringify(bulkValidatedRows) }, function(resp){
          if (!resp || resp.status !== 'ok') {
              Swal.fire('خطأ', (resp && resp.message) ? resp.message : 'فشل الإدخال.', 'error');
              return;
          }
          Swal.fire('تم', 'تمت إضافة ' + resp.inserted + ' من ' + resp.total + ' مجموعة.', 'success');
          $('#bulkRowsInput').val(''); $('#bulkResults').empty(); $('#bulkSummary').addClass('d-none');
          bulkValidatedRows = [];
          bootstrap.Modal.getInstance(document.getElementById('bulkAddModal')).hide();
          table.ajax.reload(null, false);
      }, 'json').fail(function(xhr){
          let msg = 'تعذّر الاتصال بالخادم.';
          try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e) {}
          Swal.fire('خطأ', msg, 'error');
      }).always(function(){
          $btn.prop('disabled', false).html('<i class="bi bi-cloud-upload"></i> إضافة الكل');
      });
  });
  $('#bulkRowsInput').on('input', function(){
      bulkValidatedRows = [];
      $('#bulkInsertBtn').prop('disabled', true);
      $('#bulkSummary').addClass('d-none');
      $('#bulkResults').empty();
  });

  $('#editGroupForm').on('submit',function(e){
    e.preventDefault();
    $.post('../includes/groups_server.php',$(this).serialize(),()=>{
      bootstrap.Modal.getInstance('#editGroupModal').hide();
      table.ajax.reload(null,false);
      Swal.fire('تم','تم التحديث','success');
    });
  });
});
</script>
</body>
</html>
