<?php
// File: local/obe/views/admin/capaianmatakuliah/detail_indikator.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\capaian_kelas_manager;
use local_obe\manager\prodi_manager;
use local_obe\manager\kompetensi_manager;

global $COURSE, $DB, $SESSION;

// =====================================
// 1. MODE DETEKSI ROLE VIEW
// =====================================
$is_dosen_mode = (isset($mode_view) && $mode_view === 'dosen');

// =====================================
// 2. TANGKAP PARAMETER MASUKAN
// =====================================
$courseid = optional_param('id_course', optional_param('id', 0, PARAM_INT), PARAM_INT);
if ($courseid <= 0 && isset($COURSE)) {
    $courseid = $COURSE->id;
}

$kompetensi_id = optional_param('kompetensi_id', 0, PARAM_INT);
$cpl_id        = optional_param('cpl_id', 0, PARAM_INT); // Fallback parameter jika dikirim dari view sebelum
$tipe          = 'INDIKATOR';
$tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;

if (empty($kompetensi_id)) {
    throw new moodle_exception('invalidrecord', 'error', '', null, 'ID indikator tidak valid atau kosong.');
}

// Ambil data kompetensi/indikator di awal karena dibutuhkan untuk validasi IDOR dan penentuan CPL Parent
$kompetensi = kompetensi_manager::ambil_by_id($kompetensi_id);
if (!$kompetensi) {
    throw new moodle_exception('invalidrecord', 'error', '', null, 'Data indikator tidak ditemukan.');
}

// =========================================================================
// 3. VALIDASI KEAMANAN SERVER (ANTI-IDOR DATA SHIELD INTERCEPTOR)
// =========================================================================
$prodi_aktif = prodi_manager::get_prodi_aktif_user();

if ($prodi_aktif && !$is_dosen_mode) {
    if ((int)$kompetensi->id_prodi !== (int)$prodi_aktif->id) {
        throw new moodle_exception(
            'aksesditolak',
            'local_obe',
            '',
            null,
            'Aksi ditolak! Anda tidak memiliki hak akses untuk melihat data capaian indikator milik program studi lain.'
        );
    }
}

// =========================================================================
// 4. RETRIEVE DATA UTAMA & BOBOT RELASI SPECIFIC
// =========================================================================
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// UPDATE NYATA: Mengganti nested loop berat dengan direct query via manager relasi
$cpl_id_parent = $kompetensi->parent_id ?? $cpl_id;
$bobot_cpl = capaian_kelas_manager::ambil_bobot_relasi($cpl_id_parent, $kompetensi_id, $tahun_akademik) ?? 0;

// Penarikan Tunggal via Orchestrator Utama Statistik Mahasiswa
$hasil     = capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($courseid, $kompetensi_id, 'INDIKATOR', $tahun_akademik);
$ringkasan = $hasil->ringkasan;
$data      = $hasil->detail_mahasiswa;

$jumlah_belum_kompeten     = $ringkasan->jumlah_belum;
$persentase_kompeten       = $ringkasan->jumlah_mahasiswa > 0 ? round(($ringkasan->jumlah_kompeten / $ringkasan->jumlah_mahasiswa) * 100, 2) : 0;
$persentase_belum_kompeten = $ringkasan->jumlah_mahasiswa > 0 ? round(($jumlah_belum_kompeten / $ringkasan->jumlah_mahasiswa) * 100, 2) : 0;

// Formatter dinamis untuk menghilangkan trailing zeros pada pecahan desimal
$format_angka = function($nilai, $desimal = 2) {
    if (!is_numeric($nilai)) {
        return $nilai;
    }
    return (floor($nilai) == $nilai)
        ? (int)$nilai
        : rtrim(rtrim(number_format($nilai, $desimal, '.', ''), '0'), '.');
};

// =====================================
// 5. CONFIG URL KEMBALI DINAMIS
// =====================================
// Gunakan $cpl_id_parent agar navigasi balik tetap akurat ke CPL yang menaunginya
if ($is_dosen_mode) {
    $url_kembali = new moodle_url('/local/obe/views/dosen/dashboard.php', [
        'id_course' => $courseid,
        'halaman'   => 'laporan',
        'sub'       => 'indikator',
        'cpl_id'    => $cpl_id_parent
    ]);
} else {
    $url_kembali = new moodle_url('/local/obe/views/admin/dashboard.php', [
        'id_course' => $courseid,
        'halaman'   => 'capaian_pembelajaran_kelas',
        'sub'       => 'indikator',
        'cpl_id'    => $cpl_id_parent
    ]);
}
?>

<div class="card shadow-sm mb-4 border-0">
    <div class="card-header bg-white py-3">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0 font-weight-bold text-dark">
                <i class="fa fa-users text-primary mr-2"></i>Detail Nilai Indikator - <?= s($course->fullname) ?>
            </h5>
            <a href="<?= $url_kembali->out() ?>" class="btn btn-secondary btn-sm font-weight-bold shadow-sm">
                <i class="fa fa-arrow-left mr-1"></i> Kembali
            </a>
        </div>
    </div>

    <?php if (!empty($data)): ?>
        <div class="row px-3 pt-3 m-0">
            <div class="col-md-3 mb-3">
                <div class="card border-left-primary shadow-sm h-100 border-0">
                    <div class="card-body text-center py-4">
                        <h6 class="text-primary font-weight-bold text-uppercase small" style="letter-spacing: 0.5px;">Rata-Rata Kelas</h6>
                        <h2 class="font-weight-bold text-dark my-2"><?= $format_angka($ringkasan->rata_rata) ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card border-left-info shadow-sm h-100 border-0">
                    <div class="card-body text-center py-4">
                        <h6 class="text-info font-weight-bold text-uppercase small" style="letter-spacing: 0.5px;">Total Mahasiswa</h6>
                        <h2 class="font-weight-bold text-dark my-2"><?= $ringkasan->jumlah_mahasiswa ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card border-left-success shadow-sm h-100 border-0">
                    <div class="card-body text-center py-4">
                        <h6 class="text-success font-weight-bold text-uppercase small" style="letter-spacing: 0.5px;">Kompeten</h6>
                        <h2 class="font-weight-bold text-dark my-2"><?= $ringkasan->jumlah_kompeten ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card border-left-danger shadow-sm h-100 border-0">
                    <div class="card-body text-center py-4">
                        <h6 class="text-danger font-weight-bold text-uppercase small" style="letter-spacing: 0.5px;">Belum Kompeten</h6>
                        <h2 class="font-weight-bold text-dark my-2"><?= $jumlah_belum_kompeten ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mx-3 mb-4 border-0">
            <div class="card-header bg-light border-bottom font-weight-bold text-dark py-3">
                <i class="fa fa-chart-bar text-muted mr-2"></i>Distribusi Grade Mahasiswa
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="thead-light">
                        <tr class="text-center small text-uppercase" style="letter-spacing: 0.5px;">
                            <th class="text-left pl-4" style="width: 40%;">Grade</th>
                            <th style="width: 30%;">Jumlah Mahasiswa</th>
                            <th style="width: 30%;">Persentase</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($ringkasan->distribusi_grade)): ?>
                            <?php foreach ($ringkasan->distribusi_grade as $huruf => $jumlah): ?>
                                <?php
                                $badge_dist_class = 'secondary';
                                switch (strtoupper(trim($huruf))) {
                                    case 'A': case 'AB': case 'SB': $badge_dist_class = 'success'; break;
                                    case 'B': case 'BC':             $badge_dist_class = 'primary'; break;
                                    case 'C':                         $badge_dist_class = 'warning'; break;
                                    case 'D': case 'E': case 'K':    $badge_dist_class = 'danger'; break;
                                }
                                $persentase_item = ($ringkasan->jumlah_mahasiswa > 0) ? ($jumlah / $ringkasan->jumlah_mahasiswa) * 100 : 0;
                                ?>
                                <tr class="text-center">
                                    <td class="text-left pl-4 py-2">
                                        <span class="badge badge-<?= $badge_dist_class ?> px-3 py-1 font-weight-bold" style="min-width: 45px; font-size: 0.8rem;">
                                            <?= s($huruf) ?>
                                        </span>
                                    </td>
                                    <td class="py-2 font-weight-bold text-dark"><?= $jumlah ?> mhs</td>
                                    <td class="py-2 text-muted font-weight-bold"><?= $format_angka($persentase_item) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="text-center text-muted py-3">Belum ada distribusi grade.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="card-body p-0">
        <?php if (empty($data)): ?>
            <div class="alert alert-warning text-center m-3 border-0 shadow-sm">
                <i class="fa fa-exclamation-circle mr-1"></i> Belum ada data nilai mahasiswa untuk kelas indikator ini.
            </div>
        <?php else: ?>
            <div class="table-responsive bg-white rounded p-3">
                <table class="table table-hover align-middle mb-0">
                    <thead class="thead-light">
                        <tr class="text-uppercase small text-center" style="letter-spacing: 0.5px;">
                            <th width="35%" class="text-left pl-4 py-3">Mahasiswa</th>
                            <th width="15%" class="py-3">Nilai Indikator</th>
                            <th width="20%" class="py-3">Kontribusi CPL</th>
                            <th width="15%" class="py-3">Grade</th>
                            <th width="15%" class="py-3">Status OBE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data as $mhs): ?>
                            <?php
                            $kontribusi_cpl = $mhs->nilai * ($bobot_cpl / 100);

                            $badge_mhs_class = 'secondary';
                            switch (strtoupper(trim($mhs->grade))) {
                                case 'A': case 'AB': case 'SB': $badge_mhs_class = 'success'; break;
                                case 'B': case 'BC':             $badge_mhs_class = 'primary'; break;
                                case 'C':                         $badge_mhs_class = 'warning'; break;
                                case 'D': case 'E': case 'K':    $badge_mhs_class = 'danger'; break;
                            }
                            ?>
                            <tr class="text-center">
                                <td class="text-left pl-4 py-3">
                                    <div class="font-weight-bold text-dark mb-1" style="font-size: 0.9rem;"><?= s($mhs->nama) ?></div>
                                    <small class="text-muted">
                                        <i class="fa fa-id-card mr-1"></i><?= s($mhs->username ?? '') ?>
                                    </small>
                                </td>
                                <td class="py-3">
                                    <div class="h5 font-weight-bold text-primary mb-0"><?= $format_angka($mhs->nilai) ?></div>
                                </td>
                                <td class="py-3 text-center">
                                    <div class="h5 font-weight-bold text-info mb-1">
                                        <?= $format_angka($kontribusi_cpl) ?>
                                    </div>
                                    
                                </td>
                                <td class="py-3">
                                    <span class="badge badge-<?= $badge_mhs_class ?> px-3 py-2" style="min-width: 45px; font-size: 0.85rem; font-weight: 600;">
                                        <?= s($mhs->grade) ?>
                                    </span>
                                </td>
                                <td class="py-3">
                                    <?php if ($mhs->kompeten): ?>
                                        <span class="badge badge-success px-3 py-2 font-weight-bold shadow-sm" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                                            <i class="fa fa-check-circle mr-1"></i> Kompeten
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-danger px-3 py-2 font-weight-bold shadow-sm" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                                            <i class="fa fa-times-circle mr-1"></i> Belum Kompeten
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>