<?php
// File: /local/obe/views/dosen/dashboard.php
require_once(__DIR__ . '/../../../../config.php');
require_once(__DIR__ . '/../../lib.php');

use local_obe\manager\aktivitasnilai_manager;

// =====================================================
// PARAMETER
// =====================================================
$courseid = optional_param('id', optional_param('id_course', 0, PARAM_INT), PARAM_INT);
$halaman  = optional_param('halaman', 'beranda', PARAM_ALPHAEXT);

if ($courseid <= 0) {
    throw new moodle_exception('Parameter course tidak ditemukan.');
}

// =====================================================
// VALIDASI COURSE
// =====================================================
list($course, $context) = \local_obe\access_guard::require_course_manage($courseid);

// =====================================================
// AMBIL RELASI KELAS & SET SESSKEY TAHUN AKADEMIK
// =====================================================
$relasi_kelas = $DB->get_record('local_obe_relasi_kelas', ['id_kursus_moodle' => $courseid]);

if ($relasi_kelas && !empty($relasi_kelas->id_tahun_akademik)) {
    $SESSION->obe_id_tahun_akademik = $relasi_kelas->id_tahun_akademik;
}

// =====================================================
// SETUP PAGE ENVIRONMENT MOODLE
// =====================================================
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/obe/views/dosen/dashboard.php', ['id' => $courseid, 'halaman' => $halaman]));
$PAGE->set_title('Manajemen OBE - ' . format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));

// Capability local/obe:managecourse sudah divalidasi oleh access_guard.

// =====================================================
// VALIDASI KURSUS MOODLE SUDAH TERPETAKAN OLEH ADMIN
// =====================================================
if ($halaman !== 'beranda' && !aktivitasnilai_manager::is_course_mapped($courseid)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('Mata kuliah ini belum dihubungkan dengan data OBE. Silakan hubungi Admin.', 'error');
    echo $OUTPUT->footer();
    return;
}

// =====================================================
// PROSES AKSI BACKEND FORM (SEBELUM OUTPUT BUFFER)
// =====================================================
if ($halaman === 'aktivitas_nilai' && optional_param('aksi', '', PARAM_ALPHA) === 'simpan') {
    include(__DIR__ . '/aktivitas_nilai/proses_aktivitasnilai.php');
}

// =====================================================
// OUTPUT BUFFERING UNTUK RENDERING KONTEN INTERNAL
// =====================================================
ob_start();

switch ($halaman) {
    // -------------------------------------------------
    // PEMETAAN AKTIVITAS & BOBOT INSTRUMEN PENILAIAN
    // -------------------------------------------------
    case 'aktivitas_nilai':
        $aksi = optional_param('aksi', '', PARAM_ALPHA);
        if ($aksi === 'tambah' || $aksi === 'edit') {
            include(__DIR__ . '/aktivitas_nilai/form_pemetaan.php');
        } else {
            include(__DIR__ . '/aktivitas_nilai/list_aktivitasnilai.php');
        }
        break;

    // -------------------------------------------------
    // MODUL CAPAIAN LAPORAN OBE (SHARE VIEW DENGAN ADMIN)
    // -------------------------------------------------
    case 'laporan':
        $mode_view = 'dosen';
        $sub = optional_param('sub', 'cpl', PARAM_ALPHAEXT);

        switch ($sub) {
            case 'cpl':
                $path_laporan = $CFG->dirroot . '/local/obe/views/admin/capaian_kelas/list_cpl.php';
                break;
            case 'indikator':
                $path_laporan = $CFG->dirroot . '/local/obe/views/admin/capaian_kelas/list_indikator.php';
                break;
            case 'cpmk':
                $path_laporan = $CFG->dirroot . '/local/obe/views/admin/capaian_kelas/list_cpmk.php';
                break;
            case 'subcpmk':
                $path_laporan = $CFG->dirroot . '/local/obe/views/admin/capaian_kelas/list_subcpmk.php';
                break;
            case 'detail_nilai_cpl':
                $path_laporan = $CFG->dirroot . '/local/obe/views/admin/capaian_kelas/detail_nilai_cpl.php';
                break;
            case 'detail_nilai_indikator':
                $path_laporan = $CFG->dirroot . '/local/obe/views/admin/capaian_kelas/detail_nilai_indikator.php';
                break;
            case 'detail_nilai_cpmk':
                $path_laporan = $CFG->dirroot . '/local/obe/views/admin/capaian_kelas/detail_nilai_cpmk.php';
                break;
            case 'detail_mahasiswa':
                $path_laporan = $CFG->dirroot . '/local/obe/views/admin/capaian_kelas/detail_nilai_subcpmk.php';
                break;
            default:
                $path_laporan = $CFG->dirroot . '/local/obe/views/admin/capaian_kelas/list_cpl.php';
                break;
        }

        if (file_exists($path_laporan)) {
            include_once($path_laporan);
        } else {
            echo "<div class='alert alert-danger'>File laporan tidak ditemukan: " . s($path_laporan) . "</div>";
        }
        break;

    // -------------------------------------------------
    // HALAMAN DEFAULT (LANDING BERANDA DOSEN)
    // -------------------------------------------------
    default:
        echo "<div class='alert alert-info'>Selamat datang di Dashboard OBE Dosen</div>";
        break;
}

$isi_konten = ob_get_clean();

// =====================================================
// ASSEMBLE LAYOUT UTAMA TATA LETAK
// =====================================================
local_obe_render_tata_letak($isi_konten, $halaman);