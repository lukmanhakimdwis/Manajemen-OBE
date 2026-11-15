<?php
namespace local_obe\manager;
defined('MOODLE_INTERNAL') || die();

class capaian_kelas_manager {
    
  /**
     * Mengambil data kelas berdasarkan filter prodi, matkul, angkatan, dan tahun akademik.
     */
    public static function supports($courseid, $id) {
        global $DB;
        $ids = [$id]; $seen = [];
        while ($ids) {
            $node = array_pop($ids);
            if (isset($seen[$node])) { continue; }
            $seen[$node] = true;
            $maps = $DB->get_records('local_obe_aktivitas_map', ['sub_cpmk_id' => $node]);
            foreach ($maps as $map) {
                if (get_coursemodule_from_id('', $map->cmid, $courseid, false, IGNORE_MISSING)) { return true; }
            }
            foreach ($DB->get_records('local_obe_kompetensi', ['parent_id' => $node]) as $child) { $ids[] = $child->id; }
        }
        return false;
    }

    public static function ambil_daftar_kelas($prodi_id, $matkul_id, $angkatan_id, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }
        //mengambiil data dari relasi kelas dan mata kuliah
        $sql = "SELECT rk.id_kursus_moodle, mk.nama_mk, rk.id_angkatan
                FROM {local_obe_relasi_kelas} rk
                JOIN {local_obe_mata_kuliah} mk ON rk.id_mata_kuliah = mk.id
                WHERE rk.id_tahun_akademik = :tahun_akademik";
        
        $params = ['tahun_akademik' => $id_tahun_akademik];
        
        //filter prodi, matkul, dan angkatan jika diberikan
        if ($prodi_id > 0) { 
            $sql .= " AND mk.id_prodi = :prodi"; 
            $params['prodi'] = $prodi_id; 
        }
        if ($matkul_id > 0) { 
            $sql .= " AND mk.id = :mk"; 
            $params['mk'] = $matkul_id; 
        }
        if ($angkatan_id > 0) { 
            $sql .= " AND rk.id_angkatan = :angkatan"; 
            $params['angkatan'] = $angkatan_id; 
        }

        return $DB->get_records_sql($sql, $params);
    }
    /**
     * Fondasi Utama Detail Kompetensi Mahasiswa
     * Menghasilkan:
     * 1. Ringkasan capaian kelas
     * 2. Detail capaian setiap mahasiswa
     */
    public static function ambil_detail_kompetensi_mahasiswa( $courseid, $kompetensi_id, $tipe, $id_tahun_akademik = 0) {
    global $DB, $SESSION;

    //mengubah tipe menjadi huruf besar
    $tipe = strtoupper(trim($tipe));

    if (empty($id_tahun_akademik)) {
        $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
    }

    // ==========================================================
    // mengambil PROGRAM STUDI
    // ==========================================================
    $prodi_id = $DB->get_field_sql("
        SELECT mk.id_prodi
        FROM {local_obe_relasi_kelas} rk
        JOIN {local_obe_mata_kuliah} mk
            ON rk.id_mata_kuliah = mk.id
        WHERE rk.id_kursus_moodle = :courseid
    ", [
        'courseid' => $courseid
    ], IGNORE_MULTIPLE);

    // ==========================================================
    // menyiapkan variabel
    // ==========================================================
    $sub_cpmk         = null;
    $cpmk_record      = null;
    $indikator_record = null;
    $cpl_record       = null;

    //menyimpan bobot relasi antar kompetensi
    $b_sub  = 0;
    $b_cpmk = 0;
    $b_ind  = 0;

    //menyimpan daftar aktivitas dan nilai mahasiswa
    $aktivitas_list = [];
    $grades_lookup  = [];
    $lookup_subcpmk = [];

    // ==========================================================
    // mengambil MAHASISWA
    // ==========================================================
    $mahasiswa = self::ambil_mahasiswa_terdaftar($courseid);

    //mengecek apakah ada mahasiswa
    if (empty($mahasiswa)) {

        return (object)[
            'sub_cpmk'           => null,
            'cpmk_record'        => null,
            'indikator_record'   => null,
            'cpl_record'         => null,

            'bobot_sub_display'  => 0,
            'bobot_cpmk_display' => 0,
            'bobot_ind_display'  => 0,

            'aktivitas_list'     => [],

            'ringkasan' => self::buat_ringkasan_kelas(
                [],
                $tipe,
                $prodi_id,
                $id_tahun_akademik
            ),

            'detail_mahasiswa' => [],

            'prodi_id' => $prodi_id
        ];
    }

    // ==========================================================
    // mengambil hirarki  SUB CPMK
    // ==========================================================
    if ($tipe === 'SUB-CPMK') {

        $sub_cpmk = $DB->get_record(
            'local_obe_kompetensi',
            ['id'=>$kompetensi_id],
            '*',
            MUST_EXIST
        );

        $cpmk_record = $DB->get_record(
            'local_obe_kompetensi',
            ['id'=>$sub_cpmk->parent_id],
            '*',
            IGNORE_MISSING
        );

        if ($cpmk_record) {
            $indikator_record = $DB->get_record(
                'local_obe_kompetensi',
                ['id'=>$cpmk_record->parent_id],
                '*',
                IGNORE_MISSING
            );
        }

        if ($indikator_record) {
            $cpl_record = $DB->get_record(
                'local_obe_kompetensi',
                ['id'=>$indikator_record->parent_id],
                '*',
                IGNORE_MISSING
            );
        }

        //mengambil bobot relasi
        $b_sub = self::ambil_bobot_relasi(
            $cpmk_record->id ?? 0,
            $sub_cpmk->id,
            $id_tahun_akademik
        );

        $b_cpmk = self::ambil_bobot_relasi(
            $indikator_record->id ?? 0,
            $cpmk_record->id ?? 0,
            $id_tahun_akademik
        );

        $b_ind = self::ambil_bobot_relasi(
            $cpl_record->id ?? 0,
            $indikator_record->id ?? 0,
            $id_tahun_akademik
        );

        $student_ids = array_keys($mahasiswa);

        //mengambil aktivitas pembelajaran
        $aktivitas = self::ambil_rincian_aktivitas_subcpmk(
            $courseid,
            $kompetensi_id,
            $student_ids
        );

        $aktivitas_list = $aktivitas->aktivitas_list;
        $grades_lookup  = $aktivitas->grades_lookup;

        //menghitung nilai subcpmk
        foreach ($student_ids as $userid) {

            $total = 0;

            //menghitung aktivitas mahasiswa
            foreach ($aktivitas_list as $akt) {
            //nilai mahasiswa pada aktivitas
                $grade = $grades_lookup[$userid][$akt->cmid] ?? null;
                //nilai yang diperoleh
                $skor = ($grade && $grade->finalgrade !== null)
                    ? (float)$grade->finalgrade
                    : 0;
                    //nilai maksimum
                $max = ($grade && $grade->rawgrademax)
                    ? (float)$grade->rawgrademax
                    : 100;
                    //diubah ke skala 100
                $nilai = ($max > 0)
                    ? ($skor / $max) * 100
                    : 0;
                    //lalu nilai nya dikalikan bobot
                $total += $nilai * ($akt->bobot_aktivitas / 100);
            }
            //menyimpan nilai akhir subcpmk
            $lookup_subcpmk[$userid] = round($total,2);
        }
    }

    // ==========================================================
    // DETAIL MAHASASISWA
    // ==========================================================
    $detail_mahasiswa = [];

    foreach ($mahasiswa as $userid => $user) {

    //menghitung nilai kompetensi
        $nilai = self::ambil_nilai_kompetensi_mahasiswa(
            $courseid,
            $kompetensi_id,
            $userid,
            $tipe,
            $id_tahun_akademik,
            $lookup_subcpmk
        );
        //konveri ke nilai grade
        $grade = self::konversi_nilai_ke_grade(
            $nilai,
            $tipe,
            $prodi_id,
            $id_tahun_akademik
        );

        //cek status kompeten
        $kompeten = self::cek_status_kompeten(
            $grade,
            $tipe,
            $prodi_id,
            $id_tahun_akademik
        );

        //menyimpan kontribusi nilai mahasiswa terhadap CPMK
        $kontribusi_cpmk = 0;
        $rincian_list = [];

        if ($tipe === 'SUB-CPMK') {

        //menghitung kontribusi nilai mahasiswa terhadap CPMK
        //nilai subu x bobot/100
            $kontribusi_cpmk = round(
                $nilai * ($b_sub / 100),
                2
            );
            //membuat rincian aktivitas mahasiswa

            foreach ($aktivitas_list as $akt) {

            //mengambil nilai mahasiswa pada aktivitas
                $gradeAkt = $grades_lookup[$userid][$akt->cmid] ?? null;

                //mengambil skor dan max nilai
                $skor = ($gradeAkt && $gradeAkt->finalgrade !== null)
                    ? (float)$gradeAkt->finalgrade
                    : 0;

                    //nilai maksimum
                $max = ($gradeAkt && $gradeAkt->rawgrademax)
                    ? (float)$gradeAkt->rawgrademax
                    : 100;

                    //diubah ke skala 100
                $nilaiAkt = ($max > 0)
                    ? ($skor / $max) * 100
                    : 0;

                    //menyimpan rincian aktivitas mahasiswa
                $rincian_list[] = (object)[
                    'nama_aktivitas'  => $gradeAkt->itemname ?? $akt->name,
                    'modulename'      => $akt->modulename,
                    'skor'            => $skor,
                    'max'             => $max,
                    'bobot_aktivitas' => $akt->bobot_aktivitas,
                    'nilai_terbobot'  => $nilaiAkt * ($akt->bobot_aktivitas / 100)
                ];
            }
        }
        //menyimpan detail mahasiswa

        $detail_mahasiswa[] = (object)[
            'id'               => $userid,
            'userid'           => $userid,
            'username'         => $user->username,
            'firstname'        => $user->firstname,
            'lastname'         => $user->lastname,
            'nama'             => fullname($user),

            'nilai'            => round($nilai,2),
            'total_nilai_obe'  => ($tipe === 'SUB-CPMK')
                ? round($nilai,2)
                : null,

            'kontribusi_cpmk'  => $kontribusi_cpmk,

            'grade'            => strtoupper(trim($grade ?? '-')),
            'kompeten'         => $kompeten,

            'rincian_list'     => $rincian_list
        ];
    }

    // ==========================================================
    // RINGKASAN
    // ==========================================================
    $ringkasan = self::buat_ringkasan_kelas(
        $detail_mahasiswa,
        $tipe,
        $prodi_id,
        $id_tahun_akademik
    );

    return (object)[
        'sub_cpmk'           => $sub_cpmk,
        'cpmk_record'        => $cpmk_record,
        'indikator_record'   => $indikator_record,
        'cpl_record'         => $cpl_record,

        'bobot_sub_display'  => $b_sub,
        'bobot_cpmk_display' => $b_cpmk,
        'bobot_ind_display'  => $b_ind,

        'aktivitas_list'     => $aktivitas_list,

        'ringkasan'          => $ringkasan,
        'detail_mahasiswa'   => $detail_mahasiswa,

        'prodi_id'           => $prodi_id
    ];
}
         /**
         * Helper: Membuat Ringkasan Capaian Kelas
         */
        public static function buat_ringkasan_kelas( array $detail_mahasiswa, $tipe, $prodi_id, $id_tahun_akademik) {

            $jumlah_mahasiswa = count($detail_mahasiswa);

            if ($jumlah_mahasiswa === 0) {
                return (object)[
                    'rata_rata'            => 0,
                    'grade'                => '-',
                    'jumlah_mahasiswa'     => 0,
                    'jumlah_kompeten'      => 0,
                    'jumlah_belum'         => 0,
                    'persentase_kompeten'  => 0,
                    'distribusi_grade'     => []
                ];
            }

            $total_nilai = 0;
            $jumlah_kompeten = 0;
            $distribusi_grade = [];

            foreach ($detail_mahasiswa as $mhs) {

                $total_nilai += $mhs->nilai;

                if ($mhs->kompeten) {
                    $jumlah_kompeten++;
                }

                $grade = strtoupper(trim($mhs->grade));

                if (!isset($distribusi_grade[$grade])) {
                    $distribusi_grade[$grade] = 0;
                }

                $distribusi_grade[$grade]++;
            }

            $rata_rata = $total_nilai / $jumlah_mahasiswa;

            $grade_rata_rata = self::konversi_nilai_ke_grade(
                $rata_rata,
                $tipe,
                $prodi_id,
                $id_tahun_akademik
            );

            $jumlah_belum = $jumlah_mahasiswa - $jumlah_kompeten;

            $persentase_kompeten = ($jumlah_mahasiswa > 0)
                ? round(($jumlah_kompeten / $jumlah_mahasiswa) * 100, 2)
                : 0;

            return (object)[
                'rata_rata'           => round($rata_rata, 2),
                'grade'               => $grade_rata_rata,
                'jumlah_mahasiswa'    => $jumlah_mahasiswa,
                'jumlah_kompeten'     => $jumlah_kompeten,
                'jumlah_belum'        => $jumlah_belum,
                'persentase_kompeten' => $persentase_kompeten,
                'distribusi_grade'    => $distribusi_grade
            ];
        }
        /**
         * Mengambil nilai kompetensi mahasiswa berdasarkan tipe kompetensi.
         */
        private static function ambil_nilai_kompetensi_mahasiswa( $courseid,  $kompetensi_id,  $userid,  $tipe,  $id_tahun_akademik,  array $lookup_subcpmk = []) {

            switch (strtoupper($tipe)) {

                case 'CPL':
                    return self::ambil_nilai_cpl_per_user(
                        $courseid,
                        $kompetensi_id,
                        $userid,
                        $id_tahun_akademik
                    );

                case 'INDIKATOR':
                    return self::ambil_nilai_indikator_per_user(
                        $courseid,
                        $kompetensi_id,
                        $userid,
                        $id_tahun_akademik
                    );

                case 'CPMK':
                    return self::ambil_nilai_cpmk_per_user(
                        $courseid,
                        $kompetensi_id,
                        $userid,
                        $id_tahun_akademik
                    );

                case 'SUB-CPMK':
                    return $lookup_subcpmk[$userid] ?? 0;

                default:
                    return 0;
            }
        }
        /**
         * Helper Konversi Nilai ke Grade
         */
        private static function konversi_nilai_ke_grade( $nilai, $tipe, $prodi_id, $id_tahun_akademik) {
            return \local_obe\manager\skala_nilai_manager::cek_skala(
                $nilai,
                $tipe,
                $prodi_id,
                $id_tahun_akademik
            );
        }
        private static function cek_status_kompeten($grade, $tipe, $prodi_id, $tahun){
            global $DB;

            return $DB->record_exists(
                'local_obe_skala_nilai',
                [
                    'huruf_nilai'=>$grade,
                    'tipe_kompetensi'=>$tipe,
                    'id_prodi'=>$prodi_id,
                    'id_tahun_akademik'=>$tahun,
                    'status_kompeten'=>1
                ]
            );
        }
        /**
         * Mengambil seluruh data aktivitas beserta nilai mahasiswa pada Sub-CPMK.                   
        */
        private static function ambil_rincian_aktivitas_subcpmk($courseid,$sub_cpmk_id,array $student_ids = []) {
            global $DB, $CFG;

            require_once($CFG->libdir . '/gradelib.php');

            // =====================================================
            // AMBIL COURSE MODULE SEKALI
            // =====================================================
            $modinfo = get_fast_modinfo($courseid);
            $cms = $modinfo->get_cms();

            // =====================================================
            // AMBIL MAPPING AKTIVITAS
            // =====================================================
            $maps = $DB->get_records(
                'local_obe_aktivitas_map',
                ['sub_cpmk_id' => $sub_cpmk_id]
            );

            $activities = [];

            foreach ($maps as $map) {

                $cm = $cms[$map->cmid] ?? null;

                if (!$cm || !empty($cm->deletioninprogress)) {
                    continue;
                }

                $activities[$map->id] = (object)[
                    'id'              => $map->id,
                    'cmid'            => $cm->id,
                    'instance'        => $cm->instance,
                    'modulename'      => $cm->modname,
                    'name'            => $cm->name,
                    'bobot_aktivitas' => (float)$map->bobot_aktivitas
                ];
            }

            // =====================================================
            // GRADE LOOKUP
            // =====================================================
            $lookup = [];

            $requestedusers = !empty($student_ids)
                ? array_values($student_ids)
                : null;

            foreach ($activities as $activity) {
                if (!has_capability('moodle/grade:viewhidden', \context_course::instance($courseid))) {
                    global $USER;
                    $item = \grade_item::fetch(['courseid' => $courseid, 'itemtype' => 'mod', 'itemmodule' => $activity->modulename, 'iteminstance' => $activity->instance, 'itemnumber' => 0]);
                    $own = $item ? \grade_grade::fetch(['itemid' => $item->id, 'userid' => $USER->id]) : false;
                    if (($item && $item->is_hidden()) || ($own && $own->is_hidden())) {
                        throw new \moodle_exception('nopermissions', 'error');
                    }
                }
            }

            foreach ($activities as $activity) {
                $grades = grade_get_grades(
                    $courseid,
                    'mod',
                    $activity->modulename,
                    $activity->instance,
                    $requestedusers
                );
                if (empty($grades->items)) {
                    continue;
                }
                $item = reset($grades->items);
                if (empty($item->grades)) {
                    continue;
                }
                foreach ($item->grades as $userid => $grade) {
                    $lookup[$userid][$activity->cmid] = (object)[
                        'userid'      => $userid,
                        'finalgrade'  => isset($grade->grade)
                            ? (float)$grade->grade
                            : null,
                        'rawgrademax' => (float)($item->grademax ?? 100),
                        'itemname'    => $item->name ?? $activity->name
                    ];
                }
            }

            return (object)[
                'aktivitas_list' => $activities,
                'grades_lookup'  => $lookup
            ];
        }
        /**
         * Menghasilkan lookup nilai akhir Sub-CPMK setiap mahasiswa.
         */
        private static function ambil_lookup_nilai_subcpmk( $courseid, $sub_cpmk_id, $id_tahun_akademik = 0) {
            global $SESSION;

            if (empty($id_tahun_akademik)) {
                $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
            }

            // =====================================================
            // AMBIL MAHASASISWA
            // =====================================================
            $students = self::ambil_mahasiswa_terdaftar($courseid);

            if (empty($students)) {
                return [];
            }

            $student_ids = array_keys($students);

            // =====================================================
            // AMBIL AKTIVITAS + NILAI SEKALI SAJA
            // =====================================================
            $data = self::ambil_rincian_aktivitas_subcpmk(
                $courseid,
                $sub_cpmk_id,
                $student_ids
            );

            $lookup = [];

            foreach ($students as $userid => $user) {

                $total_nilai = 0;

                foreach ($data->aktivitas_list as $aktivitas) {

                    $grade = $data->grades_lookup[$userid][$aktivitas->cmid] ?? null;

                    $skor = ($grade && $grade->finalgrade !== null)
                        ? (float)$grade->finalgrade
                        : 0;

                    $max = ($grade && $grade->rawgrademax)
                        ? (float)$grade->rawgrademax
                        : 100;

                    $nilai = ($max > 0)
                        ? ($skor / $max) * 100
                        : 0;

                    $total_nilai +=
                        $nilai * ($aktivitas->bobot_aktivitas / 100);
                }

                $lookup[$userid] = round($total_nilai, 2);
            }

            return $lookup;
        }
        

        private static function ambil_mahasiswa_terdaftar($courseid) {
        global $DB;
        $studentroles = array_keys($DB->get_records('role', ['archetype' => 'student']));
        $context = \context_course::instance($courseid);
        $users = get_enrolled_users($context, '', 0, 'u.*', 'u.lastname ASC, u.firstname ASC', 0, 0, true);
        $students = [];

        foreach ($users as $user) {
            if (!$user->suspended && !is_siteadmin($user->id) && array_filter(get_user_roles($context, $user->id), fn($role) => in_array($role->roleid, $studentroles))) {
                $students[$user->id] = $user;
            }
        }

        return $students;
        }
   
        private static function ambil_nilai_cpl_per_user( $courseid, $cpl_id,$userid, $id_tahun_akademik = 0){

            global $DB,$SESSION;

            if(empty($id_tahun_akademik)){
                $id_tahun_akademik=
                $SESSION->obe_id_tahun_akademik ?? 0;
            }
            // ===============================
            // Ambil semua indikator
            // ===============================
            $indikators=
            $DB->get_records('local_obe_kompetensi',

                [
                    'parent_id'=>$cpl_id,
                    'tipe'=>'INDIKATOR'
                ]
            );
            if(empty($indikators)){
                return 0;
            }
            $total=0;
            foreach( $indikators as $indikator){
                // bobot indikator terhadap CPL
                $bobot= self::ambil_bobot_relasi(
                    $cpl_id,
                    $indikator->id,
                    $id_tahun_akademik
                );
                // nilai indikator mahasiswa
                $nilai=self::ambil_nilai_indikator_per_user(
                    $courseid,
                    $indikator->id,
                    $userid,
                    $id_tahun_akademik
                );
                $total+= $nilai *( $bobot/100);
            }
            return round( $total, 2);
         }

        private static function ambil_nilai_cpmk_per_user( $courseid, $cpmk_id, $userid, $id_tahun_akademik = 0) {
    global $DB, $SESSION;

    if (empty($id_tahun_akademik)) {
        $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
    }
    // =====================================
    // AMBIL SEMUA SUB CPMK
    // =====================================
    $subs = $DB->get_records(
        'local_obe_kompetensi',
        [
            'parent_id' => $cpmk_id,
            'tipe'      => 'SUB-CPMK'
        ],
        '',
        'id,nama_kompetensi'
    );
    if (empty($subs)) {
        return 0;
    }
    $total_nilai_cpmk = 0;
    // HITUNG NILAI CPMK
    foreach ($subs as $sub) {
        $bobot_sub = self::ambil_bobot_relasi(
            $cpmk_id,
            $sub->id,
            $id_tahun_akademik
        );
        // Ambil lookup nilai Sub CPMK
        $lookup = self::ambil_lookup_nilai_subcpmk(
            $courseid,
            $sub->id,
            $id_tahun_akademik
        );
        $nilai_sub = $lookup[$userid] ?? 0;
        $total_nilai_cpmk +=
            $nilai_sub * ($bobot_sub / 100);
    }

    return round($total_nilai_cpmk, 2);
        }

        private static function ambil_nilai_indikator_per_user($courseid,$indikator_id, $userid,$id_tahun_akademik=0){
            global $DB,$SESSION;
            if(empty($id_tahun_akademik)){
                $id_tahun_akademik=
                $SESSION->obe_id_tahun_akademik ?? 0;
            }
            $cpmks=
            $DB->get_records(
                'local_obe_kompetensi',
                [
                    'parent_id'=>$indikator_id
                ]
            );
            if(empty($cpmks)){
                return 0;
            }
            $total=0;
            foreach(
                $cpmks as $cpmk){
                $bobot=self::ambil_bobot_relasi(
                    $indikator_id,
                    $cpmk->id,
                    $id_tahun_akademik
                );
                $nilai=self::ambil_nilai_cpmk_per_user(
                    $courseid,
                    $cpmk->id,
                    $userid,
                    $id_tahun_akademik
                );
                $total +=$nilai*( $bobot/100);
            }
           return $total;
         }
    /**
     * Mengambil struktur kompetensi pada capaian pembelajaran kelas.
     *
     * Digunakan untuk mengambil daftar:
     * - Indikator berdasarkan CPL
     * - CPMK berdasarkan Indikator
     * - Sub-CPMK berdasarkan CPMK
     */
    public static function ambil_struktur_kompetensi_kelas( $parent_id, $tipe, $id_tahun_akademik = 0, $prodi_id = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

       $kompetensi = $DB->get_records(
    'local_obe_kompetensi',
    [
        'parent_id' => $parent_id,
        'id_tahun_akademik' => $id_tahun_akademik,
        'tipe'      => strtoupper($tipe)
    ],
    'id ASC'
);

        if ($prodi_id) { $kompetensi = array_filter($kompetensi, fn($k) => (int)$k->id_prodi === (int)$prodi_id); }
        $hasil = [];

        foreach ($kompetensi as $item) {

            $hasil[] = (object)[
                'id'     => $item->id,
                'nama'   => $item->nama_kompetensi,
                'tipe'   => $item->tipe,
                'bobot'  => self::ambil_bobot_relasi(
                    $parent_id,
                    $item->id,
                    $id_tahun_akademik
                )
            ];
        }

        return $hasil;
    }
    public static function ambil_bobot_relasi($asal_id, $tujuan_id, $id_tahun_akademik = 0) {
            global $DB, $SESSION;
            $dbman = $DB->get_manager();
            
            if (!$dbman->table_exists('local_obe_bobot_relasi')) {
                return 0.00;
            }

            if (empty($id_tahun_akademik)) {
                $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
            }
            $relasi = $DB->get_record('local_obe_bobot_relasi', [
                'id_kompetensi_asal'   => $asal_id,
                'id_kompetensi_tujuan' => $tujuan_id,
                'id_tahun_akademik'    => $id_tahun_akademik
            ], 'nilai_bobot', IGNORE_MISSING);

            if (!$relasi) {
                $relasi = $DB->get_record('local_obe_bobot_relasi', [
                    'id_kompetensi_asal'   => $tujuan_id,
                    'id_kompetensi_tujuan' => $asal_id,
                    'id_tahun_akademik'    => $id_tahun_akademik
                ], 'nilai_bobot', IGNORE_MISSING);
            }
            return $relasi && isset($relasi->nilai_bobot) ? floatval($relasi->nilai_bobot) : 0.00;
    }
    
    /**
     * Mengambil struktur hierarki kompetensi pada suatu kelas secara aman
     * CPL -> Indikator -> CPMK -> Sub CPMK
     */
    public static function ambil_hierarki_kelas( $courseid,  $id_tahun_akademik = 0 ) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // 1. Ambil id_prodi dari kelas ini
        $prodi_id = $DB->get_field_sql("
            SELECT mk.id_prodi
            FROM {local_obe_relasi_kelas} rk
            JOIN {local_obe_mata_kuliah} mk ON rk.id_mata_kuliah = mk.id
            WHERE rk.id_kursus_moodle = :courseid
        ", ['courseid' => $courseid], IGNORE_MULTIPLE);

        if (empty($prodi_id)) {
            return [];
        }

        // 2. Ambil CPL dengan query SQL ketat yang HANYA memiliki hubungan dengan prodi_id ini
        // Menggunakan SQL JOIN untuk memastikan CPL benar-benar bagian dari prodi kelas bersangkutan
        $sql = "SELECT DISTINCT cpl.* 
                FROM {local_obe_kompetensi} cpl
                WHERE cpl.tipe = 'CPL'
                  AND cpl.id_prodi = :prodi_id
                  AND cpl.id_tahun_akademik = :tahun_akademik
                ORDER BY cpl.id ASC";

        $cpl_list = $DB->get_records_sql($sql, [
            'prodi_id' => $prodi_id,
            'tahun_akademik' => $id_tahun_akademik
        ]);

        if (empty($cpl_list)) {
            return [];
        }

        $hasil = [];

        foreach ($cpl_list as $cpl) {

            $indikator_list = self::ambil_struktur_kompetensi_kelas(
                $cpl->id,
                'INDIKATOR',
                $id_tahun_akademik
            );

            $indikator_data = [];

            foreach ($indikator_list as $indikator) {

                $cpmk_list = self::ambil_struktur_kompetensi_kelas(
                    $indikator->id,
                    'CPMK',
                    $id_tahun_akademik
                );

                $cpmk_data = [];

                foreach ($cpmk_list as $cpmk) {

                    $sub_list = self::ambil_struktur_kompetensi_kelas(
                        $cpmk->id,
                        'SUB-CPMK',
                        $id_tahun_akademik
                    );

                    $cpmk_data[] = (object)[
                        'id'            => $cpmk->id,
                        'nama'          => $cpmk->nama,
                        'bobot'         => $cpmk->bobot,
                        'subcpmk_list'  => $sub_list
                    ];
                }

                $indikator_data[] = (object)[
                    'id'         => $indikator->id,
                    'nama'       => $indikator->nama,
                    'bobot'      => $indikator->bobot,
                    'cpmk_list'  => $cpmk_data
                ];
            }

            $hasil[] = (object)[
                'id'              => $cpl->id,
                'nama'            => $cpl->nama_kompetensi,
                'deskripsi'       => $cpl->deskripsi,
                'indikator_list'  => $indikator_data
            ];
        }

        return $hasil;
    }

    /**
     * Mengambil seluruh data yang dibutuhkan untuk export kelas.
     */
    public static function ambil_data_export_kelas( $courseid, $id_tahun_akademik = 0) {
        global $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = aktivitasnilai_manager::ambil_tahun_id_dari_course($courseid);
        }

        // =====================================================
        // Ambil hierarki kompetensi
        // =====================================================
        $hierarki = self::ambil_hierarki_kelas(
            $courseid,
            $id_tahun_akademik
        );

        if (empty($hierarki)) {
            return [];
        }

        $hasil = [];

        foreach ($hierarki as $cpl) {

            // =====================================================
            // DETAIL CPL
            // =====================================================
            $detailCpl = self::ambil_detail_kompetensi_mahasiswa(
                $courseid,
                $cpl->id,
                'CPL',
                $id_tahun_akademik
            );

            $indikatorList = [];

            foreach ($cpl->indikator_list as $indikator) {

                // =====================================================
                // DETAIL INDIKATOR
                // =====================================================
                $detailIndikator = self::ambil_detail_kompetensi_mahasiswa(
                    $courseid,
                    $indikator->id,
                    'INDIKATOR',
                    $id_tahun_akademik
                );

                $cpmkList = [];

                foreach ($indikator->cpmk_list as $cpmk) {

                    // =====================================================
                    // DETAIL CPMK
                    // =====================================================
                    $detailCpmk = self::ambil_detail_kompetensi_mahasiswa(
                        $courseid,
                        $cpmk->id,
                        'CPMK',
                        $id_tahun_akademik
                    );

                    $subcpmkList = [];

                    foreach ($cpmk->subcpmk_list as $sub) {

                        // =====================================================
                        // DETAIL SUB CPMK
                        // =====================================================
                        $detailSub = self::ambil_detail_kompetensi_mahasiswa(
                            $courseid,
                            $sub->id,
                            'SUB-CPMK',
                            $id_tahun_akademik
                        );

                        $subcpmkList[] = (object)[
                            'kompetensi' => $sub,
                            'detail'     => $detailSub
                        ];
                    }

                    $cpmkList[] = (object)[
                        'kompetensi'   => $cpmk,
                        'detail'       => $detailCpmk,
                        'subcpmk_list' => $subcpmkList
                    ];
                }

                $indikatorList[] = (object)[
                    'kompetensi' => $indikator,
                    'detail'     => $detailIndikator,
                    'cpmk_list'  => $cpmkList
                ];
            }

            $hasil[] = (object)[
                'kompetensi'     => $cpl,
                'detail'         => $detailCpl,
                'indikator_list' => $indikatorList
            ];
        }

        return $hasil;
    }



          
     
}