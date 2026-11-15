<?php
// File: local/obe/views/admin/capaianprodi/detail_nilai_cpmk.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\capaian_prodi_manager;
use local_obe\manager\prodi_manager;
use local_obe\manager\kompetensi_manager;

global $DB, $SESSION;

// =====================================
// 1. TANGKAP PARAMETER MASUKAN
// =====================================
$prodi_id          = optional_param('prodi', 0, PARAM_INT);
$angkatan_id       = optional_param('angkatan', 0, PARAM_INT);
$cpl_id            = optional_param('cpl_id', 0, PARAM_INT);
$indikator_id      = optional_param('indikator_id', 0, PARAM_INT);
$kompetensi_id     = optional_param('kompetensi_id', 0, PARAM_INT); // ID CPMK target
$tipe              = 'CPMK';
$id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;

if (empty($kompetensi_id)) {
    throw new moodle_exception('invalidrecord', 'error', '', null, 'ID kompetensi CPMK tidak valid atau kosong.');
}

if (empty($prodi_id)) {
    throw new moodle_exception('invalidrecord', 'error', '', null, 'ID program studi tidak valid atau kosong.');
}

// =========================================================================
// 2. VALIDASI KEAMANAN SERVER (ANTI-IDOR SECURITY GATE)
// =========================================================================
$prodi_aktif = prodi_manager::get_prodi_aktif_user();

if ($prodi_aktif) {
    $prodi_id = (int)$prodi_aktif->id;
}

$kompetensi = kompetensi_manager::ambil_by_id($kompetensi_id);
if (!$kompetensi || (int)$kompetensi->id_prodi !== (int)$prodi_id) {
    throw new \moodle_exception(
        'aksesditolak',
        'local_obe',
        '',
        null,
        'Aksi ditolak! Anda tidak memiliki hak akses untuk meninjau struktur data CPMK milik program studi lain.'
    );
}

$nama_prodi_header = $DB->get_field('local_obe_prodi', 'nama_prodi', ['id' => $prodi_id]) ?: 'Program Studi';

// =========================================================================
// 3. AMBIL DATA UTAMA & ENGINE STATISTIK PRODI (DELEGASI PENUH KE MANAGER)
// =========================================================================
$data = null;
$statistik = null;

try {
    // Menggunakan pemanggilan terpusat skala prodi dengan parameter tipe 'CPMK'
    $data = capaian_prodi_manager::ambil_detail_kompetensi_mahasiswa_prodi(
        $prodi_id,
        $kompetensi_id,
        'CPMK',
        $id_tahun_akademik,
        $angkatan_id
    );

    // Menarik data statistik terpusat murni dari manager
    $statistik = capaian_prodi_manager::ambil_statistik_skala_prodi(
        $prodi_id,
        $kompetensi_id,
        'CPMK',
        $id_tahun_akademik,
        $angkatan_id
    );

} catch (\Throwable $e) {
    echo "<div class='alert alert-danger m-3'><b>Fatal Error di Fungsi Manager:</b> " . s($e->getMessage()) . "<br><small>File: " . $e->getFile() . " Line: " . $e->getLine() . "</small></div>";
    die();
}

// Sinkronisasi Variabel: Seluruh metrik statistik ditarik murni dari object $statistik Manager
$total_mhs               = $statistik->total_mahasiswa ?? 0;
$rata_rata_cpmk_prodi    = $statistik->rata_rata ?? 0;
$total_kompeten          = $statistik->jumlah_kompeten ?? 0;
$total_belum_kompeten    = $statistik->jumlah_belum_kompeten ?? 0;
$persentase_kompeten      = $statistik->persentase_kompeten ?? 0;
$persentase_perbaikan     = $statistik->persentase_belum_kompeten ?? 0;

// =====================================
// 4. URL KEMBALI (BALIK KE LIST CPMK)
// =====================================
$url_kembali = new moodle_url('/local/obe/views/admin/dashboard.php', [
    'halaman'      => 'capaian_pembelajaran_prodi',
    'sub'          => 'cpmk',
    'prodi'        => $prodi_id,
    'angkatan'     => $angkatan_id,
    'cpl_id'       => $cpl_id,
    'indikator_id' => $indikator_id
]);

// Fungsi Formatter Nilai Desimal Efisien
$format_nilai = function($nilai, $desimal = 2) {
    if (!is_numeric($nilai)) {
        return $nilai;
    }

    return (floor($nilai) == $nilai)
        ? (int)$nilai
        : rtrim(rtrim(number_format($nilai, $desimal, '.', ''), '0'), '.');
};
?>

<div class="card shadow-sm mb-4 border-0">
    <div class="card-header bg-white py-3 border-bottom">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-1 font-weight-bold text-dark">
                    <i class="fa fa-graduation-cap text-primary mr-2"></i>Detail Capaian CPMK - <?= s($kompetensi->nama_kompetensi) ?>
                </h5>
                <small class="text-muted"><i class="fa fa-university mr-1"></i><?= s($nama_prodi_header) ?></small>
            </div>
            <a href="<?= $url_kembali->out() ?>" class="btn btn-secondary btn-sm font-weight-bold shadow-sm px-3">
                <i class="fa fa-arrow-left mr-1"></i> Kembali ke Daftar CPMK
            </a>
        </div>
    </div>

    <?php if ($total_mhs > 0): ?>
        <div class="row px-3 pt-3 m-0">
            <div class="col-md-3 mb-3">
                <div class="card border-left-primary shadow-sm h-100 border-0">
                    <div class="card-body text-center py-4">
                        <h6 class="text-primary font-weight-bold text-uppercase small" style="letter-spacing: 0.5px;">Rata-Rata CPMK</h6>
                        <h2 class="font-weight-bold text-dark my-2"><?= $format_nilai($rata_rata_cpmk_prodi) ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card border-left-info shadow-sm h-100 border-0">
                    <div class="card-body text-center py-4">
                        <h6 class="text-info font-weight-bold text-uppercase small" style="letter-spacing: 0.5px;">Total Mahasiswa</h6>
                        <h2 class="font-weight-bold text-dark my-2"><?= $total_mhs ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card border-left-success shadow-sm h-100 border-0">
                    <div class="card-body text-center py-4">
                        <h6 class="text-success font-weight-bold text-uppercase small" style="letter-spacing: 0.5px;">Kompeten (Lulus)</h6>
                        <h2 class="font-weight-bold text-dark my-2"><?= $total_kompeten ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card border-left-danger shadow-sm h-100 border-0">
                    <div class="card-body text-center py-4">
                        <h6 class="text-danger font-weight-bold text-uppercase small" style="letter-spacing: 0.5px;">Belum Kompeten</h6>
                        <h2 class="font-weight-bold text-dark my-2"><?= $total_belum_kompeten ?></h2>
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
                                $huruf_grade = is_object($grade) ? ($grade->huruf ?? '-') : $grade;
                                $jumlah_mhs  = is_object($grade) ? ($grade->jumlah ?? 0) : 0;
                                $persen_mhs  = is_object($grade) ? ($grade->persentase ?? 0) : 0;

                                switch (strtoupper(trim($huruf_grade))) {
                                    case 'A': case 'AB': case 'SB': $badge_dist_class = 'success'; break;
                                    case 'B': case 'BC':             $badge_dist_class = 'primary'; break;
                                    case 'C':                        $badge_dist_class = 'warning'; break;
                                    case 'D': case 'E': case 'K':    $badge_dist_class = 'danger'; break;
                                }
                                ?>
                                <tr class="text-center">
                                    <td class="text-left pl-4 py-2">
                                        <span class="badge badge-<?= $badge_dist_class ?> px-3 py-1 font-weight-bold" style="min-width: 45px; font-size: 0.8rem;">
                                            <?= s($huruf_grade) ?>
                                        </span>
                                    </td>
                                    <td class="py-2 font-weight-bold text-dark"><?= $jumlah_mhs ?> mhs</td>
                                    <td class="py-2 text-muted font-weight-bold"><?= $format_nilai($persen_mhs) ?>%</td>
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
        <?php if ($total_mhs === 0 || empty($data->detail_mahasiswa)): ?>
            <div class="alert alert-warning text-center m-3 border-0 shadow-sm">
                <i class="fa fa-exclamation-circle mr-1"></i> Belum ada rekaman kalkulasi capaian mahasiswa untuk komponen CPMK prodi ini.
            </div>
        <?php else: ?>
            <div class="table-responsive bg-white rounded p-3">
                <table class="table table-hover align-middle mb-0 border">
                    <thead class="thead-light">
                        <tr class="text-uppercase small text-center" style="letter-spacing: 0.5px;">
                            <th width="45%" class="text-left pl-4 py-3">Mahasiswa</th>
                            <th width="20%" class="py-3">Nilai Rata-Rata CPMK</th>
                            <th width="15%" class="py-3">Grade</th>
                            <th width="20%" class="py-3">Status OBE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($data->detail_mahasiswa)): ?>
                            <?php foreach ($data->detail_mahasiswa as $mhs): ?>
                                <?php
                                $badge_mhs_class = 'secondary';
                                switch (strtoupper(trim($mhs->grade ?? '-'))) {
                                    case 'A': case 'AB': case 'SB': $badge_mhs_class = 'success'; break;
                                    case 'B': case 'BC':             $badge_mhs_class = 'primary'; break;
                                    case 'C':                        $badge_mhs_class = 'warning'; break;
                                    case 'D': case 'E': case 'K':    $badge_mhs_class = 'danger'; break;
                                }
                                ?>
                                <tr class="text-center">
                                    <td class="text-left pl-4 py-3">
                                        <div class="font-weight-bold text-dark mb-1" style="font-size: 0.9rem;"><?= s($mhs->nama ?? '-') ?></div>
                                        <small class="text-muted"><i class="fa fa-id-card mr-1"></i><?= s($mhs->username ?? '') ?></small>
                                    </td>
                                    <td class="py-3">
                                        <div class="h5 font-weight-bold text-primary mb-0"><?= $format_nilai($mhs->nilai ?? 0) ?></div>
                                    </td>
                                    <td class="py-3">
                                        <span class="badge badge-<?= $badge_mhs_class ?> px-3 py-2" style="min-width: 45px; font-size: 0.85rem; font-weight: 600;">
                                            <?= s($mhs->grade ?? '-') ?>
                                        </span>
                                    </td>
                                    <td class="py-3">
                                        <?php if (!empty($mhs->kompeten)): ?>
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
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>