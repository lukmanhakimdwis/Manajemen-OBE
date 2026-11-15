<?php
// File: /local/obe/views/mahasiswa/dashboard.php

require_once(__DIR__ . '/../../../../config.php');




global $DB, $USER, $SESSION, $OUTPUT, $PAGE, $CFG;

// =====================================================
// PARAMETER (DEFAULT ROUTING TO CPL MODULAR VIEW)
// =====================================================
$courseid = optional_param('id_course', optional_param('id', 0, PARAM_INT), PARAM_INT);
$halaman  = optional_param('halaman', 'cpl', PARAM_ALPHAEXT);

if (empty($courseid)) {
    throw new moodle_exception('Course tidak ditemukan');
}

// =====================================================
// COURSE & CONTEXT
// =====================================================
list($course, $context) = \local_obe\access_guard::require_enrolled_course_user($courseid);

// =====================================================
// PAGE SETUP
// =====================================================
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/obe/views/mahasiswa/dashboard.php', ['id_course' => $courseid, 'halaman' => $halaman]));
$PAGE->set_title('Dashboard Mahasiswa');
$PAGE->set_heading(format_string($course->fullname));

// =====================================================
// RELASI KELAS KURIKULUM
// =====================================================
$relasi = $DB->get_record('local_obe_relasi_kelas', ['id_kursus_moodle' => $courseid]);

if (!$relasi) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('Kelas belum terhubung OBE', 'error');
    echo $OUTPUT->footer();
    exit;
}

$SESSION->obe_id_tahun_akademik = $relasi->id_tahun_akademik;

// =====================================================
// ADAPTER NILAI MAHASISWA (SHARING VIEW REQUIREMENT)
// =====================================================
// =====================================================
// ROUTING SUBPAGE OUTPUT BUFFERING
// =====================================================
ob_start();
$mode_view = 'mahasiswa';

switch ($halaman) {
    case 'beranda':
    case 'cpl':
        include($CFG->dirroot . '/local/obe/views/admin/capaian_kelas/list_cpl.php');
        break;

    case 'indikator':
        include($CFG->dirroot . '/local/obe/views/admin/capaian_kelas/list_indikator.php');
        break;

    case 'cpmk':
        include($CFG->dirroot . '/local/obe/views/admin/capaian_kelas/list_cpmk.php');
        break;

    case 'subcpmk':
        include($CFG->dirroot . '/local/obe/views/admin/capaian_kelas/list_subcpmk.php');
        break;

    case 'detail_mahasiswa':
        include($CFG->dirroot . '/local/obe/views/admin/capaian_kelas/detail_nilai_subcpmk.php');
        break;
}
$content = ob_get_clean();

// =====================================================
// UNIFIED OUTPUT RENDERING
// =====================================================
echo $OUTPUT->header();

if (!empty($content)) {
    echo $content;
}

echo $OUTPUT->footer();
return;