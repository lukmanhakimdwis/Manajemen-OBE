<?php
// File: local/obe/views/admin/capaian_kelas/list_cpl.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\capaian_kelas_manager;
use local_obe\manager\prodi_manager;

global $COURSE, $DB, $OUTPUT, $SESSION, $USER;

// =====================================================
// 1. IDENTIFIKASI MODE VIEW
// =====================================================
$is_dosen_mode     = (isset($mode_view) && $mode_view === 'dosen');
$is_mahasiswa_mode = (isset($mode_view) && $mode_view === 'mahasiswa');

// =====================================================
// 2. TANGKAP PARAMETER COURSE ID
// =====================================================
$courseid = optional_param('id_course', optional_param('id', 0, PARAM_INT), PARAM_INT);

if ($courseid <= 0 && isset($COURSE)) {
    $courseid = $COURSE->id;
}

// =====================================================
// 3. VALIDASI RELASI KELAS & PROTEKSI SERVER (ANTI-IDOR FIRST)
// =====================================================
$prodi_aktif = prodi_manager::get_prodi_aktif_user();

if ($prodi_aktif && !$is_dosen_mode) {
    $sql_relasi = "SELECT rk.*
                     FROM {local_obe_relasi_kelas} rk
                     JOIN {local_obe_mata_kuliah} mk ON mk.id = rk.id_mata_kuliah
                    WHERE rk.id_kursus_moodle = :courseid
                      AND mk.id_prodi = :prodiid";
                      
    $relasi_kelas = $DB->get_record_sql($sql_relasi, [
        'courseid' => $courseid,
        'prodiid'  => $prodi_aktif->id
    ]);

    if (!$relasi_kelas) {
        throw new \moodle_exception(
            'aksesditolak',
            'local_obe',
            '',
            null,
            'Aksi ditolak! Anda tidak memiliki hak akses untuk melihat data capaian pembelajaran kelas dari program studi lain.'
        );
    }
} else {
    $relasi_kelas = $DB->get_record('local_obe_relasi_kelas', ['id_kursus_moodle' => $courseid], '*', IGNORE_MULTIPLE);
}

// =====================================================
// 4. AMBIL DATA COURSE MOODLE CORE & SET TAHUN AKADEMIK
// =====================================================
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

$tahun_akademik = 0;
if ($relasi_kelas) {
    $tahun_akademik = $relasi_kelas->id_tahun_akademik;
    $SESSION->obe_id_tahun_akademik = $tahun_akademik;
}

// =====================================================
// 5. PENARIKAN STRUKTUR ROOT CPL (DENGAN FILTER PRODI)
// =====================================================
// 5a. Dapatkan id_prodi berdasarkan courseid kelas ini
$prodi_id = $DB->get_field_sql("
    SELECT mk.id_prodi
      FROM {local_obe_relasi_kelas} rk
      JOIN {local_obe_mata_kuliah} mk ON rk.id_mata_kuliah = mk.id
     WHERE rk.id_kursus_moodle = :courseid
", ['courseid' => $courseid], IGNORE_MULTIPLE);

// 5b. Ambil CPL tingkat root (parent_id = 0) yang HANYA milik prodi & tahun akademik bersangkutan
$list_cpl = [];
if (!empty($prodi_id)) {
    $list_cpl = $DB->get_records('local_obe_kompetensi', [
        'parent_id'         => 0,
        'tipe'              => 'CPL',
        'id_prodi'          => $prodi_id, // Pengunci utama agar prodi lain tidak bocor
        'id_tahun_akademik' => $tahun_akademik
    ], 'id ASC');
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
?>

<div class="card mb-4 shadow-sm border-0">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <h5 class="mb-0 font-weight-bold text-dark">
            <i class="fa fa-bar-chart text-primary mr-2"></i>
            <?php
            if ($is_mahasiswa_mode) {
                echo 'Capaian Pembelajaran Lulusan (CPL) Saya - ' . s($course->fullname);
            } else {
                echo $is_dosen_mode ? 'Laporan CPL - ' . s($course->fullname) : 'Capaian Pembelajaran Lulusan (CPL) - ' . s($course->fullname);
            }
            ?>
        </h5>
        <div class="d-flex align-items-center">
            <?php if (!$is_dosen_mode && !$is_mahasiswa_mode): ?>
                <?php
                $url_export_rekap = new moodle_url('/local/obe/export/export_capaian_kelas_csv.php', [
                    'courseid' => $courseid,
                    'sesskey'  => sesskey()
                ]);

                $url_kembali = new moodle_url('/local/obe/views/admin/dashboard.php', [
                    'halaman' => 'capaian_pembelajaran_kelas'
                ]);
                  $url_grafik = new moodle_url('/local/obe/views/admin/dashboard.php', [
                    'id'        => $courseid,
                    'id_course' => $courseid,
                    'halaman'   => 'capaian_pembelajaran_kelas',
                    'sub'       => 'grafik_cpl'
                ]);
                ?>
              
                <a href="<?= $url_grafik->out() ?>"  class="btn btn-primary btn-sm font-weight-bold shadow-sm mr-2"> <i class=""></i> Grafik CPL</a>

                <a href="<?= $url_export_rekap->out() ?>"  class="btn btn-success btn-sm font-weight-bold shadow-sm mr-2"> <i class="fa fa-file-excel"></i> Unduh data kelas</a>
                <a href="<?= $url_kembali->out() ?>" class="btn btn-sm btn-outline-secondary font-weight-bold shadow-sm">
                    <i class="fa fa-arrow-left mr-1"></i> Kembali
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-body p-0">
        <?php if (!$relasi_kelas): ?>
            <div class="alert alert-danger m-3 border-0 shadow-sm">
                <i class="fa fa-exclamation-circle mr-1"></i> Relasi pemetaan kelas kurikulum OBE belum ditemukan untuk mata kuliah ini.
            </div>
        <?php elseif (empty($list_cpl)): ?>
            <div class="alert alert-warning m-3 text-center py-4 border-0 shadow-sm">
                <i class="fa fa-exclamation-triangle mr-1"></i> Belum ada data capaian CPL yang terekam untuk program studi dan tahun akademik ini.
            </div>
        <?php else: ?>
            <div class="table-responsive bg-white rounded p-3">
                <table class="table table-hover align-middle mb-0">
                    <thead class="thead-light">
                        <tr class="text-uppercase small text-center" style="letter-spacing: 0.5px;">
                            <th class="py-3 pl-2 text-left" style="width: 25%;">Nama/Kode CPL</th>
                            <th style="width: 30%;">
                                <?= $is_mahasiswa_mode ? 'Capaian Saya' : 'Rata-rata Capaian & Kelulusan Kelas' ?>
                            </th>
                            <th class="text-left py-3" style="width: 30%;">Rincian Indikator Utama Pendukung</th>
                            <th style="width: 10%;">Grade</th>
                            <th style="width: 5%;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($list_cpl as $cpl): ?>
                            <?php
                            // Tembak SSoT Fondasi Utama Menggunakan Properti Baru $cpl->id
                            $hasil = capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($courseid, $cpl->id, 'CPL', $tahun_akademik);
                            $ringkasan = $hasil->ringkasan;
                            $detail_mahasiswa = $hasil->detail_mahasiswa;

                            // Tarik list anak indikator murni via Helper Relasi Kompetensi
                            $list_indikator = capaian_kelas_manager::ambil_struktur_kompetensi_kelas($cpl->id, 'INDIKATOR', $tahun_akademik);

                            // Penentuan nilai akumulatif awal berdasarkan konteks global kelas
                            $nilai_tampil = $ringkasan->rata_rata ?? 0;
                            $grade_tampil = $ringkasan->grade ?? '-';

                            if ($is_mahasiswa_mode && !empty($detail_mahasiswa)) {
                                $nilai_tampil = 0;
                                $grade_tampil = '-';
                                foreach ($detail_mahasiswa as $mhs) {
                                    if ((int)$mhs->userid === (int)$USER->id) {
                                        $nilai_tampil = $mhs->nilai;
                                        $grade_tampil = $mhs->grade;
                                        break;
                                    }
                                }
                            }

                            // Pemetaan Class Warna Badge
                            $badge_class = 'secondary';
                            switch (strtoupper(trim($grade_tampil))) {
                                case 'A': case 'AB': case 'SB': $badge_class = 'success'; break;
                                case 'B': case 'BC':             $badge_class = 'primary'; break;
                                case 'C':                         $badge_class = 'warning'; break;
                                case 'D': case 'E': case 'K':     $badge_class = 'danger'; break;
                            }
                            ?>
                            <tr>
                                <td class="py-3 pl-2">
                                    <div class="font-weight-bold text-primary" style="font-size: 0.95rem;">
                                        <?= s($cpl->nama_kompetensi) ?>
                                    </div>
                                    <?php if (!$is_mahasiswa_mode): ?>
                                        <small class="text-muted d-block mt-1">
                                            <i class="fa fa-users mr-1"></i> Total Peserta: <strong><?= $ringkasan->jumlah_mahasiswa ?? 0 ?></strong> mhs
                                        </small>
                                    <?php endif; ?>
                                </td>

                                <td class="text-center py-3">
                                    <div class="h4 font-weight-bold text-dark mb-1">
                                        <?= $format_angka($nilai_tampil) ?>
                                    </div>
                                    <?php if (!$is_mahasiswa_mode && !empty($ringkasan)): ?>
                                        <div class="px-3">
                                            <div class="mb-1" style="font-size: 11px; color: #555;">
                                                <span>Kompeten: </span>
                                                <span class="font-weight-bold text-dark">
                                                    <?= $ringkasan->jumlah_kompeten ?> / <?= $ringkasan->jumlah_mahasiswa ?> mhs (<?= $format_angka($ringkasan->persentase_kompeten) ?>%)
                                                </span>
                                            </div>
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar bg-success" role="progressbar" style="width: <?= $format_angka($ringkasan->persentase_kompeten) ?>%;"></div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td class="py-3 text-left">
                                    <?php if (empty($list_indikator)): ?>
                                        <span class="text-muted small">
                                            <i class="fa fa-ban mr-1"></i> Tidak ada hubungan indikator
                                        </span>
                                    <?php else: ?>
                                        <ul class="list-unstyled mb-0 small">
                                            <?php foreach ($list_indikator as $ind): ?>
                                                <li class="mb-1 d-flex align-items-baseline">
                                                    <i class="fa fa-caret-right text-info mr-2"></i>
                                                    <div class="text-dark">
                                                        <?= s($ind->nama) ?>
                                                        <span class="text-muted font-weight-bold ml-1">
                                                            (Bobot: <?= $format_angka($ind->bobot) ?>%)
                                                        </span>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
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
                                                'id_course' => $courseid,
                                                'halaman'   => 'indikator',
                                                'cpl_id'    => $cpl->id
                                            ]);
                                        } else if ($is_dosen_mode) {
                                            $url_lihat = new moodle_url('/local/obe/views/dosen/dashboard.php', [
                                                'id_course' => $courseid,
                                                'halaman'   => 'laporan',
                                                'sub'       => 'indikator',
                                                'cpl_id'    => $cpl->id
                                            ]);
                                            $url_detail = new moodle_url('/local/obe/views/dosen/dashboard.php', [
                                                'id_course'     => $courseid,
                                                'halaman'       => 'laporan',
                                                'sub'           => 'detail_nilai_cpl',
                                                'tipe'          => 'CPL',
                                                'kompetensi_id' => $cpl->id
                                            ]);
                                        } else {
                                            $url_lihat = new moodle_url('/local/obe/views/admin/dashboard.php', [
                                                'id'        => $courseid,
                                                'halaman'   => 'capaian_pembelajaran_kelas',
                                                'sub'       => 'indikator',
                                                'cpl_id'    => $cpl->id
                                            ]);
                                            $url_detail = new moodle_url('/local/obe/views/admin/dashboard.php', [
                                                'id'            => $courseid,
                                                'id_course'     => $courseid,
                                                'halaman'       => 'capaian_pembelajaran_kelas',
                                                'sub'           => 'detail_nilai_cpl',
                                                'tipe'          => 'CPL',
                                                'kompetensi_id' => $cpl->id
                                            ]);
                                        }
                                        ?>
                                        <a href="<?= $url_lihat->out() ?>" class="btn btn-sm btn-primary d-inline-flex align-items-center justify-content-center p-0 rounded-circle" style="width: 30px; height: 30px;" title="Rincian Indikator">
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