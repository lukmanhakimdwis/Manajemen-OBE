<?php
// File: local/obe/views/admin/capaian_kelas/list_subcpmk.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\capaian_kelas_manager;
use local_obe\manager\prodi_manager;
use local_obe\manager\kompetensi_manager;

global $COURSE, $DB, $OUTPUT, $SESSION, $USER;

// =====================================================
// 1. IDENTIFIKASI MODE LOGIN
// =====================================================
$is_dosen_mode     = (isset($mode_view) && $mode_view === 'dosen');
$is_mahasiswa_mode = (isset($mode_view) && $mode_view === 'mahasiswa');

// =====================================================
// 2. COURSE VALIDASI & STRUKTUR PARAMETER
// =====================================================
$courseid = optional_param('id_course', optional_param('id', optional_param('courseid', 0, PARAM_INT), PARAM_INT), PARAM_INT);
if ($courseid == 0 && isset($COURSE)) {
    $courseid = $COURSE->id;
}

$cpmk_id        = optional_param('cpmk_id', 0, PARAM_INT);
$indikator_id   = optional_param('indikator_id', 0, PARAM_INT);
$cpl_id         = optional_param('cpl_id', 0, PARAM_INT);
$tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;

$cpmk_record = $DB->get_record('local_obe_kompetensi', ['id' => $cpmk_id]);

// =========================================================================
// 3. VALIDASI KEAMANAN SERVER (ANTI-IDOR HIERARCHY SHIELD)
// =========================================================================
$prodi_aktif = prodi_manager::get_prodi_aktif_user();

if ($prodi_aktif && !$is_dosen_mode) {
    if (!$cpmk_record) {
        throw new moodle_exception('invalidrecord', 'error', '', null, 'Data CPMK tidak ditemukan.');
    }

    if ((int)$cpmk_record->id_prodi !== (int)$prodi_aktif->id) {
        throw new moodle_exception(
            'aksesditolak',
            'local_obe',
            '',
            null,
            'Aksi ditolak! Anda tidak memiliki hak akses untuk melihat CPMK program studi lain.'
        );
    }

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
        throw new moodle_exception(
            'aksesditolak',
            'local_obe',
            '',
            null,
            'Aksi ditolak! Anda tidak memiliki hak akses untuk melihat kelas program studi lain.'
        );
    }
}

// =====================================================
// 4. TARIK STRUKTUR KOMPETENSI & TOTAL PARENT CPMK 
// =====================================================
// Mengambil array murni struktur kurikulum kompetensi anak
$sub_data = capaian_kelas_manager::ambil_struktur_kompetensi_kelas($cpmk_id, 'SUB-CPMK', $tahun_akademik);

// Tarik data pencapaian level induk CPMK dari SSoT
$detail_cpmk = capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($courseid, $cpmk_id, 'CPMK', $tahun_akademik);

$total_cpmk_value = $detail_cpmk->ringkasan->rata_rata ?? 0;

// Override total parent jika diakses dalam mode personal mahasiswa
if ($is_mahasiswa_mode && !empty($detail_cpmk->detail_mahasiswa)) {
    $total_cpmk_value = 0;
    foreach ($detail_cpmk->detail_mahasiswa as $mhs) {
        if ((int)$mhs->userid === (int)$USER->id) {
            $total_cpmk_value = $mhs->nilai;
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
// 5. CONFIG URL TOMBOL KEMBALI & GRAFIK
// =====================================================
if ($is_mahasiswa_mode) {
    $url_kembali = new moodle_url('/local/obe/views/mahasiswa/dashboard.php', [
        'id_course'    => $courseid,
        'halaman'      => 'cpmk',
        'indikator_id' => $indikator_id,
        'cpl_id'       => $cpl_id
    ]);
} elseif ($is_dosen_mode) {
    $url_kembali = new moodle_url('/local/obe/views/dosen/dashboard.php', [
        'id_course'    => $courseid,
        'halaman'      => 'laporan',
        'sub'          => 'cpmk',
        'indikator_id' => $indikator_id,
        'cpl_id'       => $cpl_id
    ]);
    $url_grafik = new moodle_url('/local/obe/views/dosen/dashboard.php', [
        'id_course'    => $courseid,
        'halaman'      => 'laporan',
        'sub'          => 'grafik_subcpmk',
        'cpmk_id'      => $cpmk_id,
        'indikator_id' => $indikator_id,
        'cpl_id'       => $cpl_id
    ]);
} else {
    $url_kembali = new moodle_url('/local/obe/views/admin/dashboard.php', [
        'id'           => $courseid,
        'id_course'    => $courseid,
        'halaman'      => 'capaian_pembelajaran_kelas',
        'sub'          => 'cpmk',
        'indikator_id' => $indikator_id,
        'cpl_id'       => $cpl_id
    ]);
    $url_grafik = new moodle_url('/local/obe/views/admin/dashboard.php', [
        'id'           => $courseid,
        'id_course'    => $courseid,
        'halaman'      => 'capaian_pembelajaran_kelas',
        'sub'          => 'grafik_subcpmk',
        'cpmk_id'      => $cpmk_id,
        'indikator_id' => $indikator_id,
        'cpl_id'       => $cpl_id
    ]);
}
?>

<div class="card mb-4 shadow-sm border-0">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <h5 class="mb-0 font-weight-bold text-dark">
            <i class="fa fa-sitemap text-primary mr-2"></i><?= $is_mahasiswa_mode ? 'Capaian Sub CPMK Saya' : 'Rincian Sub CPMK Kelas' ?>
        </h5>
        <div class="d-flex align-items-center" style="gap: 8px;">
            <?php if (!$is_mahasiswa_mode): ?>
                <a href="<?= $url_grafik->out() ?>" class="btn btn-sm btn-primary font-weight-bold shadow-sm">
                    <i class="fa fa-bar-chart mr-1"></i> Grafik Sub CPMK
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
                <div>
                    <h6 class="font-weight-bold text-dark mb-1">
                        <i class="fa fa-cube text-primary mr-2"></i>
                        CPMK: <?= $cpmk_record ? s($cpmk_record->nama_kompetensi) : '-' ?>
                    </h6>
                    <?php if (!empty($cpmk_record->deskripsi)): ?>
                        <div class="text-muted small ml-4">
                            <?= format_text($cpmk_record->deskripsi, FORMAT_HTML) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-4 text-right px-0">
                <span class="text-muted small font-weight-bold text-uppercase d-block mb-1" style="letter-spacing: 0.5px; font-size: 0.7rem;">
                    <?= $is_mahasiswa_mode ? 'Capaian CPMK Saya' : 'Nilai Total CPMK Kelas' ?>
                </span>
                <span class="badge badge-primary px-3 py-2 font-weight-bold" style="font-size: 1rem;">
                    <?= $format_angka($total_cpmk_value) ?>
                </span>
            </div>
        </div>
    </div>

    <div class="card-body p-0">
        <?php if (empty($sub_data)): ?>
            <div class="alert alert-warning text-center py-4 m-3 border-0 shadow-sm">
                <i class="fa fa-exclamation-triangle mr-1"></i> Belum ada data Sub CPMK untuk kompetensi ini.
            </div>
        <?php else: ?>
            <div class="table-responsive bg-white rounded p-3">
                <table class="table table-hover align-middle mb-0">
                    <thead class="thead-light">
                        <tr class="text-uppercase small text-center" style="letter-spacing: 0.5px;">
                            <th class="text-left py-3 pl-4" style="width: 35%;">Nama Sub CPMK</th>
                            <th style="width: 10%;">Bobot</th>
                            <th style="width: 20%;"><?= $is_mahasiswa_mode ? 'Nilai Saya' : 'Rata-rata & Kelulusan' ?></th>
                            <th style="width: 12%;">Kontribusi</th>
                            <th style="width: 10%;">Grade</th>
                            <th style="width: 13%;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sub_data as $sub): ?>
                            <?php
                            $detail_sub = capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($courseid, $sub->id, 'SUB-CPMK', $tahun_akademik);
                            $ringkasan  = $detail_sub->ringkasan;

                            $nilai_dasar = $ringkasan->rata_rata;
                            $grade_tampil = $ringkasan->grade;

                            if ($is_mahasiswa_mode) {
                                $nilai_dasar   = 0;
                                $grade_tampil = '-';
                                if (!empty($detail_sub->detail_mahasiswa)) {
                                    foreach ($detail_sub->detail_mahasiswa as $mhs_item) {
                                        if ((int)$mhs_item->userid === (int)$USER->id) {
                                            $nilai_dasar   = $mhs_item->nilai;
                                            $grade_tampil = $mhs_item->grade;
                                            break;
                                        }
                                    }
                                }
                            }

                            $kontribusi = $nilai_dasar * ($sub->bobot / 100);

                            $badge_class = 'secondary';
                            switch (strtoupper(trim($grade_tampil))) {
                                case 'A': case 'AB': case 'SB': $badge_class = 'success'; break;
                                case 'B': case 'BC':             $badge_class = 'primary'; break;
                                case 'C':                         $badge_class = 'warning'; break;
                                case 'D': case 'E': case 'K':    $badge_class = 'danger'; break;
                            }
                            ?>
                            <tr class="text-center">
                                <td class="text-left pl-4 py-3">
                                    <div class="font-weight-bold text-dark mb-1" style="font-size: 0.95rem;">
                                        <i class="fa fa-caret-right text-info mr-2"></i><?= s($sub->nama) ?>
                                    </div>
                                    <?php if (!$is_dosen_mode && !$is_mahasiswa_mode): ?>
                                        <small class="text-muted font-weight-bold text-uppercase" style="font-size: 0.65rem;">
                                            <i class="fa fa-users mr-1"></i> Total Peserta: <strong><?= $ringkasan->jumlah_mahasiswa ?></strong> mhs
                                        </small>
                                    <?php endif; ?>
                                </td>

                                <td class="py-3">
                                    <span class="badge badge-secondary font-weight-bold px-2 py-1">
                                        <?= $format_angka($sub->bobot) ?>%
                                    </span>
                                </td>

                                <td class="py-3">
                                    <div class="h4 font-weight-bold text-dark mb-1">
                                        <?= $format_angka($nilai_dasar) ?>
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

                                <td class="text-center py-3 font-weight-bold text-info" style="font-size: 0.95rem;">
                                    <?= $format_angka($kontribusi) ?>
                                </td>

                                <td class="py-3">
                                    <span class="badge badge-<?= $badge_class ?> px-3 py-2" style="font-size: 0.85rem; font-weight: 600; min-width: 45px;">
                                        <?= s($grade_tampil) ?>
                                    </span>
                                </td>

                                <td class="py-3">
                                    <?php 
                                    if ($is_mahasiswa_mode) {
                                        $url = new moodle_url('/local/obe/views/mahasiswa/dashboard.php', [
                                            'id_course'   => $courseid,
                                            'halaman'     => 'detail_mahasiswa',
                                            'sub_cpmk_id' => $sub->id,
                                            'cpmk_id'     => $cpmk_id,
                                            'indikator_id'=> $indikator_id,
                                            'cpl_id'      => $cpl_id
                                        ]);
                                    } elseif ($is_dosen_mode) {
                                        $url = new moodle_url('/local/obe/views/dosen/dashboard.php', [
                                            'id_course'   => $courseid,
                                            'halaman'     => 'laporan',
                                            'sub'         => 'detail_mahasiswa',
                                            'sub_cpmk_id' => $sub->id,
                                            'cpmk_id'     => $cpmk_id,
                                            'indikator_id'=> $indikator_id,
                                            'cpl_id'      => $cpl_id
                                        ]);
                                    } else {
                                        $url = new moodle_url('/local/obe/views/admin/dashboard.php', [
                                            'id'          => $courseid,
                                            'id_course'   => $courseid,
                                            'halaman'     => 'capaian_pembelajaran_kelas',
                                            'sub'         => 'detail_mahasiswa',
                                            'sub_cpmk_id' => $sub->id,
                                            'cpmk_id'     => $cpmk_id,
                                            'indikator_id'=> $indikator_id,
                                            'cpl_id'      => $cpl_id
                                        ]);
                                    }
                                    ?>
                                    <a href="<?= $url->out() ?>" class="btn btn-sm btn-primary font-weight-bold px-3 shadow-sm">
                                        Detail
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>