<?php
// File: local/obe/views/admin/capaianmatakuliah/detail_nilai_subcpmk.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\capaian_matakuliah_manager;
use local_obe\manager\prodi_manager;
use local_obe\manager\kompetensi_manager;
use local_obe\manager\matakuliah_manager;

global $DB, $SESSION;

// =====================================
// 1. TANGKAP PARAMETER MASUKAN
// =====================================
$prodi_id          = optional_param('prodi', 0, PARAM_INT);
$matkul_id         = optional_param('matkul', 0, PARAM_INT);
$angkatan_id       = optional_param('angkatan', 0, PARAM_INT);
$cpl_id            = optional_param('cpl_id', 0, PARAM_INT);
$indikator_id      = optional_param('indikator_id', 0, PARAM_INT);
$cpmk_id           = optional_param('cpmk_id', 0, PARAM_INT);
$kompetensi_id     = optional_param('kompetensi_id', 0, PARAM_INT); // ID Sub-CPMK target
$tipe              = 'SUB-CPMK';
$id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;

if (empty($kompetensi_id)) {
    throw new moodle_exception('invalidrecord', 'error', '', null, 'ID kompetensi Sub-CPMK tidak valid atau kosong.');
}

if (empty($matkul_id)) {
    throw new moodle_exception('invalidrecord', 'error', '', null, 'ID mata kuliah tidak valid atau kosong.');
}

// =========================================================================
// 2. VALIDASI KEAMANAN SERVER (DOUBLE-LAYER ANTI-IDOR PROTECTION)
// =========================================================================
$prodi_aktif = prodi_manager::get_prodi_aktif_user();

if ($prodi_aktif) {
    // LAPIS 1: Validasi Otoritas Lintas Mata Kuliah Prodi
    $matkul = matakuliah_manager::ambil_by_id($matkul_id);
    if (!$matkul || (int)$matkul->id_prodi !== (int)$prodi_aktif->id) {
        throw new \moodle_exception(
            'aksesditolak',
            'local_obe',
            '',
            null,
            'Aksi ditolak! Anda tidak memiliki hak akses untuk meninjau mata kuliah dari program studi lain.'
        );
    }

    // LAPIS 2: Validasi Otoritas Lintas Aset Target Sub-CPMK Kurikulum
    $kompetensi = kompetensi_manager::ambil_by_id($kompetensi_id);

    if (!$kompetensi) {
        throw new \moodle_exception('invalidrecord', 'error');
    }

    if ((int)$kompetensi->id_prodi !== (int)$prodi_aktif->id) {
        throw new \moodle_exception(
            'aksesditolak',
            'local_obe',
            '',
            null,
            'Aksi ditolak! Anda tidak memiliki hak otoritas untuk meninjau struktur data Sub-CPMK milik program studi lain.'
        );
    }
} else {
    $matkul = matakuliah_manager::ambil_by_id($matkul_id);
}

if (!$matkul) {
    throw new moodle_exception('invalidrecord', 'error', '', null, 'Mata kuliah tidak ditemukan.');
}

// =========================================================================
// 3. AMBIL DATA UTAMA & STATISTIK MATAKULIAH (TIPE: SUB-CPMK)
// =========================================================================
$data = capaian_matakuliah_manager::ambil_detail_kompetensi_mahasiswa_matakuliah(
    $matkul_id,
    $kompetensi_id,
    'SUB-CPMK',
    $id_tahun_akademik,
    $angkatan_id
);

$statistik = capaian_matakuliah_manager::ambil_statistik_skala_matakuliah(
    $matkul_id,
    $kompetensi_id,
    'SUB-CPMK',
    $id_tahun_akademik,
    $angkatan_id
);

// Kalkulasi rata-rata nilai Sub-CPMK secara dinamis jika properti dari manager kosong
$rata_rata_subcpmk = $statistik->rata_rata ?? 0;
if (empty($rata_rata_subcpmk) && !empty($data->detail_mahasiswa)) {
    $daftar_nilai = array_map(function($mhs) {
        return $mhs->nilai;
    }, $data->detail_mahasiswa);
    $total_mhs = count($daftar_nilai);
    if ($total_mhs > 0) {
        $rata_rata_subcpmk = array_sum($daftar_nilai) / $total_mhs;
    }
}

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
// 4. URL KEMBALI DINAMIS (KEMBALI KE LIST SUB-CPMK)
// =====================================
$url_kembali = new moodle_url('/local/obe/views/admin/dashboard.php', [
    'halaman'      => 'capaian_pembelajaran_matakuliah',
    'sub'          => 'subcpmk',
    'prodi'        => $prodi_id,
    'matkul'       => $matkul_id,
    'angkatan'     => $angkatan_id,
    'cpl_id'       => $cpl_id,
    'indikator_id' => $indikator_id,
    'cpmk_id'      => $cpmk_id
]);
?>

<div class="card shadow-sm mb-4 border-0">
    <div class="card-header bg-white py-3">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0 font-weight-bold text-dark">
                <i class="fa fa-users text-primary mr-2"></i>Detail Nilai Sub-CPMK - <?= s($matkul->nama_mk) ?>
            </h5>
            <a href="<?= $url_kembali->out() ?>" class="btn btn-secondary btn-sm font-weight-bold shadow-sm">
                <i class="fa fa-arrow-left mr-1"></i> Kembali
            </a>
        </div>
    </div>

    <?php if (!empty($statistik)): ?>
        <div class="row px-3 pt-3 m-0">
            <div class="col-md-3 mb-3">
                <div class="card border-left-primary shadow-sm h-100 border-0">
                    <div class="card-body text-center py-4">
                        <h6 class="text-primary font-weight-bold text-uppercase small" style="letter-spacing: 0.5px;">Rata-Rata Sub-CPMK</h6>
                        <h2 class="font-weight-bold text-dark my-2"><?= $format_angka($rata_rata_subcpmk) ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card border-left-info shadow-sm h-100 border-0">
                    <div class="card-body text-center py-4">
                        <h6 class="text-info font-weight-bold text-uppercase small" style="letter-spacing: 0.5px;">Total Mahasiswa</h6>
                        <h2 class="font-weight-bold text-dark my-2"><?= $statistik->total_mahasiswa ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card border-left-success shadow-sm h-100 border-0">
                    <div class="card-body text-center py-4">
                        <h6 class="text-success font-weight-bold text-uppercase small" style="letter-spacing: 0.5px;">Kompeten (Lulus)</h6>
                        <h2 class="font-weight-bold text-dark my-2"><?= $statistik->jumlah_kompeten ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card border-left-danger shadow-sm h-100 border-0">
                    <div class="card-body text-center py-4">
                        <h6 class="text-danger font-weight-bold text-uppercase small" style="letter-spacing: 0.5px;">Belum Kompeten</h6>
                        <h2 class="font-weight-bold text-dark my-2"><?= $statistik->jumlah_belum_kompeten ?></h2>
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
                        <?php if (!empty($statistik->distribusi_grade)): ?>
                            <?php foreach ($statistik->distribusi_grade as $grade): ?>
                                <?php
                                $badge_dist_class = 'secondary';
                                switch (strtoupper(trim($grade->huruf))) {
                                    case 'A': case 'AB': case 'SB': $badge_dist_class = 'success'; break;
                                    case 'B': case 'BC':             $badge_dist_class = 'primary'; break;
                                    case 'C':                         $badge_dist_class = 'warning'; break;
                                    case 'D': case 'E': case 'K':    $badge_dist_class = 'danger'; break;
                                }
                                ?>
                                <tr class="text-center">
                                    <td class="text-left pl-4 py-2">
                                        <span class="badge badge-<?= $badge_dist_class ?> px-3 py-1 font-weight-bold" style="min-width: 45px; font-size: 0.8rem;">
                                            <?= s($grade->huruf) ?>
                                        </span>
                                    </td>
                                    <td class="py-2 font-weight-bold text-dark"><?= $grade->jumlah ?> mhs</td>
                                    <td class="py-2 text-muted font-weight-bold"><?= $format_angka($grade->persentase) ?>%</td>
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
        <?php if (empty($data->detail_mahasiswa)): ?>
            <div class="alert alert-warning text-center m-3 border-0 shadow-sm">
                <i class="fa fa-exclamation-circle mr-1"></i> Belum ada rekaman log kalkulasi nilai mahasiswa untuk komponen Sub-CPMK ini.
            </div>
        <?php else: ?>
            <div class="table-responsive bg-white rounded p-3">
                <table class="table table-hover align-middle mb-0">
                    <thead class="thead-light">
                        <tr class="text-uppercase small text-center" style="letter-spacing: 0.5px;">
                            <th width="30%" class="text-left pl-4 py-3">Mahasiswa</th>
                            <th width="15%" class="py-3">Kelas</th>
                            <th width="15%" class="py-3">Nilai Kumulatif Sub-CPMK</th>
                            <th width="15%" class="py-3">Grade</th>
                            <th width="25%" class="py-3">Status OBE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data->detail_mahasiswa as $mhs): ?>
                            <?php
                            $badge_mhs_class = 'secondary';
                            switch (strtoupper(trim($mhs->grade ?? '-'))) {
                                case 'A': case 'AB': case 'SB': $badge_mhs_class = 'success'; break;
                                case 'B': case 'BC':             $badge_mhs_class = 'primary'; break;
                                case 'C':                         $badge_mhs_class = 'warning'; break;
                                case 'D': case 'E': case 'K':    $badge_mhs_class = 'danger'; break;
                            }
                            ?>
                            <tr class="text-center">
                                <td class="text-left pl-4 py-3">
                                    <div class="font-weight-bold text-dark mb-1" style="font-size: 0.9rem;"><?= s($mhs->nama) ?></div>
                                    <small class="text-muted"><i class="fa fa-id-card mr-1"></i><?= s($mhs->username ?? '') ?></small>
                                </td>
                                <td class="py-3 text-dark font-weight-bold">
                                    <?= s($mhs->namakelas ?? '-') ?>
                                </td>
                                <td class="py-3">
                                    <div class="h5 font-weight-bold text-primary mb-0"><?= $format_angka($mhs->nilai) ?></div>
                                </td>
                                <td class="py-3">
                                    <span class="badge badge-<?= $badge_mhs_class ?> px-3 py-2" style="min-width: 45px; font-size: 0.85rem; font-weight: 600;">
                                        <?= s($mhs->grade ?? '-') ?>
                                    </span>
                                </td>
                                <td class="py-3">
                                    <?php if ($mhs->kompeten): ?>
                                        <span class="badge badge-success px-3 py-2 font-weight-bold shadow-sm" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                                            <i class="fa fa-check-circle mr-1"></i> KOMPETEN
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-danger px-3 py-2 font-weight-bold shadow-sm" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                                            <i class="fa fa-times-circle mr-1"></i> BELUM KOMPETEN
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