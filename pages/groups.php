
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

  <!-- نموذج استيراد CSV بفاصلة -->
  <form method="post" enctype="multipart/form-data" class="row g-2 mb-3 align-items-center">

  <!-- ملف CSV -->
  <div class="col-7 col-md-9">
      <input type="file" name="csv_file" class="form-control" accept=".csv" required>
  </div>

  <!-- زر الاستيراد -->
  <div class="col-2 col-md-2 d-grid">
      <button type="submit" name="csv_import" class="btn btn-primary">استيراد CSV</button>
  </div>

  <!-- زر الحذف الكلي 🗑️ -->
  <div class="col-3 col-md-1 text-end">
      <button type="button" id="btnDeleteAllGroups" class="btn btn-danger">
          <i class="bi bi-trash-fill"></i>
      </button>
  </div>
</form>


  <div class="d-grid mb-3">
      <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addGroupModal">إضافة مجموعة</button>
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
if (isset($_POST['csv_import'])) {

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
      {data:'group'},{data:'master_group'},{data:'group_phone'},{data:'mecca_hotel'},
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
