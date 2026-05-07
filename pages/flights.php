<?php
include('../check.php');
include("../includes/db.php");

/* إضافة رحلة جديدة */
if (isset($_POST['add_flight'])) {
    $stmt = $pdo->prepare("INSERT INTO flight (num, date, time, type, flight_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$_POST['num'], $_POST['date'], $_POST['time'], $_POST['type'], $_POST['flight_id']]);
    echo "<script>Swal.fire('تم الحفظ','تمت إضافة الرحلة بنجاح','success').then(()=>location.reload());</script>";
}

/* استيراد CSV */
if (isset($_POST['csv_import'])) {
    set_time_limit(0);
    $tmp = $_FILES['csv_file']['tmp_name'];

    if (($h = fopen($tmp, 'r')) !== false) {
        $pdo->exec("PRAGMA synchronous = OFF");
        $pdo->exec("PRAGMA journal_mode = MEMORY");

        $insert = $pdo->prepare("INSERT INTO flight (num, date, time, type, flight_id) VALUES (?, ?, ?, ?, ?)");

        $pdo->beginTransaction();
        $i = 0;

        while (($row = fgetcsv($h, 1000, ';')) !== false) {
            if (count($row) < 5) continue;
            $insert->execute($row);
            $i++;

            if ($i % 2000 === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
            }
        }

        fclose($h);
        $pdo->commit();
        $pdo->exec("PRAGMA synchronous = NORMAL");

        echo "<script>Swal.fire('تم','تم استيراد CSV بنجاح','success').then(()=>location.reload());</script>";
    }
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
  <meta charset="UTF-8">
  <title>إدارة رحلات الطيران</title>
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
  <h2 class="my-3 text-center">إدارة رحلات الطيران</h2>

  <form method="post" enctype="multipart/form-data" class="row g-2 mb-3 align-items-center">
    <div class="col-7 col-md-9">
        <input type="file" name="csv_file" class="form-control" accept=".csv" required>
    </div>
    <div class="col-2 col-md-2 d-grid">
        <button type="submit" name="csv_import" class="btn btn-primary">استيراد CSV</button>
    </div>
    <div class="col-3 col-md-1 text-end">
        <button type="button" id="btnDeleteAllFlights" class="btn btn-danger">
            <i class="bi bi-trash-fill"></i>
        </button>
    </div>
  </form>

  <div class="d-grid mb-3">
      <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addFlightModal">إضافة رحلة</button>
  </div>

  <table id="flightsTable" class="table table-striped table-bordered w-100">
    <thead class="table-light">
      <tr>
        <th>رقم الرحلة</th>
        <th>تاريخ الرحلة</th>
        <th>وقت الرحلة</th>
        <th>نوع الرحلة</th>
        <th>معرف الرحلة</th>
        <th>العمليات</th>
      </tr>
    </thead>
  </table>

  <!-- Modal: إضافة رحلة -->
  <div class="modal fade" id="addFlightModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <form method="post">
          <div class="modal-header"><h5 class="modal-title">إضافة رحلة جديدة</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body row g-3">
            <div class="col-md-6">
              <label class="form-label">رقم الرحلة</label>
              <input name="num" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">تاريخ الرحلة</label>
              <input name="date" type="date" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">وقت الرحلة</label>
              <input name="time" type="time" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">نوع الرحلة</label>
              <select name="type" class="form-select" required>
                <option value="ذهاب">ذهاب</option>
                <option value="إياب">إياب</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">معرف الرحلة</label>
              <input name="flight_id" class="form-control" required>
            </div>
          </div>
          <div class="modal-footer">
            <button type="submit" name="add_flight" class="btn btn-primary">حفظ</button>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal: تعديل رحلة -->
<div class="modal fade" id="editFlightModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form id="editFlightForm">
        <div class="modal-header">
          <h5 class="modal-title">تعديل بيانات الرحلة</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body row g-3">
          <input type="hidden" name="edit_id" id="edit_id">

          <div class="col-md-6">
            <label class="form-label">رقم الرحلة</label>
            <input name="edit_num" id="edit_num" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">تاريخ الرحلة</label>
            <input name="edit_date" id="edit_date" type="date" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">وقت الرحلة</label>
            <input name="edit_time" id="edit_time" type="time" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">نوع الرحلة</label>
            <select name="edit_type" id="edit_type" class="form-select" required>
              <option value="ذهاب">ذهاب</option>
              <option value="إياب">إياب</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">معرف الرحلة</label>
            <input name="edit_flight_id" id="edit_flight_id" class="form-control" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
$(function(){
  const table = $('#flightsTable').DataTable({
    serverSide:true,processing:true,
    ajax:{url:'../includes/flights_server.php',type:'POST'},
    language:{url:'https://cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json'},
    columns:[
      {data:'num'},{data:'date'},{data:'time'},{data:'type'},{data:'flight_id'},
      {data:'actions',orderable:false,searchable:false}
    ],
    initComplete: function () {
        const api = this.api();
        const $input = $('#flightsTable_filter input');
        $input.off('.DT');
        $input.on('keypress', function (e) {
            if (e.which === 13) api.search(this.value).draw();
        });
    }
  });

  $('#btnDeleteAllFlights').on('click', function () {
    Swal.fire({
        title: 'حذف كل الرحلات؟',
        text: 'سيؤدي هذا إلى مسح الجدول بالكامل!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'نعم، احذف الكل',
        cancelButtonText: 'إلغاء'
    }).then(res => {
        if (res.isConfirmed) {
            $.post('../includes/flights_server.php', { delete_all_flights: 1 }, () => {
                table.ajax.reload(null, false);
                Swal.fire('تم', 'حُذفت جميع الرحلات', 'success');
            });
        }
    });
  });

  $(document).on('click','.delete-btn',function(){
    const id=$(this).data('id');
    Swal.fire({title:'حذف؟',icon:'warning',showCancelButton:true}).then(r=>{
      if(r.isConfirmed){
        $.post('../includes/flights_server.php',{delete_id:id},()=>table.ajax.reload(null,false));
      }
    });
  });

  $(document).on('click','.edit-btn',function(){
    const d=table.row($(this).parents('tr')).data();
    for(const k in d){ $('#edit_'+k).val(d[k]); }
    $('#edit_id').val(d.id);
    new bootstrap.Modal('#editFlightModal').show();
  });

  $('#editFlightForm').on('submit',function(e){
    e.preventDefault();
    $.post('../includes/flights_server.php',$(this).serialize(),()=>{
      bootstrap.Modal.getInstance(document.getElementById('editFlightModal')).hide();
      table.ajax.reload(null,false);
      Swal.fire('تم','تم التحديث بنجاح','success');
    });
  });
});
</script>
</body>
</html>