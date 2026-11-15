<?php
namespace local_obe\manager;

defined('MOODLE_INTERNAL') || die();

class skala_nilai_manager {

    /**
     * Ambil semua data skala nilai berdasarkan prodi dan tahun akademik
     */
    public static function is_kompeten($nilai, $tipe, $prodi, $tahun) {
        global $DB;
        $grade = self::cek_skala($nilai, $tipe, $prodi, $tahun);
        return $DB->record_exists_select('local_obe_skala_nilai',
            'id_prodi = ? AND id_tahun_akademik = ? AND UPPER(tipe_kompetensi) = ? AND huruf_nilai = ? AND status_kompeten = 1',
            [$prodi, $tahun, strtoupper(trim($tipe)), $grade]);
    }

    public static function ambil_semua($prodi, $tahun) {
        global $DB;

        return $DB->get_records(
            'local_obe_skala_nilai',
            [
                'id_prodi' => $prodi,
                'id_tahun_akademik' => $tahun
            ],
            'tipe_kompetensi ASC, nilai_max DESC' 
        );
    }

    /**
     * Ambil satu data skala nilai berdasarkan ID
     */
    public static function ambil($id) {
        global $DB;

        return $DB->get_record(
            'local_obe_skala_nilai',
            ['id' => $id]
        );
    }

    /**
     * Tambah data skala nilai baru
     */
    public static function tambah($data) {
        global $DB;

        $data->timecreated = time();
        $data->timemodified = time();

        $id = $DB->insert_record(
            'local_obe_skala_nilai',
            $data
        );
        \local_obe\access_guard::audit('create', 'skala_nilai', $id, (array)$data);
        return $id;
    }

    /**
     * Perbarui data skala nilai
     */
    public static function update($data) {
        global $DB;

        $data->timemodified = time();

        $result = $DB->update_record(
            'local_obe_skala_nilai',
            $data
        );
        \local_obe\access_guard::audit('update', 'skala_nilai', $data->id ?? 0, (array)$data);
        return $result;
    }

    /**
     * Hapus data skala nilai berdasarkan ID
     */
    public static function hapus($id) {
        global $DB;

        $old = $DB->get_record('local_obe_skala_nilai', ['id' => $id], '*', IGNORE_MISSING);
        $result = $DB->delete_records(
            'local_obe_skala_nilai',
            ['id' => $id]
        );
        \local_obe\access_guard::audit('delete', 'skala_nilai', $id, $old ? (array)$old : []);
        return $result;
    }

    /**
     * Konversi nilai ke huruf mutu
     */
    public static function cek_skala($nilai, $tipe, $id_prodi, $id_tahun_akademik) {

        global $DB;

       $sql = "
            SELECT huruf_nilai
            FROM {local_obe_skala_nilai}
            WHERE UPPER(tipe_kompetensi)=:tipe
            AND id_prodi=:prodi
            AND id_tahun_akademik=:tahun
            AND nilai_min <= :nilai1
            AND nilai_max >= :nilai2
            ORDER BY nilai_min DESC
            LIMIT 1
        ";
        $params = [
            'nilai1' => round($nilai, 2),
            'nilai2' => round($nilai, 2),
            'tipe'   => strtoupper(trim($tipe)),
            'prodi'  => $id_prodi,
            'tahun'  => $id_tahun_akademik
        ];

        $huruf = $DB->get_field_sql($sql, $params);

        return $huruf ?: '-';
            }

    /**
     * Memeriksa apakah rentang nilai baru tumpang tindih dengan data skala nilai yang sudah ada.
     */
    public static function apakah_rentang_tabrakan($prodi, $tahun_akademik, $tipe, $nilai_min, $nilai_max, $id_edit = 0) {
        global $DB;

        $sql = "SELECT id 
                FROM {local_obe_skala_nilai} 
                WHERE id_prodi = :prodi 
                AND id_tahun_akademik = :tahun 
                AND UPPER(tipe_kompetensi) = :tipe
                AND nilai_min <= :nilai_max 
                AND nilai_max >= :nilai_min";

        $params = [
            'prodi'     => $prodi,
            'tahun'     => $tahun_akademik,
            'tipe'      => strtoupper(trim($tipe)),
            'nilai_min' => $nilai_min,
            'nilai_max' => $nilai_max
        ];

        if ($id_edit > 0) {
            $sql .= " AND id <> :id_edit";
            $params['id_edit'] = $id_edit;
        }

        return $DB->record_exists_sql($sql, $params);
    }

    // =========================================================================
    // PERBAIKAN / TAMBAHAN BARU
    // =========================================================================
    
    public static function ambil_ambang_batas_kompeten($prodi, $tahun, $tipe) {
        global $DB;

        $sql = "SELECT MIN(nilai_min) 
                  FROM {local_obe_skala_nilai}
                 WHERE id_prodi = :prodi
                   AND id_tahun_akademik = :tahun
                   AND UPPER(tipe_kompetensi) = :tipe
                   AND status_kompeten = 1";

        $ambang = $DB->get_field_sql($sql, [
            'prodi' => $prodi,
            'tahun' => $tahun,  
            'tipe'  => strtoupper(trim($tipe))
        ]);

        if ($ambang === null || $ambang === false) {
            debugging(
                'Ambang kompeten OBE belum dikonfigurasi untuk prodi ' . $prodi .
                ', tahun ' . $tahun . ', tipe ' . $tipe,
                DEBUG_DEVELOPER
            );
            return null;
        }

        return (float)$ambang;
    }
    // =====================================================
    // ATUR AMBANG BATAS KOMPETEN
    // =====================================================

    
    public static function set_ambang_kompeten(  $prodi, $tahun,$tipe  , $nilai_ambang,   $status) {
    global $DB;

    $records = $DB->get_records_select(
        'local_obe_skala_nilai',
        'id_prodi = :prodi
         AND id_tahun_akademik = :tahun
         AND UPPER(tipe_kompetensi) = :tipe',
        [
            'prodi' => $prodi,
            'tahun' => $tahun,
            'tipe'  => strtoupper($tipe)
        ],
        'nilai_min DESC'
    );

    if (empty($records)) {
        return;
    }

    foreach ($records as $r) {

        if ($status == 1) {

            // Grade ini menjadi Kompeten.
            // Semua grade dengan nilai lebih tinggi juga Kompeten.
            $r->status_kompeten = ($r->nilai_max >= $nilai_ambang) ? 1 : 0;

        } else {

            // Grade ini menjadi Belum Kompeten.
            // Semua grade ini dan di bawahnya menjadi Belum Kompeten.
            // Grade yang lebih tinggi tetap Kompeten.
            $r->status_kompeten = ($r->nilai_min > $nilai_ambang) ? 1 : 0;

        }

        $r->timemodified = time();

        $DB->update_record('local_obe_skala_nilai', $r);
    }
    }
}