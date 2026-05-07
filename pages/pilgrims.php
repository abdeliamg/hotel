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
<form class="row g-2 mb-3 align-items-center" enctype="multipart/form-data" method="post">
<div class="col-7 col-md-9">
<input accept=".csv" class="form-control" name="csv_file" required="" type="file"/>
</div>
<!-- زر الاستيراد -->
<div class="col-2 col-md-2 d-grid">
<button class="btn btn-primary" name="import" type="submit">استيراد CSV</button>
</div>
<!-- زر الحذف الكل 🗑️ -->
<div class="col-3 col-md-1 text-end">
<button class="btn btn-danger" id="btnDeleteAll" type="button">
<i class="bi bi-trash-fill"></i>
</button>
</div>
</form>
<div class="d-grid mb-2">
<button class="btn btn-success" data-bs-target="#addModal" data-bs-toggle="modal">إضافة حاج جديد</button>
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
if (isset($_POST['import'])) {
    set_time_limit(0);              // احتياطًا لمنع انتهاء المهلة
    $file = $_FILES['csv_file']['tmp_name'];

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
