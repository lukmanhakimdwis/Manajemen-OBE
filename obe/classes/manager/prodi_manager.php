<?php
namespace local_obe\manager;
defined('MOODLE_INTERNAL') || die();

class prodi_manager {
    
    public static function ambil_semua_prodi() {
        global $DB;
        return $DB->get_records('local_obe_prodi', null, 'nama_prodi ASC');
    }

    public static function ambil_prodi_berdasarkan_id($id) {
        global $DB;
        return $DB->get_record('local_obe_prodi', ['id' => $id]);
    }

    public static function simpan_prodi($data) {
        global $DB;
        
        // 1. VALIDASI ANTI-DUPLIKASI: Bersihkan spasi dan cek keunikan Kode Prodi
        $kode = isset($data->kode_prodi) ? trim($data->kode_prodi) : '';
        
        if (empty($kode)) {
            throw new \moodle_exception(
                '⚠️ Gagal Menyimpan Data! Kode Program Studi tidak boleh kosong. Harap periksa kembali form Anda.',
                ''
            );
        }

        if ($DB->record_exists('local_obe_prodi', ['kode_prodi' => $kode])) {
            throw new \moodle_exception(
                '🚫 Gagal Menyimpan! Kode Program Studi "' . s($kode) . '" sudah terdaftar di sistem. ' .
                'Silakan gunakan kode prodi lain yang unik atau periksa daftar yang sudah ada.',
                ''
            );
        }

        // Pastikan data yang masuk ke DB adalah data yang sudah di-trim spacing-nya
        $data->kode_prodi = $kode;

        // =============================================
        // 2. EKSEKUSI INSERT DATA & AUDIT TRAIL
        // =============================================
        $id = $DB->insert_record('local_obe_prodi', $data);
        \local_obe\access_guard::audit('create', 'prodi', $id, (array)$data);
        
        return $id;
    }

   public static function perbarui_prodi($data) {
        global $DB;
        
        $id = isset($data->id) ? (int)$data->id : 0;
        $kode = isset($data->kode_prodi) ? trim($data->kode_prodi) : '';
        
        if ($id <= 0) {
            throw new \moodle_exception(
                '⚠️ Gagal Memperbarui! ID Program Studi tidak valid atau tidak ditemukan di sistem.',
                ''
            );
        }

        if (empty($kode)) {
            throw new \moodle_exception(
                '⚠️ Gagal Memperbarui! Kode Program Studi tidak boleh kosong. Harap isi form dengan benar.',
                ''
            );
        }

        // 🔄 VALIDASI ANTI-DUPLIKASI: Cek kode_prodi pada baris data LAIN (id <> :id)
        $sql = "SELECT id FROM {local_obe_prodi} WHERE kode_prodi = :kode AND id <> :id";
        if ($DB->record_exists_sql($sql, ['kode' => $kode, 'id' => $id])) {
            throw new \moodle_exception(
                '🚫 Gagal Memperbarui! Perubahan ditolak karena Kode Prodi "' . s($kode) . '" sudah digunakan oleh Program Studi lain.',
                ''
            );
        }

        // Pastikan data kode_prodi yang masuk ke DB sudah bersih dari spasi liar
        $data->kode_prodi = $kode;

        // =============================================
        // EKSEKUSI UPDATE DATA & AUDIT TRAIL
        // =============================================
        $result = $DB->update_record('local_obe_prodi', $data);
        \local_obe\access_guard::audit('update', 'prodi', $id, (array)$data);
        
        return $result;
    }

    public static function hapus_prodi($id) {

        global $DB;

        // =============================================
        // VALIDASI RELASI
        // =============================================

        if (self::memiliki_relasi($id)) {

            throw new \moodle_exception(
                'Data tidak dapat dihapus karena masih memiliki relasi.'
            );
        }

        // =============================================
        // HAPUS DATA
        // =============================================

        $old = $DB->get_record('local_obe_prodi', ['id' => $id], '*', IGNORE_MISSING);
        $result = $DB->delete_records(
            'local_obe_prodi',
            [
                'id' => $id
            ]
        );
        \local_obe\access_guard::audit('delete', 'prodi', $id, $old ? (array)$old : []);
        return $result;
    }

    public static function memiliki_relasi($id) {
        global $DB;

        // Relasi ke mata kuliah
        if ($DB->record_exists('local_obe_mata_kuliah', ['id_prodi' => $id])) {
            return true;
        }

        // Relasi ke angkatan
        if ($DB->record_exists('local_obe_angkatan', ['id_prodi' => $id])) {
            return true;
        }

        // Relasi ke kompetensi
        if ($DB->record_exists('local_obe_kompetensi', ['id_prodi' => $id])) {
            return true;
        }

        // Relasi ke framework
        if ($DB->record_exists('local_obe_prodi_framework', ['id_prodi' => $id])) {
            return true;
        }

        return false;
    }

        public static function ambil_prodi_kaprodi($userid) {
        global $DB;

        return $DB->get_record(
            'local_obe_prodi',
            ['userid_kaprodi' => $userid]
        );
    }

    public static function is_kaprodi($userid) {
        global $DB;

        return $DB->record_exists(
            'local_obe_prodi',
            ['userid_kaprodi' => $userid]
        );
    }
    public static function get_prodi_aktif_user() {
    global $USER;

    if (self::is_kaprodi($USER->id)) {
        return self::ambil_prodi_kaprodi($USER->id);
    }

    return null;
}
}