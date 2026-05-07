<?php
include('../check.php');
 include("../includes/db.php");

if (isset($_GET['app_id']) && !empty($_GET['app_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM pilgrim WHERE app_id = ?");
    $stmt->execute([$_GET['app_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif (isset($_GET['id']) && !empty($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM pilgrim WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    echo "❌ لا يوجد حاج لعرضه.";
    exit;
}

if (!$row) {
    echo "❌ لم يتم العثور على الحاج.";
    exit;
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <title>تفاصيل الحاج</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body{background:#f2f4f6;font-family:'Tajawal',sans-serif;}
        .profile-card{border-radius:16px;overflow:hidden;box-shadow:0 6px 12px rgba(0,0,0,.08);background:#fff;margin-top:25px;}
        .profile-header{background:linear-gradient(135deg,#0d6efd,#6610f2);color:#fff;padding:35px 20px;text-align:center;}
        .profile-body{padding:25px;}
        .info-line{display:flex;align-items:center;margin-bottom:12px;border-bottom:1px dashed #e9ecef;padding:8px 0;}
        .info-label{flex:0 0 40%;font-weight:700;color:#495057;text-align:right;}
        .info-value{flex:0 0 60%;color:#0d6efd;text-align:left;word-break:break-word;}
        #imgModalSrc{max-width:100%;max-height:70vh;object-fit:contain;}

        @media (max-width:576px){.info-label{margin-bottom:4px;text-align:right;}.info-value{text-align:left;}}
        .btn-show-group{width:100%;margin-top:20px;}
    </style>
</head>
<body class="container">

    <div class="profile-card">
        <div class="profile-header">
            <h2><i class="bi bi-person-circle me-1"></i>تفاصيل الحاج</h2>
        </div>
        <div class="profile-body">
            <?php
            $fields = [
                'name'=>'الاسم',
                'national'=>'الرقم الوطني',
                'group'=>'المجموعة',
                'barcode'=>'الباركود',
                'phone'=>'رقم الجوال',
                'passport'=>'جواز السفر',
                'visa'=>'الفيزا',
                'app_id'=>'معرف الحاج'
            ];
            foreach ($fields as $k=>$label) {
                echo '<div class="info-line"><span class="info-label">'.$label.':</span>';
                if (in_array($k, ['passport','visa']) && !empty($row[$k])) {
                    echo '<span class="info-value"><a href="#" class="view-img link-primary" data-src="'.$row[$k].'"><i class="bi bi-image"></i> عرض الصورة</a></span></div>';
                } else {
                    echo '<span class="info-value">'.($row[$k] ?? '').'</span></div>';
                }
            }
            ?>
            <button class="btn btn-outline-primary btn-show-group" onclick="showGroup()"><i class="bi bi-geo-alt"></i> عرض تفاصيل المجموعة</button>
        </div>
    </div>

    <!-- Lightbox Modal -->
    <div class="modal fade" id="imgModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-transparent border-0">
          <button type="button" class="btn-close ms-auto me-n1 mt-n1" data-bs-dismiss="modal"></button>
          <img id="imgModalSrc" src="" class="w-100 rounded shadow" />
        </div>
      </div>
    </div>

<script>
function showGroup(){
    fetch('group_info.php?group=<?= $row['group'] ?>')
        .then(res=>res.json())
        .then(data=>{
            const labels={
                group:'اسم المجموعة',master_group:'التكتل',group_phone:'رقم المجموعة',
                mecca_hotel:'فندق مكة',mecca_location:'موقع فندق مكة',
                medina_hotel:'فندق المدينة',medina_location:'موقع فندق المدينة',
                mutawwef:'رقم المطوف',mutawwef_location:'موقع المطوف',
                mina:'رقم مخيم منى',mina_location:'موقع مخيم منى',
                arafa:'رقم مخيم عرفات',arafa_location:'موقع مخيم عرفات'
            };
            const locFields=['mecca_location','medina_location','mutawwef_location','mina_location','arafa_location'];
            let html='<div style="text-align:right;font-family:Tajawal,sans-serif;">';
            for(const k in data){
                if(!data[k]) continue;
                const label=labels[k]||k;
                if(locFields.includes(k)){
                    html += `<div class="info-line" style="border:none;"><span class="info-label">${label}:</span><span class="info-value"><a href="${data[k]}" target="_blank" class="link-primary">عرض الموقع <i class="bi bi-geo"></i></a></span></div>`;
                }else{
                    html += `<div class="info-line" style="border:none;"><span class="info-label">${label}:</span><span class="info-value">${data[k]}</span></div>`;
                }
            }
            html+='</div>';
            Swal.fire({title:'تفاصيل المجموعة',html,width:'95%',customClass:{popup:'rounded-4 shadow'},confirmButtonText:'إغلاق'});
            Swal.update({html:html});
        });
}

// Lightbox
$(document).on('click','.view-img',function(e){
    e.preventDefault();
    const src=$(this).data('src');
    $('#imgModalSrc').attr('src',src);
    new bootstrap.Modal('#imgModal').show();
});
</script>
</body>
</html>
