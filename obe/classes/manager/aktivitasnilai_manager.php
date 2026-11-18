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
        $active=\local_obe\local\assessment_integrity::maps((int)$courseid);
        $activebycm=[];foreach ($active as $a) {$activebycm[$a->cmid]=$a;}
        $results = [];

        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible && !has_capability('local/obe:managecourse',\context_course::instance($courseid))) {
                continue;
            }

            $map = $activebycm[$cm->id]??null;
            $row = (object)[
                'cmid'             => $cm->id,
                'modulename'       => $cm->modname,
                'instance'         => $cm->instance,
                'name'             => $cm->name,
                'deletioninprogress'=> !empty($cm->deletioninprogress),
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
        if (!is_finite($bobot) || $bobot < 0 || $bobot > 100) {
            throw new \moodle_exception('assessmenterror','local_obe','', 'Bobot aktivitas harus berada pada rentang 0 sampai 100.');
        }
        $cm=get_coursemodule_from_id('', $cmid, $courseid, false, MUST_EXIST);
        $courseid=(int)$cm->course;
        \local_obe\ai\scope::competency($courseid,(int)$sub_cpmk_id,true,\context_course::instance($courseid));
        \local_obe\ai\scope::path($courseid,(int)$sub_cpmk_id,\context_course::instance($courseid));
        if (!empty($cm->deletioninprogress)) {throw new \moodle_exception('assessmenterror','local_obe','', 'Aktivitas sedang dihapus; selesaikan atau tinjau penghapusan terlebih dahulu.');}
        if ($cm->modname==='obeinterview') {
            $activity=$DB->get_record('obeinterview',['id'=>$cm->instance],'*',MUST_EXIST);
            \local_obe\ai\scope::focus($courseid,(int)$activity->focusid,(int)$sub_cpmk_id,\context_module::instance($cmid));
        }
        //validasi subcpmk
        $sub = $DB->get_record('local_obe_kompetensi', ['id' => $sub_cpmk_id], '*', MUST_EXIST);
        if (strtoupper((string)$sub->tipe) !== 'SUB-CPMK') {
            throw new \moodle_exception('assessmenterror','local_obe','', 'Kompetensi yang dipilih bukan Sub-CPMK.');
        }

        $sql = 'cmid = :cmid';
        $params = ['cmid' => $cmid];
        if ($exclude_map_id > 0) {
            $sql .= ' AND id <> :excludeid';
            $params['excludeid'] = $exclude_map_id;
        }
        //validasi apakah aktivitas sudah memiliki pemetaan
        if ($DB->record_exists_select('local_obe_aktivitas_map', $sql, $params)) {
            throw new \moodle_exception('assessmenterror','local_obe','', 'Aktivitas Moodle ini sudah memiliki pemetaan. Hapus atau ubah pemetaan lama terlebih dahulu.');
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
                throw new \moodle_exception('assessmenterror','local_obe','', 'Sub-CPMK tidak berasal dari program studi yang sama dengan kelas Moodle ini.');
            }

            if ((int)$sub->id_tahun_akademik !== (int)$relasi->id_tahun_akademik) {
                throw new \moodle_exception('assessmenterror','local_obe','', 'Sub-CPMK tidak berasal dari tahun akademik yang sama dengan relasi kelas ini.');
            }
            //menjumlahkan bobot aktivitas yang sudah ada untuk sub-cpmk ini
            $usage = self::get_total_bobot_usage($courseid, $exclude_map_id);
            $total = (float)($usage[$sub_cpmk_id] ?? 0) + $bobot;
            if ($total > 100.000001) {
                throw new \moodle_exception('assessmenterror','local_obe','', 'Total bobot aktivitas untuk Sub-CPMK ini melebihi 100%.');
            }
        }
    }

    /**
     * CREATE: Menyimpan pemetaan baru.
     */
    public static function insert($cmid, $sub_cpmk_id, $bobot, $courseid = 0) {
        return \local_obe\local\assessment_integrity::atomic(static function() use ($cmid,$sub_cpmk_id,$bobot,$courseid) {return self::insert_locked($cmid,$sub_cpmk_id,$bobot,$courseid);});
    }
    private static function insert_locked($cmid, $sub_cpmk_id, $bobot, $courseid = 0) {
        global $DB;
        $cm=get_coursemodule_from_id('',(int)$cmid,$courseid,false,MUST_EXIST);$courseid=(int)$cm->course;
        \local_obe\ai\scope::competency($courseid,(int)$sub_cpmk_id,true,\context_course::instance($courseid));
        $retry=$DB->get_record('local_obe_aktivitas_map',['cmid'=>$cmid]);
        if ($retry&&(int)$retry->sub_cpmk_id===(int)$sub_cpmk_id&&abs((float)$retry->bobot_aktivitas-(float)$bobot)<0.000001) {return (int)$retry->id;}
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
        return \local_obe\local\assessment_integrity::atomic(static function() use ($id,$sub_cpmk_id,$bobot,$courseid) {return self::update_locked($id,$sub_cpmk_id,$bobot,$courseid);});
    }
    private static function update_locked($id, $sub_cpmk_id, $bobot, $courseid = 0) {
        global $DB;
        $existing = $DB->get_record('local_obe_aktivitas_map', ['id' => $id], '*', MUST_EXIST);
        if ((int)$existing->sub_cpmk_id!==(int)$sub_cpmk_id || abs((float)$existing->bobot_aktivitas-(float)$bobot)>0.000001) {\local_obe\local\assessment_integrity::protect((int)$existing->cmid);}
        $cm=get_coursemodule_from_id('',(int)$existing->cmid,$courseid,false,MUST_EXIST);$courseid=(int)$cm->course;
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
        return \local_obe\local\assessment_integrity::atomic(static function() use ($id) {return self::delete_locked($id);});
    }
    private static function delete_locked($id) {
        global $DB;
        $old = $DB->get_record('local_obe_aktivitas_map', ['id' => $id], '*', IGNORE_MISSING);
        if (!$old) {return true;}
        $cm=get_coursemodule_from_id('',(int)$old->cmid,0,false,MUST_EXIST);
        \local_obe\ai\scope::course((int)$cm->course,true,\context_course::instance($cm->course));
        \local_obe\local\assessment_integrity::protect((int)$old->cmid);
        \local_obe\local\assessment_integrity::archive('deleted_empty_map',(int)$id,['courseid'=>(int)$cm->course,'map'=>$old]);
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

        $totals=[];
        foreach (\local_obe\local\assessment_integrity::maps((int)$courseid) as $map) {
            if ((int)$map->id===(int)$exclude_map_id) {continue;}
            $totals[$map->sub_cpmk_id]=($totals[$map->sub_cpmk_id]??0)+(float)$map->bobot_aktivitas;
        }
        return $totals;
    }
    /**
 * Mencari ID Tahun Akademik yang terhubung dengan Course tertentu
 */

    public static function ambil_tahun_id_dari_course($courseid) {
        global $DB;
        $record = $DB->get_record('local_obe_relasi_kelas', ['id_kursus_moodle' => $courseid], 'id_tahun_akademik');
        return $record ? $record->id_tahun_akademik : 0;
    }
    public static function replace(int $id,int $newcmid,int $courseid,string $reason,string $expected,bool $ack): int {
        global $DB;
        \local_obe\ai\scope::course($courseid,true,\context_course::instance($courseid));
        if (!$ack||trim($reason)===''||\core_text::strlen($reason)>4000) {throw new \moodle_exception('assessmenterror','local_obe','', 'Konfirmasi dampak dan isi alasan penggantian (maksimum 4000 karakter).');}
        return \local_obe\local\assessment_integrity::atomic(static function() use($id,$newcmid,$courseid,$reason,$expected,$DB) {
            $old=$DB->get_record('local_obe_aktivitas_map',['id'=>$id]);
            if (!$old) {
                $archives=$DB->get_records('local_obe_audit_log',['entity'=>'activity_map_archive','entityid'=>$id],'id DESC');
                foreach ($archives as $archive) {$d=json_decode($archive->details,true);if (($d['courseid']??0)===$courseid&&($d['newcmid']??0)===$newcmid&&($d['expected']??'')===$expected&&$DB->record_exists('local_obe_aktivitas_map',['id'=>$d['newmapid'],'cmid'=>$newcmid])) {return (int)$d['newmapid'];}}
                throw new \moodle_exception('assessmenterror','local_obe','', 'Pemetaan sudah berubah. Muat ulang halaman sebelum mengganti.');
            }
            get_coursemodule_from_id('',(int)$old->cmid,$courseid,false,MUST_EXIST);
            $policy=trim((string)get_config('local_obe','replacementpolicy'));
            if (\local_obe\local\assessment_integrity::used((int)$old->cmid)&&$policy==='') {
                throw new \moodle_exception('assessmenterror','local_obe','', 'Penggantian aspek bernilai/berriwayat belum diizinkan. Pengelola harus menetapkan kebijakan revisi capaian terbit dan agregasi Gradebook di pengaturan OBE; atau gunakan kelas/versi kurikulum baru.');
            }
            if (!hash_equals(self::fingerprint($old),$expected)||$newcmid===(int)$old->cmid) {throw new \moodle_exception('assessmenterror','local_obe','', 'Pemetaan sudah berubah atau aktivitas pengganti sama. Muat ulang.');}
            self::validate_mapping_data($newcmid,$old->sub_cpmk_id,$old->bobot_aktivitas,$courseid,$id);
            $snapshot=\local_obe\local\assessment_integrity::snapshot($courseid);
            $new=(object)['cmid'=>$newcmid,'sub_cpmk_id'=>$old->sub_cpmk_id,'bobot_aktivitas'=>$old->bobot_aktivitas];
            $newid=(int)$DB->insert_record('local_obe_aktivitas_map',$new);
            \local_obe\local\assessment_integrity::archive('activity_map_archive',$id,['courseid'=>$courseid,'oldmap'=>$old,'newcmid'=>$newcmid,'newmapid'=>$newid,'reason'=>$reason,'policy'=>$policy,'expected'=>$expected,'before'=>$snapshot]);
            $DB->delete_records('local_obe_aktivitas_map',['id'=>$id]);
            return $newid;
        });
    }
    public static function fingerprint($map): string {
        global $DB;
        $courseid=(int)$DB->get_field('course_modules','course',['id'=>$map->cmid],MUST_EXIST);
        return hash('sha256',json_encode(['map'=>$map,'state'=>\local_obe\local\assessment_integrity::snapshot($courseid)],JSON_THROW_ON_ERROR));
    }

}
