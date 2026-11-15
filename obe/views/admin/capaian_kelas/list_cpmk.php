<?php
// File: local/obe/views/admin/capaian_kelas/list_cpmk.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\capaian_kelas_manager;
use local_obe\manager\prodi_manager;
use local_obe\manager\kompetensi_manager;

global $COURSE, $DB, $OUTPUT, $USER, $SESSION;

// =====================================================
// 1. IDENTIFIKASI MODE LOGIN
// =====================================================
$is_dosen_mode     = (isset($mode_view) && $mode_view === 'dosen');
$is_mahasiswa_mode = (isset($mode_view) && $mode_view === 'mahasiswa');

// =====================================================
// 2. TANGKAP PARAMETER MASUKAN
// =====================================================
$courseid = optional_param('id_course', optional_param('id', 0, PARAM_INT), PARAM_INT);
if ($courseid == 0 && isset($COURSE)) {
    $courseid = $COURSE->id;
}

$indikator_id   = optional_param('indikator_id', 0, PARAM_INT);
$cpl_id         = optional_param('cpl_id', 0, PARAM_INT);
$tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;

if (empty($indikator_id)) {
    throw new moodle_exception('invalidrecord', 'error', '', null, 'ID indikator tidak valid atau kosong.');
}

// =====================================================
// 3. VALIDASI KEAMANAN KAPRODI (ANTI-IDOR PARAMETER CHECK)
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
            'Aksi ditolak! Anda tidak memiliki hak akses untuk meninjau data kelas dari program studi lain.'
        );
    }

    // LAPIS 2: Validasi Otoritas Parameter Induk Indikator Kinerja
    $indikator_check = kompetensi_manager::ambil_by_id($indikator_id);
    if (!$indikator_check) {
        throw new \moodle_exception('invalidrecord', 'error');
    }

    if ((int)$indikator_check->id_prodi !== (int)$prodi_aktif->id) {
        throw new \moodle_exception(
            'aksesditolak',
            'local_obe',
            '',
            null,
            'Aksi ditolak! Anda tidak memiliki hak akses terhadap komponen kurikulum milik program studi lain.'
        );
    }
}

// =====================================================
// 4. TARIK DATA STRUKTUR (Mata Kuliah -> Indikator -> CPMK)
// =====================================================
$indikator = $DB->get_record('local_obe_kompetensi', ['id' => $indikator_id], '*', MUST_EXIST);
$cpmk_data = capaian_kelas_manager::ambil_struktur_kompetensi_kelas( $indikator_id, 'CPMK', $tahun_akademik);

// =====================================================
// 5. PETA DATA STRUKTUR DENGAN ENGINE MESIN FONDASI BARU (SSoT)
// =====================================================
$total_kontribusi_akumulasi = 0;
$cpmk_payloads = []; // Menyimpan ringkasan runtime per CPMK

if (!empty($cpmk_data)) {
    foreach ($cpmk_data as $cpmk) {
        // Tembak SSoT Fondasi Utama untuk setiap komponen anak CPMK
        $hasil = capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($courseid, $cpmk->id, 'CPMK', $tahun_akademik);
        $ringkasan = $hasil->ringkasan;
        $detail_mahasiswa = $hasil->detail_mahasiswa;

        // Inisialisasi default penentu nilai (Mode Dosen/Admin)
        $nilai_cpmk_final = $ringkasan->rata_rata;
        $grade_cpmk_final = $ringkasan->grade;

        // Jika mode mahasiswa, override nilai berbasis baris data individu ybs
        if ($is_mahasiswa_mode) {
            $nilai_cpmk_final = 0;
            $grade_cpmk_final = '-';
            foreach ($detail_mahasiswa as $mhs) {
                if ((int)$mhs->userid === (int)$USER->id) {
                    $nilai_cpmk_final = $mhs->nilai;
                    $grade_cpmk_final = $mhs->grade;
                    break;
                }
            }
        }

        // Hitung nilai kontribusi kumulatif terhadap Indikator Kinerja di atasnya
        $total_kontribusi_akumulasi += $nilai_cpmk_final * ($cpmk->bobot / 100);

        // Bungkus data ke dalam struktur runtime payload biner
        $cpmk_payloads[] = (object)[
            'id'                 => $cpmk->id,
            'nama'               => $cpmk->nama,
            'bobot'              => $cpmk->bobot,
            'nilai_tampil'       => $nilai_cpmk_final,
            'grade_tampil'       => $grade_cpmk_final,
            'jumlah_mahasiswa'   => $ringkasan->jumlah_mahasiswa,
            'jumlah_kompeten'    => $ringkasan->jumlah_kompeten,
            'persentase_kompeten'=> $ringkasan->persentase_kompeten
        ];
    }
}

// Nilai Indikator Parent sekarang dijamin valid dan sinkron lintas role
$nilai_parent = $total_kontribusi_akumulasi;

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
// 6. URL KEMBALI DINAMIS (Sesuai Hak Akses Context)
// =====================================================
// Pada bagian pembentukan URL navigasi di list_cpmk.php:
if ($is_mahasiswa_mode) {
    $url_kembali = new moodle_url('/local/obe/views/mahasiswa/dashboard.php', [
        'id_course' => $courseid,
        'halaman'   => 'indikator',
        'cpl_id'    => $cpl_id
    ]);
} else if ($is_dosen_mode) {
    $url_kembali = new moodle_url('/local/obe/views/dosen/dashboard.php', [
        'id_course'    => $courseid,
        'halaman'      => 'laporan',
        'sub'          => 'indikator',
        'cpl_id'       => $cpl_id
    ]);
    $url_grafik = new moodle_url('/local/obe/views/dosen/dashboard.php', [
        'id_course'    => $courseid,
        'halaman'      => 'laporan',
        'sub'          => 'grafik_cpmk',
        'indikator_id' => $indikator_id,
        'cpl_id'       => $cpl_id
    ]);
} else {
    $url_kembali = new moodle_url('/local/obe/views/admin/dashboard.php', [
        'id'           => $courseid,
        'id_course'    => $courseid,
        'halaman'      => 'capaian_pembelajaran_kelas',
        'sub'          => 'indikator',
        'cpl_id'       => $cpl_id
    ]);
    $url_grafik = new moodle_url('/local/obe/views/admin/dashboard.php', [
        'id'           => $courseid,
        'id_course'    => $courseid,
        'halaman'      => 'capaian_pembelajaran_kelas',
        'sub'          => 'grafik_cpmk',
        'indikator_id' => $indikator_id,
        'cpl_id'       => $cpl_id
    ]);
}
?>

<div class="card mb-4 shadow-sm border-0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
    <h5 class="mb-0 font-weight-bold text-dark">
        <i class="fa fa-list text-primary mr-2"></i><?= $is_mahasiswa_mode ? 'Capaian CPMK Saya' : 'Rincian CPMK Kelas' ?>
    </h5>
    <div class="d-flex align-items-center" style="gap: 8px;">
        <?php if (!$is_mahasiswa_mode): ?>
            <a href="<?= $url_grafik->out() ?>" class="btn btn-sm btn-primary font-weight-bold shadow-sm">
                <i class="fa fa-bar-chart mr-1"></i> Grafik CPMK
            </a>
        <?php endif; ?>
        <a href="<?= $url_kembali->out() ?>" class="btn btn-sm btn-outline-secondary font-weight-bold shadow-sm">
            <i class="fa fa-arrow-left mr-1"></i> Kembali
        </a>
    </div>
</div>

    <div class="card-body border-bottom bg-light">
        <div class="row align-items-center m-0">
            <div class="col-md-8 px-0">
                 <h6 class="font-weight-bold text-dark mb-1">
                    <i class="fa fa-sitemap text-primary mr-2"></i>
                    INDIKATOR: <?= s($indikator->nama_kompetensi ?? '-') ?>
                </h6>
                <?php if (!empty($indikator->deskripsi)): ?>
                    <div class="text-muted small ml-4">
                        <?= format_text($indikator->deskripsi, FORMAT_HTML) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-md-4 text-right px-0">
                <span class="text-muted small font-weight-bold text-uppercase d-block mb-1" style="letter-spacing: 0.5px; font-size: 0.7rem;">
                    <?= $is_mahasiswa_mode ? 'Capaian Indikator Saya' : 'Rata-rata Capaian Indikator Kelas' ?>
                </span>
                <span class="badge badge-primary px-3 py-2 font-weight-bold" style="font-size: 1rem;">
                    <?= $format_angka($nilai_parent) ?>
                </span>
            </div>
        </div>
    </div>

    <div class="card-body p-0">
        <?php if (empty($cpmk_payloads)): ?>
            <div class="alert alert-warning text-center m-3 border-0 shadow-sm">
                <i class="fa fa-exclamation-triangle mr-1"></i> Belum ada data CPMK untuk indikator ini.
            </div>
        <?php else: ?>
            <div class="table-responsive bg-white rounded p-3">
                <table class="table table-hover align-middle mb-0">
                    <thead class="thead-light">
                        <tr class="text-uppercase small text-center" style="letter-spacing: 0.5px;">
                            <th class="text-left py-3 pl-4" style="width: 30%;">CPMK</th>
                            <th style="width: 10%;">Bobot</th>
                            <th style="width: 25%;"><?= $is_mahasiswa_mode ? 'Capaian Saya' : 'Rata-Rata Capaian & Kelulusan Kelas' ?></th>
                            <th style="width: 12%;">Kontribusi</th>
                            <th style="width: 10%;">Grade</th>
                            <th style="width: 13%;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cpmk_payloads as $cpmk): ?>
                            <?php
                            $nilai_kontribusi = $cpmk->nilai_tampil * ($cpmk->bobot / 100);

                            $badge = 'secondary';
                            switch (strtoupper(trim($cpmk->grade_tampil))) {
                                case 'A': case 'AB': case 'SB': $badge = 'success'; break;
                                case 'B': case 'BC':             $badge = 'primary'; break;
                                case 'C':                         $badge = 'warning'; break;
                                case 'D': case 'E': case 'K':    $badge = 'danger'; break;
                            }
                            ?>
                            <tr class="text-center">
                                <td class="text-left pl-4 py-3">
                                    <div class="font-weight-bold text-dark mb-1">
                                        <?= s($cpmk->nama) ?>
                                    </div>
                                    <?php if (!$is_mahasiswa_mode): ?>
                                        <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.65rem;">
                                            <i class="fa fa-users mr-1"></i>Total Peserta: <strong><?= $cpmk->jumlah_mahasiswa ?></strong> mhs
                                        </small>
                                    <?php endif; ?>
                                </td>

                                <td class="py-3">
                                    <span class="badge badge-secondary font-weight-bold px-2 py-1">
                                        <?= $format_angka($cpmk->bobot) ?>%
                                    </span>
                                </td>

                                <td class="py-3">
                                    <div class="font-weight-bold h4 text-dark mb-1">
                                        <?= $format_angka($cpmk->nilai_tampil) ?>
                                    </div>
                                    <?php if (!$is_mahasiswa_mode): ?>
                                        <div class="px-3">
                                            <div class="mb-1" style="font-size: 11px; color: #555555;">
                                                <span>Kompeten: </span>
                                                <span style="font-weight: bold; color: #333333;">
                                                    <?= $cpmk->jumlah_kompeten ?> / <?= $cpmk->jumlah_mahasiswa ?> mhs (<?= $format_angka($cpmk->persentase_kompeten) ?>%)
                                                </span>
                                            </div>
                                            <div class="progress" style="height: 5px;">
                                                <div class="progress-bar bg-success" role="progressbar" style="width: <?= $format_angka($cpmk->persentase_kompeten) ?>%"></div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td class="font-weight-bold text-info py-3" style="font-size: 0.95rem;">
                                    <?= $format_angka($nilai_kontribusi) ?>
                                </td>

                                <td class="py-3">
                                    <span class="badge badge-<?= $badge ?> px-3 py-2 font-weight-bold" style="font-size: 0.85rem; min-width: 45px;">
                                        <?= s($cpmk->grade_tampil) ?>
                                    </span>
                                </td>

                                <td class="py-3">
                                    <div class="d-flex justify-content-center align-items-center" style="gap: 4px;">
                                        <?php
                                        $current_cpl_id = $cpl_id;
                                        if ($is_mahasiswa_mode) {
                                            $url_sub = new moodle_url('/local/obe/views/mahasiswa/dashboard.php', [
                                                'id_course'    => $courseid,
                                                'halaman'      => 'subcpmk',
                                                'cpmk_id'      => $cpmk->id,
                                                'indikator_id' => $indikator_id,
                                                'cpl_id'       => $current_cpl_id
                                            ]);
                                        } else if ($is_dosen_mode) {
                                            $url_sub = new moodle_url('/local/obe/views/dosen/dashboard.php', [
                                                'id_course'    => $courseid,
                                                'halaman'      => 'laporan',
                                                'sub'          => 'subcpmk',
                                                'cpmk_id'      => $cpmk->id,
                                                'indikator_id' => $indikator_id,
                                                'cpl_id'       => $current_cpl_id
                                            ]);
                                            $url_detail = new moodle_url('/local/obe/views/dosen/dashboard.php', [
                                                'id_course'     => $courseid,
                                                'halaman'       => 'laporan',
                                                'sub'           => 'detail_nilai_cpmk',
                                                'kompetensi_id' => $cpmk->id,
                                                'indikator_id'  => $indikator_id,
                                                'cpl_id'        => $current_cpl_id
                                            ]);
                                        } else {
                                            $url_sub = new moodle_url('/local/obe/views/admin/dashboard.php', [
                                                'id'           => $courseid,
                                                'halaman'      => 'capaian_pembelajaran_kelas',
                                                'sub'          => 'subcpmk',
                                                'cpmk_id'      => $cpmk->id,
                                                'indikator_id' => $indikator_id,
                                                'cpl_id'       => $current_cpl_id
                                            ]);
                                            $url_detail = new moodle_url('/local/obe/views/admin/dashboard.php', [
                                                'id'            => $courseid,
                                                'id_course'     => $courseid,
                                                'halaman'       => 'capaian_pembelajaran_kelas',
                                                'sub'           => 'detail_nilai_cpmk',
                                                'kompetensi_id' => $cpmk->id,
                                                'indikator_id'  => $indikator_id,
                                                'cpl_id'        => $current_cpl_id
                                            ]);
                                        }
                                        ?>
                                        <a href="<?= $url_sub->out() ?>" class="btn btn-sm btn-primary d-inline-flex align-items-center justify-content-center p-0 rounded-circle" style="width: 30px; height: 30px;" title="Rincian Sub CPMK">
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
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>