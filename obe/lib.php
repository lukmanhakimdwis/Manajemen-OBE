<?php
defined('MOODLE_INTERNAL') || die();

/**
 * HOOK 1: Settings Navigation (sidebar settings)
 */
function local_obe_extend_settings_navigation(settings_navigation $settingsnav, context $context) {
    global $PAGE;

    // --- MENU ADMIN ---
    if (isloggedin() && !isguestuser() && (\local_obe\access_guard::is_system_manager() || (\local_obe\access_guard::get_kaprodi_prodi() && \local_obe\access_guard::has_any_system_capability(['local/obe:manageprogram','local/obe:viewprogramreport','local/obe:configprogramscale'])))) {
        $siteadmin = $settingsnav->find('root', navigation_node::TYPE_SITE_ADMIN);
        if (!$siteadmin) {
            $siteadmin = $settingsnav->find('siteadmin', navigation_node::TYPE_SITE_ADMIN);
        }
        $siteadmin=$siteadmin?:$settingsnav;
        if (!$siteadmin->find('obe_admin', navigation_node::TYPE_SETTING)) {
            $url_admin = new moodle_url('/local/obe/views/admin/dashboard.php');
            $siteadmin->add(
                'OBE Program Studi',
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
    if ((has_capability('local/obe:managecourse', $context) || has_capability('local/obe:viewreport', $context))) {
        if (!$navigation->find('obe_dosen_tab', navigation_node::TYPE_SETTING)) {
            $url = new moodle_url('/local/obe/views/dosen/dashboard.php', [
                'id'      => $course->id,
                'halaman' => 'beranda'
            ]);

            $navigation->add(
                'OBE Kelas',
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
            is_enrolled($context, $USER) && has_capability('local/obe:viewownreport', $context) &&
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
 * Protect history before Moodle destroys module grades. Empty mappings stay
 * reserved until core actually removes course_modules, including async deletion.
 * @param stdClass $cm Course module supplied by course_delete_module.
 */
function local_obe_pre_course_module_delete($cm) {
    \local_obe\local\assessment_integrity::before_module_delete($cm);
}

/**
 * Render tata letak kustom OBE
 */
function local_obe_render_tata_letak($konten, $halaman_aktif = '') {
    global $OUTPUT, $PAGE, $CFG;
    $GLOBALS['obe_halaman_aktif'] = $halaman_aktif;

    $PAGE->requires->js(new moodle_url('/local/obe/accessibility.js',['v'=>substr(hash_file('sha256',$CFG->dirroot.'/local/obe/accessibility.js'),0,12)]));
    echo $OUTPUT->header();
    echo '<div class="obe-workspace container-fluid mt-3"><div class="row">';
    echo '<div class="col-md-3 mb-4">';
    require(__DIR__ . '/layout/sidebar.php');
    echo '</div>';
    echo '<div class="col-md-9">' . $konten . '</div>';
    echo '</div></div>';
    echo $OUTPUT->footer();
}

