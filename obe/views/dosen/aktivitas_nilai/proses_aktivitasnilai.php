<?php
require_once(dirname(__DIR__, 5) . '/config.php');

use local_obe\controller\aktivitasnilai_controller;

$courseid = required_param('courseid', PARAM_INT);
list($course, $context) = \local_obe\access_guard::require_course_manage($courseid);
require_sesskey();

$aksi = optional_param('aksi', '', PARAM_ALPHA);
$id   = optional_param('id', 0, PARAM_INT);

$url = new moodle_url('/local/obe/views/dosen/dashboard.php', [
    'id'      => $courseid,
    'halaman' => 'aktivitas_nilai'
]);

try {
    switch ($aksi) {
        case 'simpan':
            aktivitasnilai_controller::eksekusi_simpan($courseid);
            redirect($url, 'Pemetaan berhasil ditambahkan.', 2, \core\output\notification::NOTIFY_SUCCESS);
            break;

        case 'update':
            aktivitasnilai_controller::eksekusi_update($courseid);
            redirect($url, 'Pemetaan berhasil diperbarui.', 2, \core\output\notification::NOTIFY_SUCCESS);
            break;

        case 'hapus':
            $map = $DB->get_record('local_obe_aktivitas_map', ['id' => $id], '*', MUST_EXIST);
            get_coursemodule_from_id('', $map->cmid, $courseid, false, MUST_EXIST);
            aktivitasnilai_controller::eksekusi_hapus($id);
            redirect($url, 'Pemetaan berhasil dihapus.', 2, \core\output\notification::NOTIFY_SUCCESS);
            break;

        default:
            redirect($url);
            break;
    }
} catch (Exception $e) {
    redirect($url, $e->getMessage(), 3, \core\output\notification::NOTIFY_ERROR);
}
