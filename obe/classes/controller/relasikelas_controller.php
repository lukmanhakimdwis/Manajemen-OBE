<?php
namespace local_obe\controller;

defined('MOODLE_INTERNAL') || die();

use local_obe\manager\relasikelas_manager;
use local_obe\manager\prodi_manager;

class relasikelas_controller {
    
    /**
     * Mengeksekusi penyimpanan massal relasi kelas Moodle baru
     */
    public static function eksekusi_simpan() {
        global $DB, $SESSION;

        $courseids      = required_param_array('id_kursus_moodle', PARAM_INT);
        $id_mata_kuliah = required_param('id_mata_kuliah', PARAM_INT);
        $id_angkatan    = required_param('id_angkatan', PARAM_INT);

        $id_tahun_akademik = optional_param('id_tahun_akademik', 0, PARAM_INT);
        $id_tahun_akademik = ($id_tahun_akademik > 0)
            ? $id_tahun_akademik
            : ($SESSION->obe_id_tahun_akademik ?? 0);

        // =========================================================================
        // BACK-END VALIDATION (ANTI-IDOR): Validasi Kepemilikan MK saat Simpan
        // =========================================================================
        $prodi_aktif = prodi_manager::get_prodi_aktif_user();
        if ($prodi_aktif) {
            $mk = $DB->get_record('local_obe_mata_kuliah', ['id' => $id_mata_kuliah]);
            if (!$mk || (int)$mk->id_prodi !== (int)$prodi_aktif->id) {
                throw new \moodle_exception('aksesditolak', 'local_obe', '', null, 'Aksi ditolak! Anda tidak memiliki hak akses untuk menambahkan relasi kelas pada mata kuliah prodi lain.');
            }
        }

        foreach ($courseids as $courseid) {
            if ($courseid <= 0) {
                continue;
            }

            // Cegah redundansi pemetaan relasi ganda pada kelas Moodle yang sama
            if ($DB->record_exists('local_obe_relasi_kelas', ['id_kursus_moodle' => $courseid])) {
                continue;
            }

            $data = new \stdClass();
            $data->id_mata_kuliah    = $id_mata_kuliah;
            $data->id_kursus_moodle  = $courseid;
            $data->id_angkatan       = $id_angkatan;
            $data->id_tahun_akademik = $id_tahun_akademik;
            $data->waktu_koneksi     = time();

            relasikelas_manager::simpan($data);
        }

        return true;
    }
    
    /**
     * Mengeksekusi pembaruan data relasi kelas tunggal
     */
    public static function eksekusi_update() {
        global $DB, $SESSION;
        
        $id             = required_param('id', PARAM_INT);
        $id_mata_kuliah = required_param('id_mata_kuliah', PARAM_INT);

        // =========================================================================
        // BACK-END VALIDATION (ANTI-IDOR): Validasi Kepemilikan MK saat Update
        // =========================================================================
        $prodi_aktif = prodi_manager::get_prodi_aktif_user();
        if ($prodi_aktif) {
            $mk = $DB->get_record('local_obe_mata_kuliah', ['id' => $id_mata_kuliah]);
            if (!$mk || (int)$mk->id_prodi !== (int)$prodi_aktif->id) {
                throw new \moodle_exception('aksesditolak', 'local_obe', '', null, 'Aksi ditolak! Anda tidak memiliki hak akses untuk memperbarui relasi kelas pada mata kuliah prodi lain.');
            }
        }

        $data = new \stdClass();
        $data->id               = $id;
        $data->id_mata_kuliah   = $id_mata_kuliah;
        $data->id_angkatan      = required_param('id_angkatan', PARAM_INT);
        $data->id_kursus_moodle = required_param('id_kursus_moodle', PARAM_INT);
        
        $id_tahun_akademik = optional_param('id_tahun_akademik', 0, PARAM_INT);
        $data->id_tahun_akademik = ($id_tahun_akademik > 0) 
            ? $id_tahun_akademik 
            : ($SESSION->obe_id_tahun_akademik ?? 0);
        
        return relasikelas_manager::update($data);
    }

    /**
     * Mengeksekusi penghapusan rekaman relasi kelas tunggal terproteksi prodi aktif
     */
    public static function eksekusi_hapus($id) {
        global $DB;

        // =========================================================================
        // BACK-END VALIDATION (ANTI-IDOR): Validasi Kepemilikan MK saat Hapus
        // =========================================================================
        $prodi_aktif = prodi_manager::get_prodi_aktif_user();
        if ($prodi_aktif) {
            $relasi = relasikelas_manager::ambil_satu($id);
            if (!$relasi) {
                throw new \moodle_exception('invalidrecord', 'error');
            }

            $mk = $DB->get_record('local_obe_mata_kuliah', ['id' => $relasi->id_mata_kuliah]);
            if (!$mk || (int)$mk->id_prodi !== (int)$prodi_aktif->id) {
                throw new \moodle_exception('aksesditolak', 'local_obe', '', null, 'Aksi ditolak! Anda tidak memiliki hak akses untuk menghapus data relasi kelas milik program studi lain.');
            }
        }

        return relasikelas_manager::hapus($id);
    }
}