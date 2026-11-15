<?php
namespace local_obe\manager;
defined('MOODLE_INTERNAL') || die();

class prodiframework_manager {
    // Mengambil semua relasi prodi-framework beserta tahun akademik
   /**
     * Mengambil semua data relasi prodi dengan framework kompetensi Moodle.
     * Mampu memfilter data berdasarkan prodi tertentu untuk kebutuhan isolasi data Kaprodi.
     * * @param int $id_prodi ID Program Studi (0 jika ingin mengambil semua prodi / Admin mode)
     * @return array Rekaman data yang berhasil ditarik
     */
    public static function ambil_semua($id_prodi = 0) {
        global $DB;

        $params = [];
        $where = "";

        // Jika parameter id_prodi dilempar (Kaprodi mode atau Admin sedang memfilter)
        if ($id_prodi > 0) {
            $where = " WHERE pf.id_prodi = :id_prodi ";
            $params['id_prodi'] = (int)$id_prodi;
        }

        $sql = "SELECT pf.*, p.nama_prodi, cf.shortname as framework_name, ta.nama_tahun 
                FROM {local_obe_prodi_framework} pf
                JOIN {local_obe_prodi} p ON pf.id_prodi = p.id
                JOIN {competency_framework} cf ON pf.frameworkid = cf.id
                LEFT JOIN {local_obe_tahun_akademik} ta ON pf.id_tahun_akademik = ta.id
                {$where}
                ORDER BY p.nama_prodi ASC";

        return $DB->get_records_sql($sql, $params);
    }
    public static function ambil_by_id($id) {
        global $DB;

        return $DB->get_record(
            'local_obe_prodi_framework',
            ['id' => $id]
        );
}

    // Mengambil semua framework yang tersedia di Moodle
    public static function ambil_semua_framework() {
        global $DB;
        return $DB->get_records('competency_framework', null, 'shortname ASC', 'id, shortname, idnumber');
    }

    public static function update($data) {
        global $DB;

        $result = $DB->update_record(
            'local_obe_prodi_framework',
            $data
        );
        \local_obe\access_guard::audit('update', 'prodi_framework', $data->id ?? 0, (array)$data);
        return $result;
    }

    public static function simpan($data) {
        global $DB;
        $id = $DB->insert_record('local_obe_prodi_framework', $data);
        \local_obe\access_guard::audit('create', 'prodi_framework', $id, (array)$data);
        return $id;
    }

    public static function hapus($id) {
            global $DB;
            if (self::memiliki_relasi($id)) { throw new \moodle_exception('Data masih memiliki relasi dan tidak dapat dihapus.'); }
        $old = $DB->get_record('local_obe_prodi_framework', ['id' => $id], '*', IGNORE_MISSING);
            $result = $DB->delete_records('local_obe_prodi_framework', ['id' => $id]);
            \local_obe\access_guard::audit('delete', 'prodi_framework', $id, $old ? (array)$old : []);
            return $result;
        }

    public static function memiliki_relasi($id) {
        global $DB;

        // Ambil data prodi framework
        $pf = $DB->get_record(
            'local_obe_prodi_framework',
            ['id' => $id]
        );

        if (!$pf) {
            return false;
        }

        // Relasi ke kompetensi
        if ($DB->record_exists(
            'local_obe_kompetensi',
            [
                'id_prodi' => $pf->id_prodi,
                'id_tahun_akademik' => $pf->id_tahun_akademik
            ]
        )) {
            return true;
        }

        return false;
    }
}