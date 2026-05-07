<?php
include('../check.php');
require_once('../includes/auth.php');

// Require admin role
$current_user = require_role('admin');

include("../includes/db.php");
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
  <meta charset="UTF-8">
  <title>إدارة المستخدمين</title>
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
  <h2 class="my-3 text-center">إدارة المستخدمين</h2>

  <div class="d-grid mb-3">
      <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addUserModal">إضافة مستخدم</button>
  </div>

  <table id="usersTable" class="table table-striped table-bordered w-100">
    <thead class="table-light">
      <tr>
        <th>اسم المستخدم</th>
        <th>الاسم الكامل</th>
        <th>البريد الإلكتروني</th>
        <th>الدور</th>
        <th>الحالة</th>
        <th>آخر تسجيل دخول</th>
        <th>العمليات</th>
      </tr>
    </thead>
  </table>

  <!-- Add User Modal -->
  <div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">إضافة مستخدم جديد</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form id="addUserForm">
          <div class="modal-body row g-2">
            <div class="col-12 col-md-6">
              <label class="form-label">اسم المستخدم</label>
              <input name="username" class="form-control" required>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">الاسم الكامل</label>
              <input name="full_name" class="form-control" required>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">البريد الإلكتروني</label>
              <input name="email" type="email" class="form-control">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">الدور</label>
              <select name="role" class="form-select" required>
                <option value="user">مستخدم</option>
                <option value="admin">مدير</option>
                <option value="viewer">مشاهد</option>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">كلمة المرور</label>
              <input name="password" type="password" class="form-control" required minlength="6">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">تأكيد كلمة المرور</label>
              <input name="password_confirm" type="password" class="form-control" required minlength="6">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">الحالة</label>
              <select name="status" class="form-select" required>
                <option value="active">نشط</option>
                <option value="inactive">غير نشط</option>
              </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="submit" class="btn btn-primary">حفظ</button>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Edit User Modal -->
  <div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">تعديل مستخدم</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form id="editUserForm">
          <div class="modal-body row g-2">
            <input type="hidden" name="edit_id" id="edit_id">
            <div class="col-12 col-md-6">
              <label class="form-label">اسم المستخدم</label>
              <input name="edit_username" id="edit_username" class="form-control" required>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">الاسم الكامل</label>
              <input name="edit_full_name" id="edit_full_name" class="form-control" required>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">البريد الإلكتروني</label>
              <input name="edit_email" id="edit_email" type="email" class="form-control">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">الدور</label>
              <select name="edit_role" id="edit_role" class="form-select" required>
                <option value="user">مستخدم</option>
                <option value="admin">مدير</option>
                <option value="viewer">مشاهد</option>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">كلمة المرور الجديدة (اتركها فارغة إذا لم ترد التغيير)</label>
              <input name="edit_password" id="edit_password" type="password" class="form-control" minlength="6">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">تأكيد كلمة المرور</label>
              <input name="edit_password_confirm" id="edit_password_confirm" type="password" class="form-control" minlength="6">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">الحالة</label>
              <select name="edit_status" id="edit_status" class="form-select" required>
                <option value="active">نشط</option>
                <option value="inactive">غير نشط</option>
              </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="submit" class="btn btn-primary">حفظ</button>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Reset Password Modal -->
  <div class="modal fade" id="resetPasswordModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">إعادة تعيين كلمة المرور</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form id="resetPasswordForm">
          <div class="modal-body">
            <input type="hidden" name="reset_id" id="reset_id">
            <div class="mb-3">
              <label class="form-label">كلمة المرور الجديدة</label>
              <input name="new_password" id="new_password" type="password" class="form-control" required minlength="6">
            </div>
            <div class="mb-3">
              <label class="form-label">تأكيد كلمة المرور</label>
              <input name="new_password_confirm" id="new_password_confirm" type="password" class="form-control" required minlength="6">
            </div>
          </div>
          <div class="modal-footer">
            <button type="submit" class="btn btn-primary">حفظ</button>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
  $(function(){
    const currentUserId = <?= $current_user['id'] ?>;

    const table = $('#usersTable').DataTable({
      serverSide: true,
      processing: true,
      ajax: { url: '../includes/users_server.php', type: 'POST' },
      language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json' },
      columns: [
        { data: 'username' },
        { data: 'full_name' },
        { data: 'email' },
        { data: 'role_badge', orderable: false },
        { data: 'status_badge', orderable: false },
        { data: 'last_login' },
        { data: 'actions', orderable: false, searchable: false }
      ],
      initComplete: function() {
        const api = this.api();
        const $input = $('#usersTable_filter input');
        $input.off('.DT');
        $input.on('keypress', function(e) {
          if (e.which === 13) api.search(this.value).draw();
        });
      }
    });

    // Add User
    $('#addUserForm').on('submit', function(e) {
      e.preventDefault();
      const password = $('[name="password"]').val();
      const confirm = $('[name="password_confirm"]').val();

      if (password !== confirm) {
        Swal.fire('خطأ', 'كلمات المرور غير متطابقة', 'error');
        return;
      }

      $.post('../includes/users_server.php', $(this).serialize() + '&add_user=1', function(response) {
        const res = JSON.parse(response);
        if (res.success) {
          bootstrap.Modal.getInstance('#addUserModal').hide();
          table.ajax.reload(null, false);
          Swal.fire('تم', 'تمت إضافة المستخدم بنجاح', 'success');
          $('#addUserForm')[0].reset();
        } else {
          Swal.fire('خطأ', res.message, 'error');
        }
      });
    });

    // Edit User
    $(document).on('click', '.edit-btn', function() {
      const d = table.row($(this).parents('tr')).data();
      $('#edit_id').val(d.id);
      $('#edit_username').val(d.username);
      $('#edit_full_name').val(d.full_name);
      $('#edit_email').val(d.email);
      $('#edit_role').val(d.role);
      $('#edit_status').val(d.status);
      $('#edit_password').val('');
      $('#edit_password_confirm').val('');

      // Disable role/status editing for own account
      if (d.id == currentUserId) {
        $('#edit_role').prop('disabled', true);
        $('#edit_status').prop('disabled', true);
      } else {
        $('#edit_role').prop('disabled', false);
        $('#edit_status').prop('disabled', false);
      }

      new bootstrap.Modal('#editUserModal').show();
    });

    $('#editUserForm').on('submit', function(e) {
      e.preventDefault();
      const password = $('#edit_password').val();
      const confirm = $('#edit_password_confirm').val();

      if (password && password !== confirm) {
        Swal.fire('خطأ', 'كلمات المرور غير متطابقة', 'error');
        return;
      }

      $.post('../includes/users_server.php', $(this).serialize(), function(response) {
        const res = JSON.parse(response);
        if (res.success) {
          bootstrap.Modal.getInstance('#editUserModal').hide();
          table.ajax.reload(null, false);
          Swal.fire('تم', 'تم تحديث المستخدم بنجاح', 'success');
        } else {
          Swal.fire('خطأ', res.message, 'error');
        }
      });
    });

    // Delete User
    $(document).on('click', '.delete-btn', function() {
      const id = $(this).data('id');
      if (id == currentUserId) {
        Swal.fire('خطأ', 'لا يمكنك حذف حسابك الخاص', 'error');
        return;
      }

      Swal.fire({
        title: 'حذف المستخدم؟',
        text: 'هل أنت متأكد من حذف هذا المستخدم؟',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'نعم، احذف',
        cancelButtonText: 'إلغاء'
      }).then(r => {
        if (r.isConfirmed) {
          $.post('../includes/users_server.php', { delete_id: id }, function(response) {
            const res = JSON.parse(response);
            if (res.success) {
              table.ajax.reload(null, false);
              Swal.fire('تم', 'تم حذف المستخدم', 'success');
            } else {
              Swal.fire('خطأ', res.message, 'error');
            }
          });
        }
      });
    });

    // Reset Password
    $(document).on('click', '.reset-password-btn', function() {
      const id = $(this).data('id');
      $('#reset_id').val(id);
      $('#new_password').val('');
      $('#new_password_confirm').val('');
      new bootstrap.Modal('#resetPasswordModal').show();
    });

    $('#resetPasswordForm').on('submit', function(e) {
      e.preventDefault();
      const password = $('#new_password').val();
      const confirm = $('#new_password_confirm').val();

      if (password !== confirm) {
        Swal.fire('خطأ', 'كلمات المرور غير متطابقة', 'error');
        return;
      }

      $.post('../includes/users_server.php', $(this).serialize() + '&reset_password=1', function(response) {
        const res = JSON.parse(response);
        if (res.success) {
          bootstrap.Modal.getInstance('#resetPasswordModal').hide();
          Swal.fire('تم', 'تم إعادة تعيين كلمة المرور بنجاح', 'success');
        } else {
          Swal.fire('خطأ', res.message, 'error');
        }
      });
    });
  });
  </script>
</body>
</html>
