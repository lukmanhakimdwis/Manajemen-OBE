<?php
namespace local_obe\manager;
defined('MOODLE_INTERNAL') || die();

class matakuliah_manager {
    public static function ambil_semua($id_prodi = 0) {
        global $DB;
        $sql = "SELECT mk.*, p.nama_prodi 
                FROM {local_obe_mata_kuliah} mk
                JOIN {local_obe_prodi} p ON mk.id_prodi = p.id";
        $params = [];
        if ($id_prodi > 0) {
            $sql .= " WHERE mk.id_prodi = :id_prodi";
            $params['id_prodi'] = $id_prodi;
        }
        $sql .= " ORDER BY p.nama_prodi ASC, mk.kode_mk ASC";
        return $DB->get_records_sql($sql, $params);
    }

    public static function ambil_by_id($id) {
        global $DB;
        return $DB->get_record('local_obe_mata_kuliah', ['id' => $id]);
    }

   public static function simpan($data) {
        global $DB;
        
        // 1. VALIDASI ANTI-DUPLIKASI: Bersihkan spasi dan cek keunikan Kode MK di Prodi yang sama
        $kode = isset($data->kode_mk) ? trim($data->kode_mk) : '';
        $id_prodi = isset($data->id_prodi) ? (int)$data->id_prodi : 0;
        
        if (empty($kode)) {
            throw new \moodle_exception(
                '⚠️ Gagal Menyimpan Data! Kode Mata Kuliah tidak boleh kosong. Harap periksa kembali form Anda.',
                ''
            );
        }

        // Cek kombinasi kode_mk dan id_prodi agar kode yang sama tidak ganda di prodi yang sama
        $kondisi = ['kode_mk' => $kode, 'id_prodi' => $id_prodi];
        if ($DB->record_exists('local_obe_mata_kuliah', $kondisi)) {
            throw new \moodle_exception(
                '🚫 Gagal Menyimpan! Kode Mata Kuliah "' . s($kode) . '" sudah terdaftar pada Program Studi ini.',
                ''
            );
        }

        // Pastikan data kode_mk yang masuk ke DB sudah bersih dari spasi liar
        $data->kode_mk = $kode;

        // =============================================
        // 2. EKSEKUSI INSERT DATA & AUDIT TRAIL
        // =============================================
        $id = $DB->insert_record('local_obe_mata_kuliah', $data);
        \local_obe\access_guard::audit('create', 'mata_kuliah', $id, (array)$data);
        
        return $id;
    }

   public static function update($data) {
        global $DB;
        
        $id = isset($data->id) ? (int)$data->id : 0;
        $kode = isset($data->kode_mk) ? trim($data->kode_mk) : '';
        $id_prodi = isset($data->id_prodi) ? (int)$data->id_prodi : 0;
        
        if ($id <= 0) {
            throw new \moodle_exception(
                '⚠️ Gagal Memperbarui! ID Mata Kuliah tidak valid atau tidak ditemukan di sistem.',
                ''
            );
        }

        if (empty($kode)) {
            throw new \moodle_exception(
                '⚠️ Gagal Memperbarui! Kode Mata Kuliah tidak boleh kosong.',
                ''
            );
        }

        // 🔄 VALIDASI ANTI-DUPLIKASI: Cek kode_mk di prodi ini pada baris data LAIN (id <> :id)
        $sql = "SELECT id FROM {local_obe_mata_kuliah} 
                WHERE kode_mk = :kode AND id_prodi = :id_prodi AND id <> :id";
                
        if ($DB->record_exists_sql($sql, ['kode' => $kode, 'id_prodi' => $id_prodi, 'id' => $id])) {
            throw new \moodle_exception(
                '🚫 Gagal Memperbarui! Perubahan ditolak karena Kode Mata Kuliah "' . s($kode) . '" sudah digunakan oleh mata kuliah lain di Program Studi ini.',
                ''
            );
        }

        // Pastikan data kode_mk yang masuk ke DB sudah bersih dari spasi liar
        $data->kode_mk = $kode;

        // =============================================
        // EKSEKUSI UPDATE DATA & AUDIT TRAIL
        // =============================================
        $result = $DB->update_record('local_obe_mata_kuliah', $data);
        \local_obe\access_guard::audit('update', 'mata_kuliah', $id, (array)$data);
        
        return $result;
    }

    public static function hapus($id) {
        global $DB;
        if (self::memiliki_relasi($id)) { throw new \moodle_exception('Data masih memiliki relasi dan tidak dapat dihapus.'); }
        $old = $DB->get_record('local_obe_mata_kuliah', ['id' => $id], '*', IGNORE_MISSING);
        $result = $DB->delete_records('local_obe_mata_kuliah', ['id' => $id]);
        \local_obe\access_guard::audit('delete', 'mata_kuliah', $id, $old ? (array)$old : []);
        return $result;
    }

    public static function memiliki_relasi($id) {
        global $DB;

        // Relasi ke tabel relasi kelas
        if ($DB->record_exists(
            'local_obe_relasi_kelas',
            ['id_mata_kuliah' => $id]
        )) {
            return true;
        }

        return false;
    }
}