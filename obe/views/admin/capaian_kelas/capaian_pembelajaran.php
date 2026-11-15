<?php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\capaian_kelas_manager;
use local_obe\manager\prodi_manager;

// Optimasi global variable yang bersih
global $DB, $SESSION, $PAGE;

// 1. Inisialisasi Parameter Filter Sederhana
$prodi_id       = optional_param('prodi', 0, PARAM_INT);
$angkatan_id    = optional_param('angkatan', 0, PARAM_INT);
$matkul_id      = optional_param('matkul', 0, PARAM_INT);
$tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;

// =========================================================================
// 2. IDENTIFIKASI PERAN & PROTEKSI SERVER (ANTI-IDOR DATA LOCK)
// =========================================================================
$prodi_aktif = prodi_manager::get_prodi_aktif_user();

if ($prodi_aktif) {
    $prodi_id = (int)$prodi_aktif->id;
    $daftar_prodi = [$prodi_aktif->id => $prodi_aktif];
} else {
    $daftar_prodi = prodi_manager::ambil_semua_prodi();
}

// =========================================================================
// 3. VALIDASI INTEGRITAS DATA CROSS-PRODI
// =========================================================================
if ($prodi_id > 0 && $angkatan_id) {
    $validangkatan = $DB->record_exists('local_obe_angkatan', [
        'id'        => $angkatan_id,
        'id_prodi'  => $prodi_id
    ]);
    if (!$validangkatan) {
        $angkatan_id = 0;
    }
}

if ($prodi_id > 0 && $matkul_id) {
    $validmatkul = $DB->record_exists('local_obe_mata_kuliah', [
        'id'        => $matkul_id,
        'id_prodi'  => $prodi_id
    ]);
    if (!$validmatkul) {
        $matkul_id = 0;
    }
}

// =========================================================================
// 4. AMBIL DATA DROPDOWN TERFILTER KONTEKS (Angkatan & Matkul)
// =========================================================================
$kondisi_angkatan = null;
$kondisi_matkul   = null;

if ($prodi_id > 0) {
    $kondisi_angkatan = ['id_prodi' => $prodi_id];
    $kondisi_matkul   = ['id_prodi' => $prodi_id];
}

$daftar_angkatan = $DB->get_records('local_obe_angkatan', $kondisi_angkatan, 'nama_angkatan DESC', 'id, nama_angkatan');
$daftar_matkul   = $DB->get_records('local_obe_mata_kuliah', $kondisi_matkul, 'nama_mk ASC', 'id, nama_mk');

// 5. Ambil Data Dasbor Utama
$daftar_kelas = capaian_kelas_manager::ambil_daftar_kelas($prodi_id, $matkul_id, $angkatan_id, $tahun_akademik);

// 6. Optimasi Memory Cache
$map_course   = $DB->get_records_menu('course', null, '', 'id, shortname');
$map_angkatan = $DB->get_records_menu('local_obe_angkatan', null, '', 'id, nama_angkatan');

// 🔄 Poin 5: Inisialisasi library Select2 bawaan Moodle/AMD Core
$PAGE->requires->js_call_amd('core/select', 'init', ['#select-matkul']);
?>

<div class="card mb-4 shadow-sm border-0">
    <div class="card-header bg-white py-3 border-bottom">
        <h5 class="mb-0 font-weight-bold text-dark">
            <i class="fa fa-filter text-primary mr-2"></i>Parameter Analisis Capaian
        </h5>
    </div>
    <div class="card-body">
        <form method="get" class="row m-0">
                <input type="hidden" name="halaman" value="capaian_pembelajaran_kelas">
                <?php if (!$prodi_aktif): ?>
                <div class="col-md-4 mb-3">
                    <label class="font-weight-bold text-dark small d-block mb-1">
                        Program Studi
                    </label>

                    <select name="prodi"
                            class="form-control form-control-sm custom-select"
                            onchange="this.form.submit()">

                        <option value="0">-- Semua Prodi --</option>

                        <?php foreach ($daftar_prodi as $p): ?>
                            <option value="<?= $p->id ?>"
                                <?= $prodi_id == $p->id ? 'selected' : '' ?>>
                                <?= s($p->nama_prodi) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="<?= !$prodi_aktif ? 'col-md-3' : 'col-md-4' ?> mb-3">
                    <label class="font-weight-bold text-dark small d-block mb-1">
                        Angkatan
                    </label>
                    <select name="angkatan"
                            class="form-control form-control-sm custom-select"
                            onchange="this.form.submit()">
                        <option value="0">-- Semua Angkatan --</option>
                        <?php foreach ($daftar_angkatan as $a): ?>
                            <option value="<?= $a->id ?>"
                                <?= $angkatan_id == $a->id ? 'selected' : '' ?>>
                                <?= s($a->nama_angkatan) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="<?= !$prodi_aktif ? 'col-md-5' : 'col-md-8' ?> mb-3">
                    <label class="font-weight-bold text-dark small d-block mb-1">
                        Mata Kuliah
                    </label>
                    <select name="matkul"
                            id="select-matkul"
                            class="form-control form-control-sm custom-select"
                            onchange="this.form.submit()">
                        <option value="0">-- Cari mata kuliah... --</option>
                        <?php foreach ($daftar_matkul as $m): ?>
                            <option value="<?= $m->id ?>"
                                <?= $matkul_id == $m->id ? 'selected' : '' ?>>
                                <?= s($m->nama_mk) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>
            </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="thead-light">
                <tr class="text-uppercase small" style="letter-spacing: 0.5px;">
                    <th class="py-3 pl-4">Mata Kuliah & Relasi Kelas Moodle</th>
                    <th class="text-center py-3" style="width: 140px;">Tindakan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($daftar_kelas)): ?>
                    <tr>
                        <td colspan="2" class="text-center text-muted py-5">
                            <i class="fa fa-inbox fa-2x d-block mb-2 text-secondary"></i>
                            Tidak ada data kelas yang terhubung dengan kriteria filter ini.
                        </td>
                    </tr>
                <?php else: foreach ($daftar_kelas as $d): ?>
                    <?php
                        $course_id = $d->id_kursus_moodle;
                        $course_name   = $map_course[$course_id] ?? 'Course Terhapus (' . $course_id . ')';
                        $nama_angkatan = $map_angkatan[$d->id_angkatan] ?? '-';
                        
                        $url_analisis = new moodle_url('/local/obe/views/admin/dashboard.php', [
                            'id'      => $course_id,
                            'halaman' => 'capaian_pembelajaran_kelas',
                            'sub'     => 'cpl'
                        ]);
                    ?>
                    <tr>
                        <td class="pl-4 py-3">
                            <div class="font-weight-bold text-dark mb-1" style="font-size: 0.95rem;">
                                <i class="fa fa-book text-primary mr-2"></i><?= s($d->nama_mk) ?>
                            </div>
                            <div class="small text-muted d-flex align-items-center">
                                <span class="mr-3"><i class="fa fa-graduation-cap mr-1"></i><?= s($course_name) ?></span>
                                <span><i class="fa fa-users mr-1"></i><?= s($nama_angkatan) ?></span>
                            </div>
                        </td>
                        <td class="text-center py-3">
                            <a href="<?= $url_analisis->out() ?>" class="btn btn-sm btn-outline-primary font-weight-bold shadow-sm" style="white-space: nowrap;">
                                <i class="fa fa-chart-bar mr-1"></i>Lihat Capaian Kelas
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>