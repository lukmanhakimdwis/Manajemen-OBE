<?php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\prodiframework_manager;
use local_obe\manager\prodi_manager;
use local_obe\manager\tahun_akademik_manager;

// Menangkap konteks prodi dari halaman list (Sekarang konsisten menggunakan filter_prodi)
$filter_prodi = optional_param('filter_prodi', 0, PARAM_INT); 

// Identifikasi peran pengguna aktif (Kaprodi / Admin)
$prodi_aktif = prodi_manager::get_prodi_aktif_user();

// Kondisikan data master prodi dan nilai default berdasarkan hak akses
if ($prodi_aktif) {
    $prodis = [$prodi_aktif];
    $id_prodi_default = (int)$prodi_aktif->id;
} else {
    $prodis = prodi_manager::ambil_semua_prodi();
    $id_prodi_default = $filter_prodi;
}

$frameworks = prodiframework_manager::ambil_semua_framework();
$list_tahun = tahun_akademik_manager::ambil_semua();
?>

<div class="card border-0 shadow-sm rounded-lg mt-3">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0">
        <h5 class="font-weight-bold text-primary">
            <i class="fa fa-sitemap mr-2"></i>Hubungkan kerangka kompetensi
        </h5>
        <p class="text-muted small mb-0">Hubungkan Program Studi dengan kerangka kompetensi Moodle</p>
    </div>
    <div class="card-body p-4">
        <form action="<?= new moodle_url('/local/obe/views/admin/framework/proses_framework.php', ['aksi' => 'simpan']) ?>" method="post">
            <input type="hidden" name="sesskey" value="<?= sesskey(); ?>">

            <div class="form-group mb-3">
                <label class="font-weight-bold">Tahun Akademik <span class="text-danger">*</span></label>
                <select name="id_tahun_akademik" class="form-control" required>
                    <option value="">-- Pilih Tahun Akademik --</option>
                    <?php foreach ($list_tahun as $t): ?>
                        <option value="<?= $t->id ?>">
                            <?= s($t->nama_tahun) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group mb-3">
                <label class="font-weight-bold">Program Studi <span class="text-danger">*</span></label>
                
                <?php if ($prodi_aktif): ?>
                    <input type="hidden" name="id_prodi" value="<?= $id_prodi_default ?>">
                    <input type="text" class="form-control bg-light font-weight-bold" value="<?= s($prodi_aktif->nama_prodi) ?>" readonly>
                <?php else: ?>
                    <select name="id_prodi" class="form-control" required>
                        <option value="">-- Pilih Program Studi --</option>
                        <?php foreach ($prodis as $p): ?>
                            <option value="<?= $p->id ?>" <?= ($id_prodi_default == $p->id) ? 'selected' : '' ?>>
                                <?= s($p->nama_prodi) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <div class="form-group mb-4">
                <label class="font-weight-bold">Kerangka kompetensi <span class="text-danger">*</span></label>
                <select name="frameworkid" class="form-control" required>
                    <option value="">-- Pilih Framework --</option>
                    <?php foreach ($frameworks as $f): ?>
                        <option value="<?= $f->id ?>">
                            <?= s($f->shortname) ?> (ID: <?= s($f->idnumber) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-info mt-1 d-block">
                    <i class="fa fa-info-circle mr-1"></i> <?php if (has_capability('moodle/competency:competencymanage',context_system::instance())): ?>
                    <?= html_writer::link(new moodle_url('/admin/tool/lp/competencyframeworks.php',['pagecontextid'=>context_system::instance()->id]),'Kelola kerangka kompetensi di Moodle') ?>
                    <?php else: ?>Minta administrator menyiapkan kerangka kompetensi untuk prodi dan tahun ini.<?php endif; ?>
                </small>
            </div>

            <div class="d-flex justify-content-end pt-3 border-top">
                <a href="?halaman=kelola_framework<?= $id_prodi_default > 0 ? '&filter_prodi=' . $id_prodi_default : '' ?>" class="btn btn-light px-4 mr-2">Batal</a>
                <button type="submit" class="btn btn-primary px-4 shadow-sm font-weight-bold">
                    <i class="fa fa-save mr-1"></i> Simpan hubungan
                </button>
            </div>
        </form>
    </div>
</div>