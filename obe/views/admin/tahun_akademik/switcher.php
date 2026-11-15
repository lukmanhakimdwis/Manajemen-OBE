<?php
defined('MOODLE_INTERNAL') || die();

$daftar_tahun = \local_obe\manager\tahun_akademik_manager::ambil_semua();
$tahun_aktif_id = $SESSION->obe_id_tahun_akademik ?? 0;
?>

<div class="card mb-4 shadow-sm border-0">
    <div class="card-body py-3 d-flex justify-content-between align-items-center">
        <div>
            <span class="text-muted small text-uppercase font-weight-bold">Tahun Akademik</span>
            <h6 class="text-primary font-weight-bold mb-0 mt-1">
                <?php if ($tahun_aktif_id == 0): ?>
                    Pilih Tahun Akademik 
                <?php else: ?>
                    <?= s($SESSION->obe_tahun_akademik ?? 'Tidak Diketahui') ?>
                <?php endif; ?>
            </h6>
        </div>
        
        <div>
            <form action="<?= new moodle_url('/local/obe/views/admin/tahun_akademik/set_tahun.php') ?>" method="get" class="form-inline m-0">
                <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                
                <select name="id_tahun" class="form-control mr-2" onchange="this.form.submit()">
                    <option value="0">-- Pilih Tahun Akademik --</option>
                    <?php foreach ($daftar_tahun as $t): ?>
                        <option value="<?= $t->id ?>" <?= ($tahun_aktif_id == $t->id) ? 'selected' : '' ?>>
                            <?= s($t->nama_tahun) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                
            </form>
        </div>
    </div>
</div>