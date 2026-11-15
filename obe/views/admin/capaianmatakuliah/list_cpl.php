<?php
// File: local/obe/views/admin/capaianmatakuliah/list_cpl.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\kompetensi_manager;
use local_obe\manager\capaian_matakuliah_manager;
use local_obe\manager\prodi_manager;

global $DB, $SESSION;

// ========================================
// 1. PARAMETER FILTER (TERMASUK ANGKATAN)
// ========================================
$prodi_id       = optional_param('prodi', 0, PARAM_INT);
$matkul_id      = optional_param('matkul', 0, PARAM_INT);
$angkatan_id    = optional_param('angkatan', 0, PARAM_INT); 
$tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;

// =========================================================================
// 2. IDENTIFIKASI PERAN & PROTEKSI SERVER (ANTI-IDOR PARAMETER SHIELD)
// =========================================================================
$prodi_aktif = prodi_manager::get_prodi_aktif_user();

if ($prodi_aktif) {
    // Jika Kaprodi, paksa kunci ID prodi miliknya sendiri (Aman dari manipulasi URL)
    $prodi_id = (int)$prodi_aktif->id;
    $daftar_prodi = [$prodi_aktif->id => $prodi_aktif];
} else {
    // Jika Admin/Kajur, ijinkan memuat seluruh master prodi
    $daftar_prodi = kompetensi_manager::ambil_daftar_prodi();
}

// =========================================================================
// VALIDASI INTEGRITAS DATA CROSS-PRODI (Mencegah Kebocoran Filter Angkatan & MK)
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

// ========================================
// 3. DATA DROPDOWN FILTER
// ========================================
// Filter daftar angkatan & matkul agar menyusut otomatis mengikuti prodi pilihan
$kondisi_filter = ($prodi_id > 0) ? ['id_prodi' => $prodi_id] : null;

$daftar_angkatan = $DB->get_records('local_obe_angkatan', $kondisi_filter, 'nama_angkatan DESC', 'id, nama_angkatan');
$daftar_matkul   = $DB->get_records('local_obe_mata_kuliah', $kondisi_filter, 'nama_mk ASC', 'id, nama_mk');

// ========================================
// 4. DATA CPL MATA KULIAH (TERFILTER ANGKATAN)
// ========================================
$cpl_data = [];
if ($matkul_id > 0) {
    $cpl_data = capaian_matakuliah_manager::ambil_capaian_cpl_matakuliah($matkul_id, $tahun_akademik, $angkatan_id);
}

// Formatter dinamis untuk menghilangkan trailing zeros pada pecahan desimal
$format_nilai = function($nilai, $desimal = 2) {
    if (!is_numeric($nilai)) {
        return $nilai;
    }
    return (floor($nilai) == $nilai)
        ? (int)$nilai
        : rtrim(rtrim(number_format($nilai, $desimal, '.', ''), '0'), '.');
};
?>

<div class="card mb-4 shadow-sm border-0">
    <div class="card-header bg-white py-3 border-bottom">
        <h5 class="mb-0 font-weight-bold text-dark">
            <i class="fa fa-filter text-primary mr-2"></i>Parameter Analisis Capaian
        </h5>
    </div>
    <div class="card-body">
        <form method="get" class="row m-0 align-items-end">
            <input type="hidden" name="halaman" value="capaian_pembelajaran_matakuliah">
            <input type="hidden" name="sub" value="cpl">
            
            <?php if (!$prodi_aktif): ?>
            <div class="col-md-3 mb-2">
                <label class="form-label font-weight-bold text-dark small">Program Studi</label>
                <select name="prodi" id="select-prodi" class="form-control form-control-sm custom-select" onchange="this.form.submit()">
                    <option value="0">-- Semua Prodi --</option>
                    <?php foreach ($daftar_prodi as $p): ?>
                        <option value="<?= $p->id ?>" <?= ($prodi_id == $p->id) ? 'selected' : '' ?>>
                            <?= s($p->nama_prodi) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="<?= !$prodi_aktif ? 'col-md-3' : 'col-md-4' ?> mb-2">
                <label class="form-label font-weight-bold text-dark small">Angkatan</label>
                <select name="angkatan" id="select-angkatan" class="form-control form-control-sm custom-select" onchange="this.form.submit()">
                    <option value="0">-- Semua Angkatan --</option>
                    <?php foreach ($daftar_angkatan as $a): ?>
                        <option value="<?= $a->id ?>" <?= ($angkatan_id == $a->id) ? 'selected' : '' ?>>
                            <?= s($a->nama_angkatan) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="<?= !$prodi_aktif ? 'col-md-3' : 'col-md-5' ?> mb-2">
                <label class="form-label font-weight-bold text-dark small">Mata Kuliah</label>
                <select name="matkul" id="select-matkul" class="form-control form-control-sm custom-select" onchange="this.form.submit()">
                    <option value="0">-- Semua Mata Kuliah --</option>
                    <?php foreach ($daftar_matkul as $m): ?>
                        <option value="<?= $m->id ?>" <?= ($matkul_id == $m->id) ? 'selected' : '' ?>>
                            <?= s($m->nama_mk) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3 mb-2 text-right">
                <?php if ($matkul_id > 0): ?>
                    <?php
                    $urlgrafik = new moodle_url('/local/obe/views/admin/dashboard.php', [
                        'halaman'  => 'capaian_pembelajaran_matakuliah',
                        'sub'      => 'grafik_cpl',
                        'prodi'    => $prodi_id,
                        'matkul'   => $matkul_id,
                        'angkatan' => $angkatan_id,
                        'sesskey'  => sesskey()
                    ]);

                    $urlexport = new moodle_url('/local/obe/export/export_capaian_matakuliah_csv.php', [
                        'matkul_id'         => $matkul_id,
                        'angkatan_id'       => $angkatan_id,
                        'id_tahun_akademik' => $tahun_akademik,
                        'sesskey'           => sesskey()
                    ]);
                    ?>
                    <div class="d-flex justify-content-end align-items-center" style="gap: 6px;">
                        <a href="<?= $urlgrafik->out() ?>" class="btn btn-primary btn-sm font-weight-bold shadow-sm px-3" style="height: 31px; display: inline-flex; align-items: center; justify-content: center;">
                            <i class=""></i> Grafik CPL
                        </a>
                        <a href="<?= $urlexport->out() ?>" class="btn btn-success btn-sm font-weight-bold shadow-sm px-3" style="height: 31px; display: inline-flex; align-items: center; justify-content: center;">
                            <i class="fa fa-download mr-1"></i> Export 
                        </a>
                    </div>
                <?php else: ?>
                    <span class="badge badge-secondary font-weight-bold px-3 py-2 d-inline-block text-center" style="font-size: 0.75rem; min-height: 31px; line-height: 15px;">Pilih Matkul Aktif</span>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($matkul_id == 0): ?>
    <div class="alert alert-info py-4 text-center border-0 shadow-sm" style="border-radius: 8px;">
        <i class="fa fa-info-circle fa-2x text-info mb-2 d-block"></i>
        <h5 class="font-weight-bold text-dark mb-1">Silakan Pilih Mata Kuliah Terlebih Dahulu</h5>
        <p class="text-muted mb-0 small">Gunakan dropdown filter di atas untuk memuat data laporan Capaian Pembelajaran Lulusan (CPL) aktif.</p>
    </div>
<?php else: ?>
    <div class="card shadow-sm mb-4 border-0">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0 font-weight-bold text-dark">
                <i class="fa fa-book text-primary mr-2"></i>Capaian Pembelajaran Mata Kuliah
            </h5>
            
            <?php if ($prodi_aktif): ?>
                <span class="badge badge-light text-dark border px-2 py-1 font-weight-bold small">
                    <i class="fa fa-university text-muted mr-1"></i>PRODI: <?= s($prodi_aktif->nama_prodi) ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="table-responsive bg-white rounded">
            <table class="table table-hover align-middle mb-0">
                <thead class="thead-light">
                    <tr class="text-uppercase small" style="letter-spacing: 0.5px;">
                        <th class="py-3 pl-4" style="width: 200px;">Nama / Kode CPL</th>
                        <th class="text-left py-3">Deskripsi CPL</th>
                        <th class="text-center py-3" style="width: 220px;">Rata-Rata Capaian</th>
                        <th class="text-center py-3" style="width: 100px;">Grade</th>
                        <th class="text-center py-3" style="width: 260px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cpl_data)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-5 align-middle">
                                <i class="fa fa-ban fa-2x d-block mb-2 text-secondary"></i>
                                Belum ada data data capaian CPL mata kuliah yang terekam untuk kriteria ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($cpl_data as $cpl): ?>
                            <?php
                            $grade_tampil = !empty($cpl->grade) ? $cpl->grade : '-';
                            $badge_class = 'secondary';

                            switch (strtoupper(trim($grade_tampil))) {
                                case 'A': case 'AB': case 'SB': $badge_class = 'success'; break;
                                case 'B': case 'BC':             $badge_class = 'primary'; break;
                                case 'C':                         $badge_class = 'warning'; break;
                                case 'D': case 'E': case 'K':    $badge_class = 'danger'; break;
                            }

                            // Logika Fallback mengambil deskripsi CPL secara aman dari master database
                            $deskripsi_cpl = '-';
                            if (!empty($cpl->deskripsi)) {
                                $deskripsi_cpl = $cpl->deskripsi;
                            } else if (!empty($cpl->cpl_id)) {
                                $deskripsi_cpl = $DB->get_field('local_obe_kompetensi', 'deskripsi', [
                                    'id'   => $cpl->cpl_id, 
                                    'tipe' => 'CPL'
                                ]) ?: '-';
                            }
                            ?>
                            <tr>
                                <td class="pl-4 py-3 align-middle font-weight-bold text-dark" style="font-size: 0.95rem; white-space: nowrap;">
                                    <i class="fa fa-caret-right text-info mr-2"></i><?= s($cpl->nama_cpl) ?>
                                </td>
                                
                                <td class="text-left py-3 align-middle text-dark font-weight-normal" style="line-height: 1.5; min-width: 300px; font-size: 0.9rem;">
                                    <?= s(strip_tags($deskripsi_cpl)) ?>
                                </td>
                                
                                <td class="text-center py-3 align-middle font-weight-bold text-dark h5 mb-0">
                                    <?= $format_nilai($cpl->percentage) ?>
                                </td>
                                
                                <td class="text-center py-3 align-middle">
                                    <span class="badge badge-<?= $badge_class ?> px-3 py-2" style="font-size: 0.85rem; font-weight: 600; min-width: 45px;">
                                        <?= s($grade_tampil) ?>
                                    </span>
                                </td>
                                
                                <td class="text-center py-3 align-middle">
                                    <div class="d-flex justify-content-center align-items-center" style="gap: 6px; min-height: 38px;">

                                        <?php
                                        $url_lihat = new moodle_url('/local/obe/views/admin/dashboard.php', [
                                            'halaman'  => 'capaian_pembelajaran_matakuliah',
                                            'sub'      => 'indikator',
                                            'prodi'    => $prodi_id,
                                            'matkul'   => $matkul_id,
                                            'angkatan' => $angkatan_id,
                                            'cpl_id'   => $cpl->cpl_id,
                                            'sesskey'  => sesskey()
                                        ]);

                                        $url_detail = new moodle_url('/local/obe/views/admin/dashboard.php', [
                                            'halaman'       => 'capaian_pembelajaran_matakuliah',
                                            'sub'           => 'detail_nilai_cpl',
                                            'prodi'         => $prodi_id,
                                            'matkul'        => $matkul_id,
                                            'angkatan'      => $angkatan_id,
                                            'cpl_id'        => $cpl->cpl_id,
                                            'kompetensi_id' => $cpl->cpl_id,
                                            'tipe'          => 'CPL',
                                            'sesskey'       => sesskey()
                                        ]);
                                        ?>

                                        <a href="<?= $url_lihat->out() ?>"
                                        class="btn btn-sm btn-primary d-inline-flex align-items-center justify-content-center p-0 rounded-circle"
                                        style="width:32px; height:32px; flex-shrink: 0;"
                                        title="Rincian Indikator">
                                            <i class="fa fa-search" style="font-size:11px;"></i>
                                        </a>

                                        <a href="<?= $url_detail->out() ?>"
                                        class="btn btn-sm btn-info d-inline-flex align-items-center justify-content-center p-0 rounded-circle"
                                        style="width:32px; height:32px; flex-shrink: 0;"
                                        title="Detail Nilai Mahasiswa">
                                            <i class="fa fa-users" style="font-size:11px;"></i>
                                        </a>

                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>