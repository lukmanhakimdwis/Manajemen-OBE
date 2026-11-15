<?php
namespace local_obe\manager;
use core_competency\api;
defined('MOODLE_INTERNAL') || die();

class aktivitasnilai_manager {

    /**
     * Mengambil daftar aktivitas dengan status pemetaannya (Untuk List View)
     */
    public static function ambil_semua_pemetaan($courseid) {
        global $DB;

        // Gunakan Course Modinfo API Moodle agar nama modul, visibilitas, dan status deletion
        // mengikuti cache dan aturan bawaan Moodle, bukan membaca tabel core secara manual.
        $modinfo = get_fast_modinfo($courseid);
        $results = [];

        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible || !empty($cm->deletioninprogress)) {
                continue;
            }

            $map = $DB->get_record('local_obe_aktivitas_map', ['cmid' => $cm->id], '*', IGNORE_MISSING);
            $row = (object)[
                'cmid'             => $cm->id,
                'modulename'       => $cm->modname,
                'instance'         => $cm->instance,
                'name'             => $cm->name,
                'map_id'           => $map->id ?? null,
                'sub_cpmk_id'      => $map->sub_cpmk_id ?? null,
                'bobot_aktivitas'  => $map->bobot_aktivitas ?? null,
                'nama_sub_cpmk'    => null,
            ];

            if (!empty($row->sub_cpmk_id)) {
                $row->nama_sub_cpmk = $DB->get_field('local_obe_kompetensi', 'nama_kompetensi', ['id' => $row->sub_cpmk_id]);
            }

            $results[] = $row;
        }

        return $results;
    }

    /**
     * Mengambil satu baris pemetaan spesifik (Untuk Edit Form)
     */
    public static function ambil_satu_pemetaan($id) {
        global $DB;
        return $DB->get_record('local_obe_aktivitas_map', ['id' => $id]);
    }

    /**
     * Validasi data pemetaan sebelum disimpan ke database
     */
    private static function validate_mapping_data($cmid, $sub_cpmk_id, $bobot, $courseid = 0, $exclude_map_id = 0) {
        global $DB;
        //validasi aktivitas dan sub-cpmk benar dipilih
        if ($cmid <= 0 || $sub_cpmk_id <= 0) {
            throw new \moodle_exception('invalidparameter', 'error');
        }
        //validasi bobot aktivitas berada pada rentang 0-100
        $bobot = (float)$bobot;
        if ($bobot < 0 || $bobot > 100) {
            throw new \moodle_exception('Bobot aktivitas harus berada pada rentang 0 sampai 100.');
        }
        //validasi subcpmk
        $sub = $DB->get_record('local_obe_kompetensi', ['id' => $sub_cpmk_id], '*', MUST_EXIST);
        if (strtoupper((string)$sub->tipe) !== 'SUB-CPMK') {
            throw new \moodle_exception('Kompetensi yang dipilih bukan Sub-CPMK.');
        }

        $sql = 'cmid = :cmid';
        $params = ['cmid' => $cmid];
        if ($exclude_map_id > 0) {
            $sql .= ' AND id <> :excludeid';
            $params['excludeid'] = $exclude_map_id;
        }
        //validasi apakah aktivitas sudah memiliki pemetaan
        if ($DB->record_exists_select('local_obe_aktivitas_map', $sql, $params)) {
            throw new \moodle_exception('Aktivitas Moodle ini sudah memiliki pemetaan. Hapus atau ubah pemetaan lama terlebih dahulu.');
        }
        //validasi cmid benar-benar milik course ini
        if ($courseid > 0) {
            get_coursemodule_from_id('', $cmid, $courseid, false, MUST_EXIST);

            $relasi = $DB->get_record_sql(
                "SELECT rk.*, mk.id_prodi
                   FROM {local_obe_relasi_kelas} rk
                   JOIN {local_obe_mata_kuliah} mk ON mk.id = rk.id_mata_kuliah
                  WHERE rk.id_kursus_moodle = :courseid",
                ['courseid' => $courseid],
                MUST_EXIST
            );
            //validasi sub-cpmk berasal dari prodi dan tahun akademik yang sama dengan kelas Moodle ini
            if ((int)$sub->id_prodi !== (int)$relasi->id_prodi) {
                throw new \moodle_exception('Sub-CPMK tidak berasal dari program studi yang sama dengan kelas Moodle ini.');
            }

            if ((int)$sub->id_tahun_akademik !== (int)$relasi->id_tahun_akademik) {
                throw new \moodle_exception('Sub-CPMK tidak berasal dari tahun akademik yang sama dengan relasi kelas ini.');
            }
            //menjumlahkan bobot aktivitas yang sudah ada untuk sub-cpmk ini
            $usage = self::get_total_bobot_usage($courseid, $exclude_map_id);
            $total = (float)($usage[$sub_cpmk_id] ?? 0) + $bobot;
            if ($total > 100.01) {
                throw new \moodle_exception('Total bobot aktivitas untuk Sub-CPMK ini melebihi 100%.');
            }
        }
    }

    /**
     * CREATE: Menyimpan pemetaan baru.
     */
    public static function insert($cmid, $sub_cpmk_id, $bobot, $courseid = 0) {
        global $DB;
        self::validate_mapping_data($cmid, $sub_cpmk_id, $bobot, $courseid, 0);

        $record = new \stdClass();
        $record->cmid = $cmid;
        $record->sub_cpmk_id = $sub_cpmk_id;
        $record->bobot_aktivitas = (float)$bobot;
        $id = $DB->insert_record('local_obe_aktivitas_map', $record);
        \local_obe\access_guard::audit('create', 'aktivitas_map', $id, (array)$record);
        return $id;
        //menyiimpan log auditaccess_guard::audit()
    }

    /**
     * UPDATE: Mengubah pemetaan yang sudah ada.
     */
    public static function update($id, $sub_cpmk_id, $bobot, $courseid = 0) {
        global $DB;
        $existing = $DB->get_record('local_obe_aktivitas_map', ['id' => $id], '*', MUST_EXIST);
        self::validate_mapping_data($existing->cmid, $sub_cpmk_id, $bobot, $courseid, $id);

        $record = new \stdClass();
        $record->id = $id;
        $record->sub_cpmk_id = $sub_cpmk_id;
        $record->bobot_aktivitas = (float)$bobot;
        $result = $DB->update_record('local_obe_aktivitas_map', $record);
        \local_obe\access_guard::audit('update', 'aktivitas_map', $id, (array)$record);
        return $result;
    }

    /**
     * DELETE: Menghapus pemetaan.
     */
    public static function delete($id) {
        global $DB;
        $old = $DB->get_record('local_obe_aktivitas_map', ['id' => $id], '*', IGNORE_MISSING);
        $result = $DB->delete_records('local_obe_aktivitas_map', ['id' => $id]);
        \local_obe\access_guard::audit('delete', 'aktivitas_map', $id, $old ? (array)$old : []);
        return $result;
    }

    public static function ambil_kompetensi_moodle_course($courseid) {
        // API Moodle untuk mengambil semua kompetensi yang terdaftar di kursus
        return api::list_course_competencies($courseid);
    }

    /**
     * Memeriksa apakah suatu Course ID telah direlasikan ke Mata Kuliah OBE
     */
    public static function is_course_mapped($courseid) {
        global $DB;
        return $DB->record_exists('local_obe_relasi_kelas', ['id_kursus_moodle' => $courseid]);
    }

    //menghitung total bobot aktivitas yang sudah ada untuk setiap sub-cpmk di course ini, kecuali pemetaan tertentu (misal saat update)
    public static function get_total_bobot_usage($courseid, $exclude_map_id = 0) {
        global $DB;

        // Gunakan Course Modinfo API untuk memastikan cmid benar-benar milik course ini.
        $modinfo = get_fast_modinfo($courseid);
        $coursecmids = array_map('intval', array_keys($modinfo->get_cms()));
        if (empty($coursecmids)) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal($coursecmids, SQL_PARAMS_NAMED, 'cmid');
        $params['excludeid'] = $exclude_map_id;

        $sql = "SELECT sub_cpmk_id, SUM(bobot_aktivitas) AS total
                  FROM {local_obe_aktivitas_map}
                 WHERE cmid $insql AND id <> :excludeid
              GROUP BY sub_cpmk_id";

        return $DB->get_records_sql_menu($sql, $params);
    }
    /**
 * Mencari ID Tahun Akademik yang terhubung dengan Course tertentu
 */

    public static function ambil_tahun_id_dari_course($courseid) {
        global $DB;
        $record = $DB->get_record('local_obe_relasi_kelas', ['id_kursus_moodle' => $courseid], 'id_tahun_akademik');
        return $record ? $record->id_tahun_akademik : 0;
    }
}