<?php
// File: local/obe/views/admin/capaian_kelas/list_indikator.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\capaian_kelas_manager;
use local_obe\manager\prodi_manager;
use local_obe\manager\kompetensi_manager;

global $DB, $SESSION, $OUTPUT, $COURSE, $USER;

// =====================================================
// 1. IDENTIFIKASI MODE VIEW
// =====================================================
$is_dosen_mode = (isset($mode_view) && $mode_view === 'dosen');
$is_mahasiswa_mode = (isset($mode_view) && $mode_view === 'mahasiswa');

// =====================================================
// 2. TANGKAP PARAMETER COURSE ID & CPL ID
// =====================================================
$courseid = optional_param('id_course', optional_param('id', 0, PARAM_INT), PARAM_INT);
if ($courseid <= 0 && isset($COURSE)) {
    $courseid = $COURSE->id;
}

$tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
$cpl_id = optional_param('cpl_id', 0, PARAM_INT);

if (empty($cpl_id)) {
    throw new moodle_exception('invalidrecord', 'error', '', null, 'ID CPL tidak valid atau kosong.');
}

// =====================================================
// 3. VALIDASI KEAMANAN KAPRODI (ANTI-IDOR FIRST)
// =====================================================
$prodi_aktif = prodi_manager::get_prodi_aktif_user();

if ($prodi_aktif && !$is_dosen_mode) {
    // LAPIS 1: Validasi Otoritas Lintas Kelas Moodle
    $sql_relasi = "SELECT rk.id
                     FROM {local_obe_relasi_kelas} rk
                     JOIN {local_obe_mata_kuliah} mk ON mk.id = rk.id_mata_kuliah
                    WHERE rk.id_kursus_moodle = :courseid
                      AND mk.id_prodi = :prodiid";

    $relasi_valid = $DB->get_record_sql($sql_relasi, [
        'courseid' => $courseid,
        'prodiid'  => $prodi_aktif->id
    ]);

    if (!$relasi_valid) {
        throw new \moodle_exception(
            'aksesditolak',
            'local_obe',
            '',
            null,
            'Aksi ditolak! Anda tidak memiliki hak akses untuk melihat kelas program studi lain.'
        );
    }

    // LAPIS 2: Validasi Otoritas Parameter Acuan CPL
    $kompetensi = kompetensi_manager::ambil_by_id($cpl_id);
    if (!$kompetensi) {
        throw new \moodle_exception('invalidrecord', 'error');
    }

    if ((int)$kompetensi->id_prodi !== (int)$prodi_aktif->id) {
        throw new \moodle_exception(
            'aksesditolak',
            'local_obe',
            '',
            null,
            'Aksi ditolak! Anda tidak memiliki hak akses terhadap CPL program studi lain.'
        );
    }
}

// =====================================================
// 4. AMBIL DATA STRUKTUR (Menggunakan Pendekatan Baru)
// =====================================================
// Ambil record CPL tunggal sebagai anchor header (Sesuai Rencana Anda)
$cpl = $DB->get_record('local_obe_kompetensi', ['id' => $cpl_id], '*', MUST_EXIST);

// Ambil list indikator pendukung murni struktur kurikulum via helper terstandarisasi
$list_ind = capaian_kelas_manager::ambil_struktur_kompetensi_kelas($cpl_id, 'INDIKATOR', $tahun_akademik);

// Tarik data akumulasi CPL dari engine utama (Untuk nilai badge kanan atas header)
$detail_cpl_parent = capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($courseid, $cpl_id, 'CPL', $tahun_akademik);
$nilai_header = $detail_cpl_parent->ringkasan->rata_rata ?? 0;

// Override nilai header jika diakses dalam mode personal mahasiswa
if ($is_mahasiswa_mode && !empty($detail_cpl_parent->detail_mahasiswa)) {
    $nilai_header = 0;
    foreach ($detail_cpl_parent->detail_mahasiswa as $mhs_parent) {
        if ((int)$mhs_parent->userid === (int)$USER->id) {
            $nilai_header = $mhs_parent->nilai;
            break;
        }
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

// =====================================================
// 5. CONFIG TOMBOL KEMBALI
// =====================================================
if ($is_mahasiswa_mode) {
    $url_kembali = new moodle_url('/local/obe/views/mahasiswa/dashboard.php', ['id_course' => $courseid]);
} elseif ($is_dosen_mode) {
    $url_kembali = new moodle_url('/local/obe/views/dosen/dashboard.php', [
        'id_course' => $courseid,
        'halaman'   => 'laporan',
        'sub'       => 'cpl'
    ]);
    $url_grafik = new moodle_url('/local/obe/views/dosen/dashboard.php', [
        'id_course' => $courseid,
        'halaman'   => 'laporan',
        'sub'       => 'grafik_indikator',
        'cpl_id'    => $cpl_id
    ]);
} else {
    $url_kembali = new moodle_url('/local/obe/views/admin/dashboard.php', [
        'id'        => $courseid,
        'halaman'   => 'capaian_pembelajaran_kelas',
        'sub'       => 'cpl'
    ]);
    $url_grafik = new moodle_url('/local/obe/views/admin/dashboard.php', [
        'id'        => $courseid,
        'id_course' => $courseid,
        'halaman'   => 'capaian_pembelajaran_kelas',
        'sub'       => 'grafik_indikator',
        'cpl_id'    => $cpl_id
    ]);
}
?>

<div class="mb-4 d-flex justify-content-between align-items-center">
    <div>
        <h4 class="font-weight-bold text-dark mb-1">Laporan Capaian Indikator Kinerja</h4>
        <p class="text-muted small mb-0">
            <?= $is_mahasiswa_mode 
                ? 'Tingkat capaian berdasarkan nilai pribadi mahasiswa.' 
                : 'Tingkat capaian berdasarkan rata-rata pencapaian indikator kelas.' ?>
        </p>
    </div>
    <div class="d-flex align-items-center" style="gap: 8px;">
        <?php if (!$is_mahasiswa_mode): ?>
            <a href="<?= $url_grafik->out() ?>" class="btn btn-sm btn-primary font-weight-bold shadow-sm">
                <i class="fa fa-bar-chart mr-1"></i> Grafik Indikator
            </a>
        <?php endif; ?>
        <a href="<?= $url_kembali->out() ?>" class="btn btn-sm btn-outline-secondary font-weight-bold shadow-sm">
            <i class="fa fa-arrow-left mr-1"></i> Kembali ke CPL
        </a>
    </div>
</div>

<div class="card mb-4 shadow-sm border-0">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 border-bottom">
        <div>
            <div class="h5 mb-1 text-dark" style="color: #000000 !important; font-weight: 700;">
                <i class="fa fa-graduation-cap text-primary mr-2"></i>CPL: <?= s($cpl->nama_kompetensi ?? $cpl->nama ?? '-') ?>
            </div>
            <?php if (!empty($cpl->deskripsi)): ?>
                <div class="text-secondary small" style="color: #495057 !important; line-height: 1.45; max-width: 750px;">
                    <?= format_text($cpl->deskripsi, FORMAT_HTML) ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="text-right flex-shrink-0 ml-3">
            <span class="text-muted small font-weight-bold text-uppercase d-block mb-1" style="letter-spacing: 0.5px; font-size: 0.7rem;">
                <?= $is_mahasiswa_mode ? 'Capaian CPL Saya' : 'Total Capaian Kelas' ?>
            </span>
            <span class="badge badge-primary px-3 py-2 font-weight-bold shadow-xs" style="font-size: 0.95rem; letter-spacing: 0.5px; border-radius: 6px;">
                <?= $format_angka($nilai_header) ?>
            </span>
        </div>
    </div>

    <div class="table-responsive bg-white rounded p-3">
        <table class="table table-hover align-middle mb-0">
            <thead class="thead-light">
                <tr class="text-uppercase small text-center" style="letter-spacing: 0.5px;">
                    <th class="text-left py-3 pl-4" style="width: 30%;">Indikator Kinerja</th>
                    <th style="width: 10%;">Bobot</th>
                    <th style="width: 20%;"><?= $is_mahasiswa_mode ? 'Capaian Saya' : 'Rata-Rata Capaian & Kelulusan Kelas' ?></th>
                    <th style="width: 15%;">Kontribusi CPL</th>
                    <th style="width: 10%;">Grade</th>
                    <th style="width: 15%;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($list_ind)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            <i class="fa fa-ban mr-1"></i> Tidak ada indikator yang terhubung ke CPL ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($list_ind as $ind): ?>
                        <?php
                        // Tembak SSoT Fondasi Utama untuk setiap komponen anak Indikator
                        $hasil = capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($courseid, $ind->id, 'INDIKATOR', $tahun_akademik);
                        $ringkasan = $hasil->ringkasan;

                        $nilai_indikator = $ringkasan->rata_rata;
                        $grade_tampil    = $ringkasan->grade;

                        if ($is_mahasiswa_mode) {
                            $nilai_indikator = 0;
                            $grade_tampil    = '-';
                            foreach ($hasil->detail_mahasiswa as $mhs) {
                                if ((int)$mhs->userid === (int)$USER->id) {
                                    $nilai_indikator = $mhs->nilai;
                                    $grade_tampil    = $mhs->grade;
                                    break;
                                }
                            }
                        }

                        // Menggunakan properti baru terstandarisasi: $ind->bobot
                        $kontribusi_cpl = $nilai_indikator * ($ind->bobot / 100);

                        $badge_class = 'secondary';
                        switch (strtoupper(trim($grade_tampil))) {
                            case 'A': case 'AB': case 'SB': $badge_class = 'success'; break;
                            case 'B': case 'BC':             $badge_class = 'primary'; break;
                            case 'C':                         $badge_class = 'warning'; break;
                            case 'D': case 'E': case 'K':    $badge_class = 'danger'; break;
                        }
                        ?>
                        <tr>
                            <td class="text-left pl-4 py-3">
                                <div class="font-weight-bold text-dark" style="font-size: 0.95rem;">
                                    <i class="fa fa-caret-right text-info mr-2"></i><?= s($ind->nama) ?>
                                </div>
                                <?php if (!$is_mahasiswa_mode): ?>
                                    <small class="text-muted d-block mt-1 ml-3">
                                        <i class="fa fa-users mr-1"></i> Total Peserta: <strong><?= $ringkasan->jumlah_mahasiswa ?></strong> mhs
                                    </small>
                                <?php endif; ?>
                            </td>
                            
                            <td class="text-center py-3">
                                <span class="badge badge-secondary font-weight-bold px-2 py-1">
                                    <?= $format_angka($ind->bobot) ?>%
                                </span>
                            </td>
                            
                            <td class="text-center py-3">
                                <div class="h4 font-weight-bold text-dark mb-1">
                                    <?= $format_angka($nilai_indikator) ?>
                                </div>
                                
                                <?php if (!$is_mahasiswa_mode): ?>
                                    <div class="px-3 mt-1">
                                        <div class="mb-1" style="font-size: 11px; color: #555555;">
                                            <span>Kompeten: </span>
                                            <span style="font-weight: bold; color: #333333;">
                                                <?= $ringkasan->jumlah_kompeten ?> / <?= $ringkasan->jumlah_mahasiswa ?> mhs (<?= $format_angka($ringkasan->persentase_kompeten) ?>%)
                                            </span>
                                        </div>
                                        <div class="progress" style="height: 5px;">
                                            <div class="progress-bar bg-success" role="progressbar" style="width: <?= $format_angka($ringkasan->persentase_kompeten) ?>%;"></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            
                            <td class="text-center py-3">
                                <div class="font-weight-bold text-info h5 mb-0">
                                    <?= $format_angka($kontribusi_cpl) ?>
                                </div>
                            </td>
                            
                            <td class="text-center py-3">
                                <span class="badge badge-<?= $badge_class ?> px-3 py-2" style="font-size: 0.85rem; font-weight: 600; min-width: 45px;">
                                    <?= s($grade_tampil) ?>
                                </span>
                            </td>
                            
                            <td class="text-center py-3">
                                <div class="d-flex justify-content-center align-items-center" style="gap: 4px;">
                                    <?php
                                    if ($is_mahasiswa_mode) {
                                        $url_lihat = new moodle_url('/local/obe/views/mahasiswa/dashboard.php', [
                                            'id_course'    => $courseid,
                                            'halaman'      => 'cpmk',
                                            'indikator_id' => $ind->id,
                                            'cpl_id'       => $cpl_id
                                        ]);
                                    } else if ($is_dosen_mode) {
                                        $url_lihat = new moodle_url('/local/obe/views/dosen/dashboard.php', [
                                            'id_course'    => $courseid,
                                            'halaman'      => 'laporan',
                                            'sub'          => 'cpmk',
                                            'indikator_id' => $ind->id,
                                            'cpl_id'       => $cpl_id
                                        ]);
                                        $url_detail = new moodle_url('/local/obe/views/dosen/dashboard.php', [
                                            'id_course'     => $courseid,
                                            'halaman'       => 'laporan',
                                            'sub'           => 'detail_nilai_indikator',
                                            'tipe'          => 'INDIKATOR',
                                            'kompetensi_id' => $ind->id,
                                            'cpl_id'        => $cpl_id
                                        ]);
                                    } else {
                                        $url_lihat = new moodle_url('/local/obe/views/admin/dashboard.php', [
                                            'id'           => $courseid,
                                            'halaman'      => 'capaian_pembelajaran_kelas',
                                            'sub'          => 'cpmk',
                                            'indikator_id' => $ind->id,
                                            'cpl_id'       => $cpl_id
                                        ]);
                                        $url_detail = new moodle_url('/local/obe/views/admin/dashboard.php', [
                                            'id_course'     => $courseid,
                                            'id'            => $courseid,
                                            'halaman'       => 'capaian_pembelajaran_kelas',
                                            'sub'           => 'detail_nilai_indikator',
                                            'tipe'          => 'INDIKATOR',
                                            'kompetensi_id' => $ind->id,
                                            'cpl_id'        => $cpl_id
                                        ]);
                                    }
                                    ?>
                                    <a href="<?= $url_lihat->out() ?>" class="btn btn-sm btn-primary d-inline-flex align-items-center justify-content-center p-0 rounded-circle" style="width: 30px; height: 30px;" title="Rincian CPMK">
                                        <i class="fa fa-search" style="font-size: 11px;"></i>
                                    </a>

                                    <?php if (!$is_mahasiswa_mode): ?>
                                        <a href="<?= $url_detail->out() ?>" class="btn btn-sm btn-info d-inline-flex align-items-center justify-content-center p-0 rounded-circle" style="width: 30px; height: 30px;" title="Detail Nilai Mahasiswa">
                                            <i class="fa fa-users" style="font-size: 11px;"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>