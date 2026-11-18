<?php
namespace local_obe\local;
defined('MOODLE_INTERNAL') || die();

/** One serialised writer and one active-map projection for capacity and reports. No grade writes. */
final class assessment_integrity {
    public static function atomic(callable $operation) {
        global $DB;
        $lock=\core\lock\lock_config::get_lock_factory('local_obe')->get_lock('assessment-weights',10);
        if (!$lock) {throw new \moodle_exception('assessmenterror','local_obe','', 'Penilaian sedang diubah pengguna lain. Muat ulang lalu coba kembali.');}
        try {$tx=$DB->start_delegated_transaction();try {$result=$operation();$tx->allow_commit();return $result;} catch (\Throwable $e) {$tx->rollback($e);}}
        finally {$lock->release();}
    }
    /** Hidden activities still own weight; deletion-in-progress activities remain counted until actual deletion.
     * This prevents a failed asynchronous delete silently removing an already graded contribution.
     */
    public static function maps(int $courseid,int $subid=0): array {
        global $DB;
        $params=['course'=>$courseid];$where='';
        if ($subid) {$where=' AND a.sub_cpmk_id=:sub';$params['sub']=$subid;}
        $rows=$DB->get_records_sql("SELECT a.* FROM {local_obe_aktivitas_map} a
            JOIN {course_modules} cm ON cm.id=a.cmid
            JOIN {local_obe_relasi_kelas} rk ON rk.id_kursus_moodle=cm.course
            JOIN {local_obe_mata_kuliah} mk ON mk.id=rk.id_mata_kuliah
            JOIN {local_obe_kompetensi} k ON k.id=a.sub_cpmk_id
            JOIN {local_obe_prodi_framework} pf ON pf.id_prodi=mk.id_prodi AND pf.id_tahun_akademik=rk.id_tahun_akademik
            WHERE cm.course=:course AND k.tipe='SUB-CPMK' AND k.id_prodi=mk.id_prodi
            AND k.id_tahun_akademik=rk.id_tahun_akademik AND k.frameworkid=pf.frameworkid $where ORDER BY a.id",$params);
        $seen=[];foreach ($rows as $row) {
            if (isset($seen[$row->cmid])||!is_finite((float)$row->bobot_aktivitas)||(float)$row->bobot_aktivitas<0||(float)$row->bobot_aktivitas>100) {throw new \moodle_exception('assessmenterror','local_obe','', 'Pemetaan ganda atau bobot tidak sah. Jalankan audit integritas; data tidak dibersihkan otomatis.');}
            $seen[$row->cmid]=true;
        }
        return $rows;
    }
    /** Any recorded zero/raw/final grade, decision, evidence or interview session requires retention. */
    public static function used(int $cmid): bool {
        global $DB;
        if ($DB->record_exists('local_obe_ai_evidence',['cmid'=>$cmid])||$DB->record_exists('local_obe_ai_session',['cmid'=>$cmid])) {return true;}
        $recorded=$DB->record_exists_sql("SELECT g.id FROM {grade_grades} g
            JOIN {grade_items} i ON i.id=g.itemid JOIN {course_modules} cm
              ON cm.course=i.courseid AND cm.instance=i.iteminstance
            JOIN {modules} m ON m.id=cm.module AND m.name=i.itemmodule
            WHERE cm.id=:cm AND i.itemtype='mod' AND (g.finalgrade IS NOT NULL OR g.rawgrade IS NOT NULL)",['cm'=>$cmid]);
        if ($recorded) {return true;}
        return $DB->record_exists_sql("SELECT g.id FROM {grade_grades_history} g JOIN {grade_items} i ON i.id=g.itemid JOIN {course_modules} cm ON cm.course=i.courseid AND cm.instance=i.iteminstance JOIN {modules} m ON m.id=cm.module AND m.name=i.itemmodule WHERE cm.id=:cm AND i.itemtype='mod' AND (g.finalgrade IS NOT NULL OR g.rawgrade IS NOT NULL)",['cm'=>$cmid]);
    }
    public static function protect(int $cmid): void {
        if (self::used($cmid)) {throw new \moodle_exception('assessmenterror','local_obe','', 'Aspek ini sudah memiliki nilai atau riwayat wawancara. Gunakan penggantian aspek untuk mengatur penilaian berikutnya. Nilai 0 juga dilindungi.');}
    }
    /** Strict archive write: failure must roll back replacement/deletion, unlike best-effort diagnostics. */
    public static function archive(string $entity,int $id,array $details): void {
        global $DB,$USER;
        $DB->insert_record('local_obe_audit_log',(object)['userid'=>(int)$USER->id,'action'=>'archive',
            'entity'=>$entity,'entityid'=>$id,'details'=>json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'timecreated'=>time()]);
    }
    public static function snapshot(int $courseid): array {
        global $DB;
        $out=['courseid'=>$courseid,'replacementpolicy'=>trim((string)get_config('local_obe','replacementpolicy')),'maps'=>array_values(self::maps($courseid))];
        $out['grades']=array_values($DB->get_records_sql("SELECT g.*,i.itemname,i.itemmodule,i.iteminstance,i.grademax FROM {grade_grades} g JOIN {grade_items} i ON i.id=g.itemid WHERE i.courseid=:c ORDER BY g.id",['c'=>$courseid]));
        $out['decisions']=array_values($DB->get_records_sql("SELECT d.* FROM {local_obe_ai_decision} d JOIN {local_obe_ai_evidence} e ON e.id=d.evidenceid JOIN {course_modules} cm ON cm.id=e.cmid WHERE cm.course=:c ORDER BY d.id",['c'=>$courseid]));
        $r=$DB->get_record_sql("SELECT rk.*,mk.id_prodi FROM {local_obe_relasi_kelas} rk JOIN {local_obe_mata_kuliah} mk ON mk.id=rk.id_mata_kuliah WHERE rk.id_kursus_moodle=:c",['c'=>$courseid],MUST_EXIST);
        $out['relation']=$r;
        foreach (['local_obe_kompetensi','local_obe_prodi_framework','local_obe_skala_nilai'] as $t) {$out[$t]=array_values($DB->get_records($t,['id_prodi'=>$r->id_prodi,'id_tahun_akademik'=>$r->id_tahun_akademik],'id ASC'));}
        $out['weights']=array_values($DB->get_records_sql("SELECT b.* FROM {local_obe_bobot_relasi} b JOIN {local_obe_kompetensi} k ON k.id=b.id_kompetensi_asal WHERE k.id_prodi=:p AND b.id_tahun_akademik=:y ORDER BY b.id",['p'=>$r->id_prodi,'y'=>$r->id_tahun_akademik]));
        return $out;
    }
    /** Runs before synchronous Moodle module deletion; asynchronous deletion reaches this in the task. */
    public static function before_module_delete($cm): void {
        global $DB;
        $mapped=$DB->record_exists('local_obe_aktivitas_map',['cmid'=>$cm->id]);
        $interview=$DB->get_field('modules','name',['id'=>$cm->module])==='obeinterview';
        if (!$mapped&&!$interview) {return;}
        self::atomic(static function() use($cm,$DB) {
            self::protect((int)$cm->id);
            foreach ($DB->get_records('local_obe_aktivitas_map',['cmid'=>$cm->id]) as $map) {
                self::archive('core_delete_requested_map',(int)$map->id,['courseid'=>(int)$cm->course,'map'=>$map,'cause'=>'core_module_delete_requested','note'=>'Mapping remains active until course_modules row is actually removed; no early weight release.']);
            }
        });
    }
    /** Curriculum weights retain graded/history-bearing descendants; sync can also require no mappings. */
    public static function protect_competency(int $id,bool $allmapped=false): void {
        global $DB;$pending=[$id];$seen=[];
        while ($pending) {
            $node=array_pop($pending);if(isset($seen[$node])) {continue;}$seen[$node]=true;
            foreach ($DB->get_records('local_obe_aktivitas_map',['sub_cpmk_id'=>$node]) as $map) {
                if (!$allmapped&&$DB->record_exists('course_modules',['id'=>$map->cmid])&&!self::used((int)$map->cmid)) {continue;}
                throw new \moodle_exception('assessmenterror','local_obe','', 'Kompetensi masih memiliki nilai/riwayat atau referensi pemetaan yang harus dipertahankan. Gunakan versi kurikulum baru untuk perubahan bobot pada capaian terbit.');
            }
            foreach ($DB->get_records('local_obe_kompetensi',['parent_id'=>$node]) as $child) {$pending[]=(int)$child->id;}
        }
    }
    /** Canonical child->parent edges; roots get their own scoped CPL sibling budget. */
    public static function relations(int $prodi,int $year): array {
        global $DB;
        $rows=$DB->get_records_sql("SELECT b.*,k.parent_id FROM {local_obe_bobot_relasi} b
            JOIN {local_obe_kompetensi} k ON k.id=b.id_kompetensi_asal
            JOIN {local_obe_prodi_framework} pf ON pf.id_prodi=k.id_prodi AND pf.id_tahun_akademik=k.id_tahun_akademik AND pf.frameworkid=k.frameworkid
            LEFT JOIN {local_obe_kompetensi} p ON p.id=k.parent_id
            WHERE k.id_prodi=:p AND k.id_tahun_akademik=:y AND b.id_tahun_akademik=k.id_tahun_akademik
              AND b.id_kompetensi_tujuan=k.parent_id
              AND (k.parent_id=0 OR (p.id_prodi=k.id_prodi AND p.id_tahun_akademik=k.id_tahun_akademik AND p.frameworkid=k.frameworkid)) ORDER BY b.id",['p'=>$prodi,'y'=>$year]);
        $seen=[];
        foreach ($rows as $row) {if(isset($seen[$row->id_kompetensi_asal])||!is_finite((float)$row->nilai_bobot)||(float)$row->nilai_bobot<0||(float)$row->nilai_bobot>100) {throw new \moodle_exception('assessmenterror','local_obe','', 'Bobot relasi ganda. Minta pengelola meninjau audit integritas; tidak ada data dibersihkan otomatis.');}$seen[$row->id_kompetensi_asal]=true;}
        return $rows;
    }
    public static function relation_weight(int $a,int $b,int $year): float {
        global $DB;
        foreach ([$a,$b] as $id) {
            $k=$DB->get_record('local_obe_kompetensi',['id'=>$id,'id_tahun_akademik'=>$year]);
            if (!$k) {continue;}
            foreach (self::relations((int)$k->id_prodi,$year) as $edge) {
                if (((int)$edge->id_kompetensi_asal===$a&&(int)$edge->id_kompetensi_tujuan===$b)||((int)$edge->id_kompetensi_asal===$b&&(int)$edge->id_kompetensi_tujuan===$a)) {return (float)$edge->nilai_bobot;}
            }
        }
        return 0;
    }
}
