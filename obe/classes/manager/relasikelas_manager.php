<?php
namespace local_obe\manager;
defined('MOODLE_INTERNAL') || die();

class relasikelas_manager {
    
    public static function ambil_semua($id_mk = 0, $id_angkatan = 0, $id_prodi = 0, $id_tahun_akademik = 0) {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $conditions = [];
        $params = [];

        if ($id_mk > 0) { $conditions[] = "r.id_mata_kuliah = :id_mk"; $params['id_mk'] = $id_mk; }
        if ($id_angkatan > 0) { $conditions[] = "r.id_angkatan = :id_angkatan"; $params['id_angkatan'] = $id_angkatan; }
        if ($id_prodi > 0) { $conditions[] = "mk.id_prodi = :id_prodi"; $params['id_prodi'] = $id_prodi; }
        if ($id_tahun_akademik > 0) { $conditions[] = "r.id_tahun_akademik = :id_tahun_akademik"; $params['id_tahun_akademik'] = $id_tahun_akademik; }

        $where = !empty($conditions) ? " WHERE " . implode(" AND ", $conditions) : "";

        // Data relasi/mata kuliah adalah tabel lokal OBE. Data course Moodle diperkaya melalui get_course().
        $sql = "SELECT r.*, mk.nama_mk, a.nama_angkatan, t.nama_tahun
                  FROM {local_obe_relasi_kelas} r
                  JOIN {local_obe_mata_kuliah} mk ON r.id_mata_kuliah = mk.id
                  JOIN {local_obe_angkatan} a ON r.id_angkatan = a.id
             LEFT JOIN {local_obe_tahun_akademik} t ON r.id_tahun_akademik = t.id
                $where
              ORDER BY a.tahun_masuk DESC, mk.nama_mk ASC";

        $records = $DB->get_records_sql($sql, $params);
        foreach ($records as $record) {
            try {
                $course = get_course($record->id_kursus_moodle);
                $record->moodle_course_name = $course->fullname;
                $record->moodle_shortname = $course->shortname;
            } catch (\moodle_exception $e) {
                $record->moodle_course_name = get_string('missingcourse', 'error') . ' #' . $record->id_kursus_moodle;
                $record->moodle_shortname = '';
            }
        }

        return $records;
    }
    public static function ambil_satu($id) {
        global $DB;
        return $DB->get_record('local_obe_relasi_kelas', ['id' => $id]);
    }
    
    public static function ambil_semua_course_moodle() {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/course/lib.php');

        // 1. Ambil daftar course yang sudah direlasikan (Gunakan array_flip untuk lookup cepat).
        $mappedcourseids = $DB->get_fieldset_select(
            'local_obe_relasi_kelas',
            'id_kursus_moodle',
            'id_kursus_moodle <> 0'
        );
        $mappedlookup = array_flip(array_map('intval', $mappedcourseids));

        // 2. Optimasi: Ambil semua kategori sekaligus untuk menghindari N+1 Query di dalam loop.
        $categories = \core_course_category::get_all();

        // 3. Ambil semua course Moodle melalui API.
        $courses = get_courses(
            'all',
            'c.sortorder ASC',
            'c.id, c.fullname, c.shortname, c.category'
        );

        $results = [];

        foreach ($courses as $course) {
            $courseid = (int)$course->id;

            // Lewati Site Course Moodle (ID = SITEID) atau yang sudah direlasikan.
            if ($courseid === (int)SITEID || isset($mappedlookup[$courseid])) {
                continue;
            }

            // Ambil nama kategori dari cache memory ($categories), bukan query database ulang.
            $categoryname = '';
            if (isset($categories[$course->category])) {
                $categoryname = $categories[$course->category]->get_formatted_name();
            }

            $results[$courseid] = (object)[
                'id'            => $courseid,
                'fullname'      => $course->fullname,
                'shortname'     => $course->shortname,
                'category_name' => $categoryname
            ];
        }

        // 4. Urutkan berdasarkan Nama Kategori, kemudian Nama Course.
        uasort($results, function($a, $b) {
            $catcmp = strcasecmp($a->category_name, $b->category_name);
            if ($catcmp !== 0) {
                return $catcmp;
            }
            return strcasecmp($a->fullname, $b->fullname);
        });

        return $results;
    }

   public static function simpan($data) {
    global $DB, $SESSION;

    if (empty($data->id_tahun_akademik)) {
        $data->id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
    }

    // VALIDASI
    if (empty($data->id_kursus_moodle) || (int)$data->id_kursus_moodle <= 0) {
        throw new \moodle_exception(
            'Kelas Moodle wajib dipilih sebelum relasi disimpan.'
        );
    }

    $id = $DB->insert_record('local_obe_relasi_kelas', $data);
    \local_obe\access_guard::audit('create', 'relasi_kelas', $id, (array)$data);
    return $id;
}
    public static function update($data) {
    global $DB, $SESSION;

    if (empty($data->id_tahun_akademik)) {
        $data->id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
    }

    // VALIDASI
    if (empty($data->id_kursus_moodle) || (int)$data->id_kursus_moodle <= 0) {
        throw new \moodle_exception(
            'Kelas Moodle wajib dipilih sebelum relasi diperbarui.'
        );
    }

    if ($DB->record_exists_select('local_obe_relasi_kelas', 'id_kursus_moodle = ? AND id <> ?', [$data->id_kursus_moodle, $data->id])) { throw new \moodle_exception('Course Moodle sudah dipetakan.'); }
    $result = $DB->update_record('local_obe_relasi_kelas', $data);
    \local_obe\access_guard::audit('update', 'relasi_kelas', $data->id ?? 0, (array)$data);
    return $result;
}

    public static function hapus($id) {
        global $DB;
        $old = $DB->get_record('local_obe_relasi_kelas', ['id' => $id], '*', IGNORE_MISSING);
        $result = $DB->delete_records('local_obe_relasi_kelas', ['id' => $id]);
        \local_obe\access_guard::audit('delete', 'relasi_kelas', $id, $old ? (array)$old : []);
        return $result;
    }
}