<?php
defined('MOODLE_INTERNAL') || die();

$daftar_tahun = \local_obe\manager\tahun_akademik_manager::ambil_semua();
$tahun_aktif_id = $SESSION->obe_id_tahun_akademik ?? 0;
$params=[];
foreach (['halaman','sub','prodi','prodiid','filter_prodi','angkatan','angkatanid','matakuliah','matakuliahid','id','id_course','userid','sub_cpmk_id','cpmk_id','indikator_id','cpl_id'] as $key) {
    $value=optional_param($key,'',in_array($key,['halaman','sub'])?PARAM_ALPHAEXT:PARAM_INT);
    if ($value!=='') {$params[$key]=$value;}
}
$returnto=new moodle_url('/local/obe/views/admin/dashboard.php',$params);
$yearrecord=$tahun_aktif_id?$DB->get_record('local_obe_tahun_akademik',['id'=>$tahun_aktif_id]):null;
$PAGE->requires->js(new moodle_url('/local/obe/year-context.js',['v'=>substr(hash_file('sha256',$CFG->dirroot.'/local/obe/year-context.js'),0,12)]));
?>

<div id="obe-year-context" data-year="<?= (int)$tahun_aktif_id ?>" class="card mb-4 shadow-sm border-0">
    <div class="card-body py-3 d-flex justify-content-between align-items-center">
        <div>
            <span class="text-muted small text-uppercase font-weight-bold">Tahun Akademik</span>
            <h6 class="text-primary font-weight-bold mb-0 mt-1">
                <?php if ($tahun_aktif_id == 0): ?>
                    Pilih Tahun Akademik 
                <?php else: ?>
                    <?= s($yearrecord->nama_tahun ?? 'Tahun belum tersedia') ?>
                <?php endif; ?>
            </h6>
        </div>
        
        <div>
            <form action="<?= new moodle_url('/local/obe/views/admin/tahun_akademik/set_tahun.php') ?>" method="get" class="form-inline m-0">
                <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                
                <input type="hidden" name="returnto" value="<?= s($returnto->out(false)) ?>"><label for="obe-year" class="sr-only">Tahun akademik yang ditampilkan</label><select id="obe-year" name="id_tahun" class="form-control mr-2">
                    <option value="0">-- Pilih Tahun Akademik --</option>
                    <?php foreach ($daftar_tahun as $t): ?>
                        <option value="<?= $t->id ?>" <?= ($tahun_aktif_id == $t->id) ? 'selected' : '' ?>>
                            <?= s($t->nama_tahun) ?>
                        </option>
                    <?php endforeach; ?>
                </select><button class="btn btn-outline-primary" type="submit">Terapkan tahun</button>
                
                
            </form>
        </div>
    </div>
</div>