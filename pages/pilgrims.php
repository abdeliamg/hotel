<?php
include('../check.php');
 include("../includes/db.php"); ?>
<!DOCTYPE html>

<html dir="rtl" lang="ar">
<head>
<meta charset="utf-8"/>
<title>إدارة الحجاج</title>
<meta content="width=device-width, initial-scale=1" name="viewport"/>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet"/>
<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet"/>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
</link>
<style>
.select2-container--open {
  z-index: 9999 !important;
}
</style>

</head>
<body class="container">
<h1 class="my-3 text-center">إدارة الحجاج</h1>
<div class="row g-2 mb-2 align-items-center">
  <div class="col-12 col-md-9 d-flex gap-2 flex-wrap">
    <button class="btn btn-success" type="button" data-bs-target="#addModal" data-bs-toggle="modal">
      <i class="bi bi-person-plus-fill"></i> إضافة حاج جديد
    </button>
    <button class="btn btn-primary" type="button" data-bs-target="#bulkAddModal" data-bs-toggle="modal">
      <i class="bi bi-clipboard-data"></i> إضافة من إكسل (لصق)
    </button>
  </div>
  <div class="col-12 col-md-3 text-md-end">
    <button class="btn btn-danger" id="btnDeleteAll" type="button">
      <i class="bi bi-trash-fill"></i> حذف الكل
    </button>
  </div>
</div>
<table class="table table-striped table-bordered w-100" id="pilgrimsTable">
<thead class="table-light">
<tr>
<th>الرقم الوطني</th>
<th>اسم الحاج</th>
<th>المجموعة</th>
<th>التكتل</th>
<th>الباركود</th>
<th>العمليات</th>
</tr>
</thead>
</table>
<div class="modal fade" id="addModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<div class="modal-header"><h5 class="modal-title">إضافة حاج جديد</h5>
<button class="btn-close" data-bs-dismiss="modal" type="button"></button></div>
<form method="post">
<div class="modal-body row g-2">
<?php
              $labels=["national"=>"الرقم الوطني","name"=>"اسم الحاج","group"=>"المجموعة","barcode"=>"الباركود",
                       "phone"=>"رقم الجوال","passport"=>"جواز السفر","visa"=>"الفيزا","app_id"=>"App ID"];
              if (isset($_POST['add_submit'])) {
                 $pdo->prepare("INSERT INTO pilgrim (national,name,`group`,barcode,phone,passport,visa,app_id)
                        VALUES (?,?,?,?,?,?,?,?)")
                     ->execute([$_POST['national'],$_POST['name'],$_POST['group'],$_POST['barcode'],
                                $_POST['phone'],$_POST['passport'],$_POST['visa'],$_POST['app_id']]);
                 echo "<script>Swal.fire('تم الحفظ','أُضيف الحاج بنجاح','success').then(()=>location.reload());</script>";
              }
              foreach($labels as $n=>$l){
                if ($n == 'group') {
                    echo "<div class='col-12 col-md-6'>
                            <label class='form-label'>$l</label>
                            <select class='form-select select2' name='group' id='group'>
                                <option value=''>-- اختر مجموعة --</option>
                            </select>
                          </div>";
                } else {
                    echo "<div class='col-12 col-md-6'>
                            <label class='form-label'>$l</label>
                            <input class='form-control' name='$n'/>
                          </div>";
                }
                
              }
            ?>
         
<div class="col-12 col-md-6">
<label class="form-label">رحلة الذهاب</label>
<select class="form-select select2" id="flight_id_out" name="flight_id_out">
<option value="">-- اختر رحلة الذهاب --</option>
</select>
</div>
<div class="col-12 col-md-6">
<label class="form-label">رحلة الإياب</label>
<select class="form-select select2" id="flight_id_in" name="flight_id_in">
<option value="">-- اختر رحلة الإياب --</option>
</select>
</div>
</div>
<div class="modal-footer">
<button class="btn btn-primary" name="add_submit" type="submit">حفظ</button>
<button class="btn btn-secondary" data-bs-dismiss="modal" type="button">إغلاق</button>
</div></form>
</div>
</div>
</div>
<?php
if (false) { // legacy CSV import disabled

    if (($h = fopen($file, "r")) !== false) {

        /* إعدادات تسريع لـ SQLite */
        $pdo->exec("PRAGMA synchronous = OFF");
        $pdo->exec("PRAGMA journal_mode = MEMORY");

        $insert = $pdo->prepare("
    INSERT INTO pilgrim
    (national, name, `group`, barcode, phone, passport, visa, app_id, flight_id_out, flight_id_in)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");


        $pdo->beginTransaction();
        $count = 0;

        while (($row = fgetcsv($h, 1000, ";")) !== false) {
            // تخلَّص من أي صفوف ناقصة:
            if (count($row) < 10) { continue; }

            $insert->execute($row);
            $count++;

            /* التزام مرحلي كل 2000 صف (اختياري) */
            if ($count % 2000 === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
            }
        }
        fclose($h);
        $pdo->commit();                     // التزام نهائي
        $pdo->exec("PRAGMA synchronous = NORMAL"); // إعادة الوضع الافتراضي
    }

    echo "<script>
            Swal.fire('تم','اكتمل استيراد CSV بنجاح','success')
                 .then(()=>location.reload());
          </script>";
}

?>

<script>
$(function(){
    const table = $('#pilgrimsTable').DataTable({
    serverSide: true,
    processing: true,
    ajax: { url: '../includes/pilgrims_server.php', type: 'POST' },
    language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json' },
    columns: [
        { data: 'national' },
        { data: 'name'     },
        { data: 'group'    },
        { data: 'master_group'    },
        { data: 'barcode'  },
        { data: 'actions', orderable: false, searchable: false }
    ],

    /* هنا نفصل المستمعات الافتراضية ثم نضيف مستمع Enter */
    initComplete: function () {
        const api    = this.api();
        const $input = $('#pilgrimsTable_filter input');

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

/* الكود الجديد ↓ */
$('#btnDeleteAll').on('click',function(){
       Swal.fire({
         title:'حذف جميع الحجاج؟',
         text:'هذا الخيار سيحذف كل السجلات نهائياً!',
         icon:'warning',
         showCancelButton:true,
         confirmButtonColor:'#d33',
         confirmButtonText:'نعم، احذف الكل',
         cancelButtonText:'إلغاء'
       }).then(res=>{
         if(res.isConfirmed){
            $.post('../includes/pilgrims_server.php',{delete_all:1},function(){
                table.ajax.reload();
                Swal.fire('تم','حُذفت جميع البيانات بنجاح','success');
            });
         }
       });
   });
  $(document).on('click','.delete-btn',function(){
      const id=$(this).data('id');
      Swal.fire({title:'حذف؟',icon:'warning',
          showCancelButton:true,confirmButtonText:'نعم'}).then(r=>{
              if(r.isConfirmed){
                 $.post('../includes/pilgrims_server.php',{delete_id:id},()=>table.ajax.reload(null,false));
              }
          });
  });

  $(document).on('click', '.edit-btn', function () {
    const d = table.row($(this).parents('tr')).data();

    // تعبئة الحقول النصية تلقائيًا
    for (const k in d) {
        const $el = $('#edit_' + k);
        if ($el.length && !$el.is('select')) {
            $el.val(d[k]);
        }
    }

    // الحقول select2
    $('#edit_group').val(d.group).trigger('change');
    $('#edit_flight_id_out').val(d.flight_id_out).trigger('change');
    $('#edit_flight_id_in').val(d.flight_id_in).trigger('change');

    // الحقل المخفي الخاص بالمعرف
    $('#edit_id').val(d.id);

    // فتح المودال
    new bootstrap.Modal('#editModal').show();
});


  // ===== Bulk paste-from-Excel handlers =====
  // Note: the server-side endpoints accept the raw paste text directly via
  // `rows_text`, so we never upload a JSON copy of the rows from the browser.
  // This avoids 4-8 MB POST bodies on 20k+ row imports and keeps the request
  // small. Validation results are summary-only (errors capped server-side).
  let bulkValidated = false;
  let bulkValidatedCount = 0;

  function bulkRenderResults(resp) {
      const total      = Number(resp.count || 0);
      const errors     = resp.errors || [];
      const errorCount = Number(resp.error_count || errors.length || 0);
      const truncated  = !!resp.truncated;
      const $list = $('#bulkResults').empty();
      const $summary = $('#bulkSummary').removeClass('d-none alert-info alert-success alert-danger alert-warning');
      if (!errorCount) {
          $summary.addClass('alert-success').text('تم التحقق من ' + total + ' صف بنجاح. جاهزة للإضافة.');
      } else {
          $summary.addClass('alert-warning').text(
              'تم التحقق: ' + total + ' صف، ' + errorCount + ' خطأ.' +
              (truncated ? ' (يتم عرض أول ' + errors.length + ' خطأ فقط)' : '') +
              ' صحّح الأخطاء قبل الإضافة.'
          );
          errors.forEach(function(er){
              const $i = $('<div class="list-group-item error"></div>')
                  .text('سطر ' + (Number(er.index) + 1) + ': ' + er.message);
              $list.append($i);
          });
      }
  }

  function bulkAjax(action, onSuccess, onAlways) {
      const raw = $('#bulkRowsInput').val();
      return $.ajax({
          url: '../includes/pilgrims_server.php',
          type: 'POST',
          dataType: 'json',
          timeout: 0,
          data: { action: action, rows_text: raw }
      }).done(onSuccess).fail(function(xhr){
          let msg = 'تعذّر الاتصال بالخادم.';
          if (xhr && xhr.status) { msg += ' (HTTP ' + xhr.status + ')'; }
          try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch(_) {}
          Swal.fire('خطأ', msg, 'error');
      }).always(onAlways || function(){});
  }

  $('#bulkValidateBtn').on('click', function(){
      const raw = $('#bulkRowsInput').val();
      if (!raw || !raw.trim()) {
          Swal.fire('تنبيه','الرجاء لصق بيانات أولاً.','warning');
          return;
      }
      const $btn = $(this).prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> جاري التحقق...');
      $('#bulkInsertBtn').prop('disabled', true);
      bulkValidated = false;
      bulkAjax('bulk_validate', function(resp){
          if (!resp || resp.status !== 'ok') {
              Swal.fire('خطأ', (resp && resp.message) ? resp.message : 'تعذّر التحقق.', 'error');
              if (resp && resp.missing_headers && resp.missing_headers.length) {
                  $('#bulkResults').html('<div class="list-group-item error">أعمدة مفقودة: ' + resp.missing_headers.join('، ') + '</div>');
              }
              return;
          }
          bulkRenderResults(resp);
          bulkValidatedCount = Number(resp.count || 0);
          const errCount = Number(resp.error_count || (resp.errors || []).length || 0);
          bulkValidated = (errCount === 0 && bulkValidatedCount > 0);
          $('#bulkInsertBtn').prop('disabled', !bulkValidated);
      }, function(){
          $btn.prop('disabled', false).html('<i class="bi bi-check2-circle"></i> تحقق من البيانات');
      });
  });

  $('#bulkInsertBtn').on('click', function(){
      if (!bulkValidated) {
          Swal.fire('تنبيه','الرجاء التحقق من البيانات أولاً.','warning');
          return;
      }
      const $btn = $(this).prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> جاري الإضافة...');
      bulkAjax('bulk_insert', function(resp){
          if (!resp || resp.status !== 'ok') {
              Swal.fire('خطأ', (resp && resp.message) ? resp.message : 'فشل الإدخال.', 'error');
              return;
          }
          const msg = 'تمت إضافة ' + resp.inserted + ' من ' + resp.total + ' حاج' +
                      (resp.skipped ? '. تم تخطّي ' + resp.skipped + ' سطر بسبب أخطاء.' : '.');
          Swal.fire('تم', msg, 'success');
          $('#bulkRowsInput').val('');
          $('#bulkResults').empty();
          $('#bulkSummary').addClass('d-none');
          bulkValidated = false;
          bulkValidatedCount = 0;
          bootstrap.Modal.getInstance(document.getElementById('bulkAddModal')).hide();
          table.ajax.reload(null, false);
      }, function(){
          $btn.prop('disabled', false).html('<i class="bi bi-cloud-upload"></i> إضافة الكل');
      });
  });

  $('#bulkRowsInput').on('input', function(){
      bulkValidated = false;
      bulkValidatedCount = 0;
      $('#bulkInsertBtn').prop('disabled', true);
      $('#bulkSummary').addClass('d-none');
      $('#bulkResults').empty();
  });

  $('#editForm').on('submit',function(e){
      e.preventDefault();
      $.post('../includes/pilgrims_server.php',$(this).serialize(),()=>{
          bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
          table.ajax.reload(null,false);
          Swal.fire('تم','تم التحديث بنجاح','success');
      });
  });
});
</script>
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
#bulkResults .list-group-item.ok { background:#f0fdf4; border-color:#bbf7d0; }
.bulk-textarea { font-family: ui-monospace, Menlo, monospace; font-size:.86rem; min-height:160px; direction:ltr; text-align:left; }
</style>
<div class="modal fade" id="bulkAddModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-clipboard-data"></i> إضافة حجاج بالجملة (لصق من إكسل)</h5>
        <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
      </div>
      <div class="modal-body">

        <div class="format-card">
          <h6><i class="bi bi-info-circle-fill text-primary"></i> صيغة البيانات المتوقعة</h6>
          <div class="subtitle">انسخ الأعمدة من ملف إكسل والصقها مباشرة في المربع أدناه. يمكنك أيضًا تحميل ملف القالب وتعبئته ثم نسخه.</div>

          <div class="d-flex flex-wrap gap-2 mb-2">
            <a class="btn btn-outline-primary btn-sm" href="../includes/pilgrims_server.php?action=download_template">
              <i class="bi bi-download"></i> تحميل ملف القالب (CSV)
            </a>
            <span class="badge text-bg-light align-self-center">الفاصل في الملف: فاصلة منقوطة <code>;</code></span>
            <span class="badge text-bg-light align-self-center">عند اللصق من إكسل: الفاصل تبويب <kbd>Tab</kbd></span>
          </div>

          <div class="cols-grid">
            <div class="col-chip required"><span class="col-name"><i class="bi bi-person-vcard"></i> الرقم الوطني</span><span class="col-hint">national</span></div>
            <div class="col-chip required"><span class="col-name"><i class="bi bi-person"></i> الاسم</span><span class="col-hint">name</span></div>
            <div class="col-chip required"><span class="col-name"><i class="bi bi-people"></i> المجموعة</span><span class="col-hint">يجب أن تكون موجودة في جدول المجموعات</span></div>
            <div class="col-chip"><span class="col-name"><i class="bi bi-upc"></i> الباركود</span><span class="col-hint">barcode</span></div>
            <div class="col-chip"><span class="col-name"><i class="bi bi-telephone"></i> الجوال</span><span class="col-hint">phone</span></div>
            <div class="col-chip"><span class="col-name"><i class="bi bi-passport"></i> جواز السفر</span><span class="col-hint">passport</span></div>
            <div class="col-chip"><span class="col-name"><i class="bi bi-credit-card-2-front"></i> الفيزا</span><span class="col-hint">visa</span></div>
            <div class="col-chip"><span class="col-name"><i class="bi bi-phone"></i> App ID</span><span class="col-hint">app_id</span></div>
            <div class="col-chip"><span class="col-name"><i class="bi bi-airplane"></i> رحلة الذهاب</span><span class="col-hint">flight_id_out (إن وُجد يجب أن يكون موجوداً)</span></div>
            <div class="col-chip"><span class="col-name"><i class="bi bi-airplane-engines"></i> رحلة الإياب</span><span class="col-hint">flight_id_in (إن وُجد يجب أن يكون موجوداً)</span></div>
          </div>

          <div class="example">
الرقم الوطني&#9;الاسم&#9;المجموعة&#9;الباركود&#9;الجوال&#9;جواز السفر&#9;الفيزا&#9;App ID&#9;رحلة الذهاب&#9;رحلة الإياب
1234567890&#9;محمد أحمد&#9;مجموعة 1&#9;BC0001&#9;0500000000&#9;P1234567&#9;V1234567&#9;APP-001&#9;SV101&#9;SV102
          </div>

          <div class="tip">
            <i class="bi bi-lightbulb-fill mt-1"></i>
            <div>
              الحقول المطلوبة مُشار إليها بـ <span class="text-danger">*</span>. الحقول الاختيارية يمكن تركها فارغة.
              يتم التحقق من وجود اسم المجموعة وأرقام الرحلات قبل الإدخال — الصفوف التي تحتوي على قيم غير صالحة لن تُضاف.
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

<div class="modal fade" id="editModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">تعديل بيانات الحاج</h5>
<button class="btn-close" data-bs-dismiss="modal" type="button"></button></div>
<form id="editForm"><div class="modal-body row g-2">
<input id="edit_id" name="edit_id" type="hidden"/>
<?php
      foreach(["national","name","group","barcode","phone","passport","visa","app_id"] as $f){
        if ($f == 'group') {
            echo "<div class='col-12 col-md-6'>
                    <label class='form-label'>".$labels[$f]."</label>
                    <select class='form-select select2' id='edit_group' name='edit_group'>
                      <option value=''>-- اختر مجموعة --</option>
                    </select>
                  </div>";
        } else {
            echo "<div class='col-12 col-md-6'>
                    <label class='form-label'>".$labels[$f]."</label>
                    <input class='form-control' id='edit_{$f}' name='edit_{$f}'/>
                  </div>";
        }
    }
    
    ?>
    <div class='col-12 col-md-6'>
  <label class='form-label'>رحلة الذهاب</label>
  <select name='edit_flight_id_out' id='edit_flight_id_out' class='form-select select2'>
    <option value=''>-- اختر رحلة الذهاب --</option>
  </select>
</div>

<div class='col-12 col-md-6'>
  <label class='form-label'>رحلة الإياب</label>
  <select name='edit_flight_id_in' id='edit_flight_id_in' class='form-select select2'>
    <option value=''>-- اختر رحلة الإياب --</option>
  </select>
</div>
<div class="modal-footer">
<button class="btn btn-primary" type="submit">حفظ</button>
<button class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
</div>
  </form></div>

</div></div>

</body>
</html>
<script>
function loadFlightOptions() {
    $.getJSON('../includes/all_flights.php', function(flights) {
        const label = f => f.num + " - " + f.flight_id + " (" + f.date + ")";
        const ids = ['flight_id_out', 'flight_id_in', 'edit_flight_id_out', 'edit_flight_id_in'];

        ids.forEach(id => {
            const $sel = $('#' + id);
            $sel.empty().append('<option value="">-- اختر --</option>');
            flights.forEach(f => {
                $sel.append(`<option value="${f.flight_id}">${label(f)}</option>`);
            });
        });
    });
}

function loadGroupOptions() {
  $.getJSON('../includes/all_groups.php', function(groups) {
    const label = g => `${g.group} - ${g.master_group}`;
    const ids = ['group', 'edit_group'];

    ids.forEach(id => {
      const $sel = $('#' + id);
      $sel.empty().append('<option value="">-- اختر مجموعة --</option>');
      groups.forEach(g => {
        $sel.append(`<option value="${g.group}">${label(g)}</option>`);
      });
    });
  });
}


$(document).ready(function() {
    $('.select2').select2({ width: '100%' });
    loadFlightOptions();

    loadGroupOptions();
});
</script>
