<?php
namespace local_obe\manager;
defined('MOODLE_INTERNAL') || die();

class angkatan_manager {
    
//mengambil semua angkatan
    public static function ambil_semua($id_prodi = 0) {
    global $DB;

    $sql = "
        SELECT
            a.*,
            p.nama_prodi
        FROM {local_obe_angkatan} a
        JOIN {local_obe_prodi} p
            ON a.id_prodi = p.id
    ";

    $params = [];
    //filterby prodi
    if ($id_prodi > 0) {
        $sql .= " WHERE a.id_prodi = :id_prodi";
        $params['id_prodi'] = $id_prodi;
    }
    //mengurutkan data
    $sql .= "
        ORDER BY
            a.tahun_masuk DESC,
            p.nama_prodi ASC
    ";

    return $DB->get_records_sql($sql, $params);
}
//megnambil angkatan berdasarkan id
    public static function ambil_by_id($id) {
        global $DB;
        return $DB->get_record('local_obe_angkatan', ['id' => $id]);
    }

    public static function simpan($data) {
    global $DB;

    // Validasi agar satu prodi tidak memiliki tahun masuk yang sama
    if ($DB->record_exists('local_obe_angkatan', [
        'id_prodi'     => $data->id_prodi,
        'tahun_masuk'  => $data->tahun_masuk
    ])) {

        throw new \moodle_exception(
            'Angkatan dengan tahun masuk tersebut sudah tersedia pada program studi ini.'
        );
    }

    $id = $DB->insert_record('local_obe_angkatan', $data);

    \local_obe\access_guard::audit(
        'create',
        'angkatan',
        $id,
        (array)$data
    );

    return $id;
}

   public static function update($data) {
    global $DB;

    $sql = "
        SELECT id
        FROM {local_obe_angkatan}
        WHERE id_prodi = :prodi
          AND tahun_masuk = :tahun
          AND id <> :id
    ";

    $params = [
        'prodi' => $data->id_prodi,
        'tahun' => $data->tahun_masuk,
        'id'    => $data->id
    ];

    if ($DB->record_exists_sql($sql, $params)) {

        throw new \moodle_exception(
            'Angkatan dengan tahun masuk tersebut sudah tersedia pada program studi ini.'
        );
    }

    $result = $DB->update_record('local_obe_angkatan', $data);

    \local_obe\access_guard::audit(
        'update',
        'angkatan',
        $data->id,
        (array)$data
    );

    return $result;
}

    public static function hapus($id) {
        global $DB;
        if (self::memiliki_relasi($id)) { throw new \moodle_exception('Data masih memiliki relasi dan tidak dapat dihapus.'); }
        $old = $DB->get_record('local_obe_angkatan', ['id' => $id], '*', IGNORE_MISSING);
        $result = $DB->delete_records('local_obe_angkatan', ['id' => $id]);
        \local_obe\access_guard::audit('delete', 'angkatan', $id, $old ? (array)$old : []);
        return $result;
    }

     public static function memiliki_relasi($id) {
        global $DB;

        // Relasi ke tabel relasi kelas
        if ($DB->record_exists(
            'local_obe_relasi_kelas',
            ['id_angkatan' => $id]
        )) {
            return true;
        }

        return false;
    }
}