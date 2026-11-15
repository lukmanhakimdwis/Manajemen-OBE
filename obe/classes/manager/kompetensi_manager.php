<?php
namespace local_obe\manager;
use core_competency\api;
defined('MOODLE_INTERNAL') || die();

class kompetensi_manager {

    /**
     * Mengambil daftar seluruh program studi
     */
    public static function ambil_daftar_prodi() {
        global $DB;
        return $DB->get_records('local_obe_prodi', null, 'nama_prodi ASC');
    }

    public static function ambil_kompetensi_via_prodi($prodi_id, $id_tahun_akademik = 0) {
        global $DB, $SESSION;
        
        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        $mapping = $DB->get_record('local_obe_prodi_framework', [
            'id_prodi'          => $prodi_id, 
            'id_tahun_akademik' => $id_tahun_akademik
        ], 'frameworkid');

        if (!$mapping) return [];

        return $DB->get_records('local_obe_kompetensi', [
            'frameworkid'       => $mapping->frameworkid,
            'id_tahun_akademik' => $id_tahun_akademik
        ], 'id ASC');
    }

 /**
     * Sinkronisasi data kompetensi dari Framework Moodle ke tabel lokal local_obe_kompetensi
     * Disertai dengan auto-migration data lama (competencyid = 0) agar tidak terjadi duplikasi.
     * * @param int $prodi_id
     * @param int $id_tahun_akademik
     * @return bool
     */
    /**
     * Sinkronisasi data kompetensi dari Framework Moodle ke tabel lokal local_obe_kompetensi
     * Disertai dengan auto-migration data lama (competencyid = 0) agar tidak terjadi duplikasi.
     * 
     * @param int $prodi_id
     * @param int $id_tahun_akademik
     * @return bool
     */
    public static function sync_competencies($prodi_id, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // Ambil relasi framework kompetensi moodle untuk prodi dan tahun akademik terkait
        $mapping = $DB->get_record('local_obe_prodi_framework', [
            'id_prodi'          => $prodi_id,
            'id_tahun_akademik' => $id_tahun_akademik
        ], 'frameworkid');

        if (!$mapping) {
            return false;
        }

        $transaction = $DB->start_delegated_transaction();
        $sourceids = $DB->get_fieldset_select('competency', 'id', 'competencyframeworkid = ?', [$mapping->frameworkid]);
        $locals = $DB->get_records('local_obe_kompetensi', ['id_prodi' => $prodi_id, 'id_tahun_akademik' => $id_tahun_akademik]);
        foreach ($locals as $local) {
            if (!in_array($local->competencyid, $sourceids)) {
                if ($DB->record_exists('local_obe_aktivitas_map', ['sub_cpmk_id' => $local->id]) ||
                    $DB->record_exists_select('local_obe_bobot_relasi', 'id_kompetensi_asal = ? OR id_kompetensi_tujuan = ?', [$local->id, $local->id])) {
                    throw new \moodle_exception('Kompetensi yang dihapus masih dirujuk bobot atau aktivitas. Arsipkan kurikulum lama sebelum sinkronisasi.');
                }
                $DB->delete_records('local_obe_kompetensi', ['id' => $local->id]);
            }
        }
        // Ambil seluruh rekaman kompetensi moodle di bawah framework tersebut
        $moodle_competencies = $DB->get_records('competency', ['competencyframeworkid' => $mapping->frameworkid]);
        
        if (empty($moodle_competencies)) { $transaction->allow_commit(); return true; }

        // =====================================================
        // PROSES STRUKTUR DATA MAP MOODLE
        // =====================================================
        $map = [];
        foreach ($moodle_competencies as $mc) {
            $map[$mc->id] = $mc;
        }

        $id_map = [];
        
        // =====================================================
        // LOOP 1: IDENTIFIKASI HIERARKI, INSERT ATAU UPDATE
        // =====================================================
        foreach ($moodle_competencies as $mc) {
            $parent = $map[$mc->parentid] ?? null;
            
            // --- Logika Penentuan Tipe 4 Level Kurikulum ---
            if ($mc->parentid == 0) {
                $tipe = 'CPL'; // Level 1
            } else {
                $grandparentid = $parent ? $parent->parentid : 0;
                $grandparent   = $map[$grandparentid] ?? null;

                if ($parent && $parent->parentid == 0) {
                    $tipe = 'INDIKATOR';
                } else if ($grandparent && $grandparent->parentid == 0) {
                    $tipe = 'CPMK'; // Level 3
                } else {
                    $tipe = 'SUB-CPMK'; // Level 4
                }
            }

            // Pencarian data existing berbasis ID Moodle (competencyid) & id_prodi agar tidak bocor
            $existing = $DB->get_record('local_obe_kompetensi', [
                'competencyid'      => $mc->id,
                'id_prodi'          => $prodi_id,
                'frameworkid'       => $mapping->frameworkid,
                'id_tahun_akademik' => $id_tahun_akademik,
            ]);

            if (!$existing) {
                // Skema objek data baru jika tidak ditemukan di tabel lokal (INSERT)
                $data                    = new \stdClass();
                $data->id_prodi          = $prodi_id;
                $data->frameworkid       = $mapping->frameworkid;
                $data->competencyid      = $mc->id;
                $data->nama_kompetensi   = $mc->shortname;
                $data->deskripsi         = $mc->description ?? '';
                $data->tipe              = $tipe;
                $data->parent_id         = 0; // Di-link pada loop kedua
                $data->id_tahun_akademik = $id_tahun_akademik;
                
                $local_id = $DB->insert_record('local_obe_kompetensi', $data);
            } else {
                // Skema pembaruan jika data sudah ada (UPDATE nama & tipe kurikulum)
                $existing->nama_kompetensi = $mc->shortname;
                $existing->deskripsi       = $mc->description ?? '';
                $existing->tipe            = $tipe;
                $existing->parent_id       = 0; // Reset parent untuk di-link ulang pada loop 2
                
                $DB->update_record('local_obe_kompetensi', $existing);
                $local_id = $existing->id;
            }

            // Simpan pemetaan korelasi [ID_Moodle] => ID_Lokal_Tabel
            $id_map[$mc->id] = $local_id;
        }

        // =====================================================
        // LOOP 2: MAPPING HIERARKI RELASI PARENT_ID LOKAL
        // =====================================================
        foreach ($moodle_competencies as $mc) {
            $local_id        = $id_map[$mc->id];
            $local_parent_id = ($mc->parentid != 0 && isset($id_map[$mc->parentid]))
                ? $id_map[$mc->parentid]
                : 0;

            // Perketat update field: Pastikan local_id dan local_parent_id dikunci pada id_prodi yang benar
            $DB->execute("
                UPDATE {local_obe_kompetensi}
                   SET parent_id = :parent_id
                 WHERE id = :id 
                   AND id_prodi = :prodi_id 
                   AND id_tahun_akademik = :tahun_akademik
            ", [
                'parent_id'      => $local_parent_id,
                'id'             => $local_id,
                'prodi_id'       => $prodi_id,
                'tahun_akademik' => $id_tahun_akademik
            ]);
        }

        $transaction->allow_commit();
        return true;
    }

    

    public static function ambil_semua_sub_cpmk() {
        global $DB;
        return $DB->get_records('local_obe_kompetensi', ['tipe' => 'SUB-CPMK'], 'id ASC');
    }

    /**
     * Mengambil daftar Sub-CPMK berdasarkan tahun akademik aktif
     */
   public static function ambil_semua_sub_cpmk_berdasarkan_tahun($id_tahun_akademik = 0, $courseid = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        $params = ['tahun_akademik' => $id_tahun_akademik];
        $coursefilter = '';

        if ($courseid > 0) {
            $coursefilter = ' AND rk.id_kursus_moodle = :courseid ';
            $params['courseid'] = $courseid;
        }

        // Sub-CPMK dibatasi pada tahun akademik dan, jika tersedia, course/prodi yang sedang dipetakan.
        $sql = "SELECT DISTINCT komp.id, komp.nama_kompetensi
                  FROM {local_obe_kompetensi} komp
                  JOIN {local_obe_kompetensi} cpmk ON komp.parent_id = cpmk.id
                  JOIN {local_obe_mata_kuliah} mk ON cpmk.id_prodi = mk.id_prodi
                  JOIN {local_obe_relasi_kelas} rk ON mk.id = rk.id_mata_kuliah
                 WHERE komp.tipe = 'SUB-CPMK'
                   AND komp.id_prodi = mk.id_prodi
                   AND komp.id_tahun_akademik = rk.id_tahun_akademik
                   AND rk.id_tahun_akademik = :tahun_akademik
                       {$coursefilter}
              ORDER BY komp.nama_kompetensi ASC";

        return array_values($DB->get_records_sql($sql, $params));
    }

    public static function ambil_kompetensi_terurut($prodi_id, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        $mapping = $DB->get_record('local_obe_prodi_framework', [
            'id_prodi'          => $prodi_id,
            'id_tahun_akademik' => $id_tahun_akademik
        ], 'frameworkid');

        if (!$mapping) return [];

        $semua_data = $DB->get_records('local_obe_kompetensi', [
            'id_prodi'          => $prodi_id,
            'frameworkid'       => $mapping->frameworkid,
            'id_tahun_akademik' => $id_tahun_akademik,
        ]);

        $result = [];
        self::sort_hierarchy($semua_data, 0, $result);
        return $result;
    }

    private static function sort_hierarchy($data, $parent_id = 0, &$result = []) {
        foreach ($data as $item) {
            if ((int)$item->parent_id === (int)$parent_id) {
                $result[] = $item;
                self::sort_hierarchy($data, $item->id, $result);
            }
        }
        return $result;
    }

    public static function ambil_by_id($id) {
    global $DB;

    return $DB->get_record(
        'local_obe_kompetensi',
        ['id' => $id]
    );
    }

    public static function ambil_hierarki_subcpmk($subcpmkid){
    global $DB;

    $subcpmk = $DB->get_record(
        'local_obe_kompetensi',
        ['id' => $subcpmkid]
    );

    if (!$subcpmk) {
        return null;
    }

    $cpmk = $DB->get_record(
        'local_obe_kompetensi',
        ['id' => $subcpmk->parent_id]
    );

    $indikator = $cpmk
        ? $DB->get_record(
            'local_obe_kompetensi',
            ['id' => $cpmk->parent_id]
        )
        : null;

    $cpl = $indikator
        ? $DB->get_record(
            'local_obe_kompetensi',
            ['id' => $indikator->parent_id]
        )
        : null;

    return (object)[
        'subcpmk'   => $subcpmk,
        'cpmk'      => $cpmk,
        'indikator' => $indikator,
        'cpl'       => $cpl
    ];
}
}