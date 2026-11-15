<?php
namespace local_obe\manager;

defined('MOODLE_INTERNAL') || die();

class tahun_akademik_manager {

   
    public static function ambil_semua() {

        global $DB;

        return $DB->get_records(
            'local_obe_tahun_akademik',
            null,
            'nama_tahun DESC'
        );
    }

    public static function simpan($nama_tahun) {

        global $DB;

        // =============================================
        // VALIDASI KOSONG
        // =============================================

        if (empty(trim($nama_tahun))) {

            throw new \moodle_exception(
                'Nama tahun akademik wajib diisi.'
            );
        }

        // =============================================
        // VALIDASI DUPLIKAT
        // =============================================

        $exists = $DB->record_exists(
            'local_obe_tahun_akademik',
            [
                'nama_tahun' => trim($nama_tahun)
            ]
        );

        if ($exists) {

            throw new \moodle_exception(
                'Tahun akademik sudah tersedia.'
            );
        }

        // =============================================
        // SIMPAN DATA
        // =============================================

        $record = new \stdClass();

        $record->nama_tahun =
            trim($nama_tahun);

        $record->status_aktif =
            0;

        return $DB->insert_record(
            'local_obe_tahun_akademik',
            $record
        );
    }


    public static function memiliki_relasi($id) {
        global $DB;
        // =============================================
        // RELASI KELAS
        // =============================================
        if ($DB->record_exists(
            'local_obe_relasi_kelas',
            [
                'id_tahun_akademik' => $id
            ]
        )) {
            return true;
        }
        // =============================================
        // RELASI BOBOT
        // =============================================
        if ($DB->record_exists(
            'local_obe_bobot_relasi',
            [
                'id_tahun_akademik' => $id
            ]
        )) {
            return true;
        }
        // =============================================
        // RELASI KOMPETENSI
        // =============================================
        if ($DB->record_exists(
            'local_obe_kompetensi',
            [
                'id_tahun_akademik' => $id
            ]
        )) {
            return true;
        }
        // =============================================
        // RELASI PRODI FRAMEWORK
        // =============================================
        if ($DB->record_exists(
            'local_obe_prodi_framework',
            [
                'id_tahun_akademik' => $id
            ]
        )) {
            return true;
        }

        return false;
    }


    public static function hapus($id) {

        global $DB;

        // =============================================
        // VALIDASI DATA ADA
        // =============================================

        $exists = $DB->record_exists(
            'local_obe_tahun_akademik',
            [
                'id' => $id
            ]
        );

        if (!$exists) {

            throw new \moodle_exception(
                'Data tahun akademik tidak ditemukan.'
            );
        }

        // =============================================
        // VALIDASI RELASI
        // =============================================

        if (self::memiliki_relasi($id)) {

            throw new \moodle_exception(
                'Tahun akademik masih memiliki relasi data.'
            );
        }

        // =============================================
        // HAPUS DATA
        // =============================================

        return $DB->delete_records(
            'local_obe_tahun_akademik',
            [
                'id' => $id
            ]
        );
    }

    public static function aktifkan($id) {
        global $DB;

        $exists = $DB->record_exists('local_obe_tahun_akademik', ['id' => $id]);
        if (!$exists) {
            throw new \moodle_exception('Data tahun akademik tidak ditemukan.');
        }

        $transaction = $DB->start_delegated_transaction();
        $DB->set_field('local_obe_tahun_akademik', 'status_aktif', 0);
        $DB->set_field('local_obe_tahun_akademik', 'status_aktif', 1, ['id' => $id]);
        $transaction->allow_commit();

        \local_obe\access_guard::audit('activate', 'tahun_akademik', $id);
        return true;
    }

}