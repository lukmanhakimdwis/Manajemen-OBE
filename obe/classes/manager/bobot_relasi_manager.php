<?php
namespace local_obe\manager;

defined('MOODLE_INTERNAL') || die();

class bobot_relasi_manager {

    /**
     * Menyimpan atau memperbarui nilai bobot relasi kompetensi anak terhadap induknya.
     * Sudah dilengkapi pengaman batasan nilai akumulatif maksimal 100%.
     */
    public static function save_bobot($id_kompetensi, $bobot, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        // Jika tidak diberikan, gunakan tahun akademik aktif dari sesi
        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }
        //validasi bobot berada pada rentang 0-100
        $bobot = (float)$bobot;
        if ($bobot < 0 || $bobot > 100) {
            throw new \moodle_exception('Bobot relasi harus berada pada rentang 0 sampai 100.');
        }
    //
        $kompetensi_asal = $DB->get_record('local_obe_kompetensi', ['id' => $id_kompetensi]);
        if (!$kompetensi_asal) {
            return false;
        }

        // Ambil data parent untuk menentukan tipe tujuan relasi hierarki
        $parent = $DB->get_record('local_obe_kompetensi', ['id' => $kompetensi_asal->parent_id]);
        $tipe_tujuan = $parent ? $parent->tipe : 'CPL';

        // VALIDASI: Pastikan akumulasi jatah nilai saudara (siblings) + nilai input baru tidak over 100%
        $total_sekarang = self::get_total_bobot_siblings($id_kompetensi, $bobot, $id_tahun_akademik);
        if ($total_sekarang > 100.01) { // Toleransi presisi nilai float 0.01
            return false;
        }

        $exist = $DB->get_record('local_obe_bobot_relasi', [
            'id_kompetensi_asal' => $id_kompetensi,
            'id_tahun_akademik'  => $id_tahun_akademik
        ]);
        
        $data = $exist ? $exist : new \stdClass();
        $data->id_kompetensi_asal   = $id_kompetensi;
        $data->tipe_asal             = $kompetensi_asal->tipe;
        $data->id_kompetensi_tujuan = $kompetensi_asal->parent_id;
        $data->tipe_tujuan           = $tipe_tujuan;
        $data->nilai_bobot           = (float)$bobot;
        $data->id_tahun_akademik     = $id_tahun_akademik;
        $data->waktu_ubah            = time();

        //memeriksa apakah sudah ada record sebelumnya, jika ada maka update, jika tidak maka insert
        if ($exist) {
            $result = $DB->update_record('local_obe_bobot_relasi', $data);
            \local_obe\access_guard::audit('update', 'bobot_relasi', $data->id, (array)$data);
            return $result;
        } else {
            $id = $DB->insert_record('local_obe_bobot_relasi', $data);
            \local_obe\access_guard::audit('create', 'bobot_relasi', $id, (array)$data);
            return $id;
        }
    }

    /**
     * Mengambil semua bobot relasi kompetensi untuk program studi tertentu dan tahun akademik tertentu.
     */
    public static function get_semua_bobot_by_prodi($prodi_id, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }
        //data diambil dari tabel bobot relasi dan di join dengan tabel kompetensi untuk mendapatkan id_prodi
        $sql = "SELECT r.id_kompetensi_asal, r.nilai_bobot 
                FROM {local_obe_bobot_relasi} r
                JOIN {local_obe_kompetensi} c ON c.id = r.id_kompetensi_asal
                WHERE c.id_prodi = :prodi_id AND r.id_tahun_akademik = :tahun_akademik";
                
        return $DB->get_records_sql_menu($sql, [
            'prodi_id'       => (int)$prodi_id, 
            'tahun_akademik' => (int)$id_tahun_akademik
        ]);
    }

    /**
     * Menghitung total bobot komptensi anak terhadap induknya
     */
    public static function get_total_bobot_terisi_by_parent($prodi_id, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        $sql = "SELECT c.parent_id, SUM(r.nilai_bobot) as total
                FROM {local_obe_bobot_relasi} r
                JOIN {local_obe_kompetensi} c ON c.id = r.id_kompetensi_asal
                WHERE c.id_prodi = :prodi_id AND r.id_tahun_akademik = :tahun_akademik
                GROUP BY c.parent_id";
                
        return $DB->get_records_sql_menu($sql, [
            'prodi_id'       => (int)$prodi_id, 
            'tahun_akademik' => (int)$id_tahun_akademik
        ]);
    }

    /**
     * Menghitung totoal bobot kompetensi yang berada pada level sama
     */
    public static function get_total_bobot_siblings($kompetensi_id, $bobot_baru, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        $kompetensi = $DB->get_record('local_obe_kompetensi', ['id' => $kompetensi_id], 'parent_id');
        if (!$kompetensi) {
            return 0;
        }

        $sql = "SELECT SUM(r.nilai_bobot) 
                FROM {local_obe_bobot_relasi} r
                JOIN {local_obe_kompetensi} k ON r.id_kompetensi_asal = k.id
                WHERE k.parent_id = :parent_id 
                AND r.id_kompetensi_asal != :id_saat_ini
                AND r.id_tahun_akademik = :tahun_akademik";
                
        $total_lain = $DB->get_field_sql($sql, [
            'parent_id'      => $kompetensi->parent_id, 
            'id_saat_ini'    => $kompetensi_id,
            'tahun_akademik' => $id_tahun_akademik
        ]);

        return (float)$total_lain + (float)$bobot_baru;
    }

    /**
     * Menghapus record bobot relasi berdasarkan ID
     */
    public static function delete_relasi($id) {
        global $DB;
        $old = $DB->get_record('local_obe_bobot_relasi', ['id' => $id], '*', IGNORE_MISSING);
        $result = $DB->delete_records('local_obe_bobot_relasi', ['id' => $id]);
        \local_obe\access_guard::audit('delete', 'bobot_relasi', $id, $old ? (array)$old : []);
        return $result;
    }
    
}