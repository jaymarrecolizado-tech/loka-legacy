<?php
if (!canAccessSystemControl() && !isAdmin()) redirectWith('/?page=dashboard','danger','Access denied.');
$pageTitle='Add Gas Station';
$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    requireCsrf();
    $name=trim(post('name','')); $address=trim(post('address','')); $contact=trim(post('contact','')); $status=post('status','active')==='inactive'?'inactive':'active';
    if($name==='') $errors[]='Name is required.';
    elseif(mb_strlen($name)>150) $errors[]='Name must be 150 chars or fewer.';
    if(empty($errors)){
        try{
            db()->insert('gas_stations',['name'=>$name,'address'=>$address?:null,'contact'=>$contact?:null,'status'=>$status,'created_at'=>date(DATETIME_FORMAT)]);
            auditLog('gas_station_created','gas_station',db()->fetchColumn("SELECT id FROM gas_stations WHERE name=?",[$name]));
            redirectWith('/?page=gas-stations','success','Station added.');
        }catch(Exception $e){
            if(strpos($e->getMessage(),'Duplicate')!==false) $errors[]='A station with that name already exists.';
            else $errors[]=$e->getMessage();
        }
    }
}
require_once INCLUDES_PATH . '/header.php';
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h4 class="mb-1">Add Gas Station</h4><nav aria-label="breadcrumb"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=gas-stations">Gas Stations</a></li><li class="breadcrumb-item active">Add</li></ol></nav></div>
        <a href="<?= APP_URL ?>/?page=gas-stations" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>
    <?php if($errors): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <div class="row justify-content-center"><div class="col-lg-8"><div class="card"><div class="card-body p-4">
        <form method="POST">
            <?= csrfField() ?>
            <div class="mb-3"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" maxlength="150" required value="<?= e(post('name','')) ?>" placeholder="Queensforth Corporation"></div>
            <div class="mb-3"><label class="form-label">Address</label><input type="text" name="address" class="form-control" maxlength="255" value="<?= e(post('address','')) ?>"></div>
            <div class="mb-3"><label class="form-label">Contact</label><input type="text" name="contact" class="form-control" maxlength="100" value="<?= e(post('contact','')) ?>"></div>
            <div class="mb-3"><label class="form-label">Status</label><select name="status" class="form-select"><option value="active" selected>Active</option><option value="inactive">Inactive</option></select><small class="text-muted">Inactive stations won't appear when creating new vouchers.</small></div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button>
        </form>
    </div></div></div></div>
</div>
<?php require_once INCLUDES_PATH . '/footer.php'; ?>
