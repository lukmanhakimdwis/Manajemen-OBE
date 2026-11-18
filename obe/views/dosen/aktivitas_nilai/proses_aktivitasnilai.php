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
    if (in_array($aksi,['simpan','update'],true)) {
        $SESSION->obe_mapping_form[$courseid]=['cmid'=>optional_param('cmid',0,PARAM_INT),'sub_cpmk_id'=>optional_param('sub_cpmk_id',0,PARAM_INT),'bobot'=>optional_param('bobot','',PARAM_RAW_TRIMMED)];
        $url->param('aksi',$aksi==='update'?'edit':'tambah');$url->param('map_id',$id);$url->param('cmid',optional_param('cmid',0,PARAM_INT));
    }
    if ($aksi==='hapus') {redirect($url,'Penghapusan ditolak. Aspek mungkin sudah memiliki nilai (termasuk 0) atau riwayat. Gunakan Penggantian aspek; bila referensi hilang, minta pengelola menjalankan audit integritas.',null,\core\output\notification::NOTIFY_ERROR);}
    redirect($url, 'Pemetaan belum tersimpan. Periksa kesesuaian fokus wawancara, Sub-CPMK, kelas/tahun dan sisa bobot. Isian Anda dipertahankan.', null, \core\output\notification::NOTIFY_ERROR);
}
