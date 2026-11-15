<?php
namespace local_obe\controller;

defined('MOODLE_INTERNAL') || die();

use local_obe\manager\prodiframework_manager;
use local_obe\manager\prodi_manager;

class prodiframework_controller {

    /**
     * Simpan mapping framework baru
     */
    public static function eksekusi_simpan() {
        global $DB;

        $id_prodi          = required_param('id_prodi', PARAM_INT);
        $frameworkid       = required_param('frameworkid', PARAM_INT);
        $id_tahun_akademik = required_param('id_tahun_akademik', PARAM_INT);

        // ============================================================
        // ANTI IDOR
        // ============================================================
        $prodi_aktif = prodi_manager::get_prodi_aktif_user();

        if ($prodi_aktif) {
            $id_prodi = (int)$prodi_aktif->id;
        }

        // ============================================================
        // VALIDASI 1 PRODI + 1 TAHUN = 1 FRAMEWORK
        // ============================================================
       if ($DB->record_exists(
                'local_obe_prodi_framework',
                [
                    'id_prodi'          => $id_prodi,
                    'id_tahun_akademik' => $id_tahun_akademik
                ]
            )) {
                throw new \Exception(
                    'Program studi ini sudah memiliki Competency Framework pada tahun akademik tersebut.'
                );
            }

        $data = new \stdClass();
        $data->id_prodi          = $id_prodi;
        $data->frameworkid       = $frameworkid;
        $data->id_tahun_akademik = $id_tahun_akademik;

        return prodiframework_manager::simpan($data);
    }

    

    /**
     * Hapus mapping framework
     */
    public static function eksekusi_hapus($id) {
        global $DB;

        $prodi_aktif = prodi_manager::get_prodi_aktif_user();

        if ($prodi_aktif) {

            $data = prodiframework_manager::ambil_by_id($id);

            if (!$data) {
                throw new \moodle_exception('invalidrecord', 'error');
            }

            if ((int)$data->id_prodi !== (int)$prodi_aktif->id) {
                throw new \moodle_exception(
                    'aksesditolak',
                    'local_obe',
                    '',
                    null,
                    'Aksi ditolak! Anda tidak memiliki hak akses untuk menghapus data pemetaan framework milik program studi lain.'
                );
            }
        }

        return prodiframework_manager::hapus($id);
    }
}