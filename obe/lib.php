<?php
defined('MOODLE_INTERNAL') || die();

/**
 * HOOK 1: Settings Navigation (sidebar settings)
 */
function local_obe_extend_settings_navigation(settings_navigation $settingsnav, context $context) {
    global $PAGE;

    // --- MENU ADMIN ---
    if (has_capability('local/obe:manage', context_system::instance())) {
        $siteadmin = $settingsnav->find('root', navigation_node::TYPE_SITE_ADMIN);
        if (!$siteadmin) {
            $siteadmin = $settingsnav->find('siteadmin', navigation_node::TYPE_SITE_ADMIN);
        }
        if ($siteadmin && !$siteadmin->find('obe_admin', navigation_node::TYPE_SETTING)) {
            $url_admin = new moodle_url('/local/obe/views/admin/dashboard.php');
            $siteadmin->add(
                'Manajemen OBE (Admin)',
                $url_admin,
                navigation_node::TYPE_SETTING,
                null,
                'obe_admin',
                new pix_icon('i/settings', '')
            );
        }
    }
}

/**
 * HOOK 2: Course Navigation Tab — Moodle 4.x
 * (ditampilkan di tab "Selengkapnya" di dalam course untuk dosen dan mahasiswa)
 */
function local_obe_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context) {
    global $USER;

    // 1. Menu untuk Dosen atau Admin Mata Kuliah
    if (has_capability('local/obe:managecourse', $context)) {
        if (!$navigation->find('obe_dosen_tab', navigation_node::TYPE_SETTING)) {
            $url = new moodle_url('/local/obe/views/dosen/dashboard.php', [
                'id'      => $course->id,
                'halaman' => 'aktivitas_nilai'
            ]);

            $navigation->add(
                'Manajemen OBE',
                $url,
                navigation_node::TYPE_SETTING,
                null,
                'obe_dosen_tab',
                new pix_icon('i/settings', '')
            );
        }
    }
    // 2. Menu untuk Mahasiswa
    
else {

        // Pastikan user adalah mahasiswa
        if (
            is_enrolled($context, $USER) &&
            !has_capability('moodle/course:update', $context)
        ) {

            // Hindari duplicate menu
            if (
                !$navigation->find(
                    'obe_mahasiswa_tab',
                    navigation_node::TYPE_SETTING
                )
            ) {

                $url = new moodle_url(
                    '/local/obe/views/mahasiswa/dashboard.php',
                    [
                        'id_course' => $course->id,
                        'halaman'   => 'beranda'
                    ]
                );

                $navigation->add(
                    'OBE Mahasiswa',
                    $url,
                    navigation_node::TYPE_SETTING,
                    'Monitoring capaian pembelajaran mahasiswa',
                    'obe_mahasiswa_tab',
                    new pix_icon('i/report', '')
                );
            }
        }
    }
}

/**
 * HOOK 3: Course Module Deletion Handler
 * Menghapus relasi di tabel aktivitas saat suatu aktivitas (kuis, tugas, dll) dihapus dari Course.
 * @param int $cmid ID dari tabel course_modules
 */
function local_obe_pre_course_module_delete($cmid) {
    global $DB;
    
    $DB->delete_records('local_obe_aktivitas_map', ['cmid' => $cmid]);
}

/**
 * Render tata letak kustom OBE
 */
function local_obe_render_tata_letak($konten, $halaman_aktif = '') {
    global $OUTPUT;
    $GLOBALS['obe_halaman_aktif'] = $halaman_aktif;

    echo $OUTPUT->header();
    echo '<div class="container-fluid mt-3"><div class="row">';
    echo '<div class="col-md-3 mb-4">';
    require(__DIR__ . '/layout/sidebar.php');
    echo '</div>';
    echo '<div class="col-md-9">' . $konten . '</div>';
    echo '</div></div>';
    echo $OUTPUT->footer();
}
