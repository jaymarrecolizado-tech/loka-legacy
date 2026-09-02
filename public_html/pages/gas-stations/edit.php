<?php
if (!canAccessSystemControl() && !isAdmin()) redirectWith('/?page=dashboard','danger','Access denied.');
$id=(int)get('id',0);
$row=db()->fetch("SELECT * FROM gas_stations WHERE id=? AND deleted_at IS NULL",[$id]);
if(!$row) redirectWith('/?page=gas-stations','danger','Station not found.');
$pageTitle='Edit Gas Station';
$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    requireCsrf();
    $name=trim(post('name','')); $address=trim(post('address','')); $contact=trim(post('contact','')); $status=post('status','active')==='inactive'?'inactive':'active';
    if($name==='') $errors[]='Name is required.';
    elseif(mb_strlen($name)>150) $errors[]='Name must be 150 chars or fewer.';
    if(empty($errors)){
        try{
            db()->update('gas_stations',['name'=>$name,'address'=>$address?:null,'contact'=>$contact?:null,'status'=>$status,'updated_at'=>date(DATETIME_FORMAT)],'id=?',[$id]);
            auditLog('gas_station_updated','gas_station',$id,$row->name,$name);
            redirectWith('/?page=gas-stations','success','Station updated.');
        }catch(Exception $e){
            if(strpos($e->getMessage(),'Duplicate')!==false) $errors[]='Duplicate name.';
            else $errors[]=$e->getMessage();
        }
    }
}
require_once INCLUDES_PATH . '/header.php';
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h4 class="mb-1">Edit Gas Station</h4><nav aria-label="breadcrumb"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=gas-stations">Gas Stations</a></li><li class="breadcrumb-item active">Edit</li></ol></nav></div>
        <a href="<?= APP_URL ?>/?page=gas-stations" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>
    <?php if($errors): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <div class="row justify-content-center"><div class="col-lg-8"><div class="card"><div class="card-body p-4">
        <form method="POST">
            <?= csrfField() ?>
            <div class="mb-3"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" maxlength="150" required value="<?= e(post('name',$row->name)) ?>"></div>
            <div class="mb-3"><label class="form-label">Address</label><input type="text" name="address" class="form-control" maxlength="255" value="<?= e(post('address',$row->address)) ?>"></div>
            <div class="mb-3"><label class="form-label">Contact</label><input type="text" name="contact" class="form-control" maxlength="100" value="<?= e(post('contact',$row->contact)) ?>"></div>
            <div class="mb-3"><label class="form-label">Status</label><select name="status" class="form-select"><option value="active" <?= post('status',$row->status)==='active'?'selected':'' ?>>Active</option><option value="inactive" <?= post('status',$row->status)==='inactive'?'selected':'' ?>>Inactive</option></select></div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button>
        </form>
    </div></div></div></div>
</div>
<?php require_once INCLUDES_PATH . '/footer.php'; ?>
