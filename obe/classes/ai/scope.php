<?php
namespace local_obe\ai;
defined('MOODLE_INTERNAL') || die();
use local_obe\access_guard;
use local_obe\manager\aktivitasnilai_manager;

/** All AI identities are references to the existing academic registry. */
final class scope {
    /** Optional established context avoids reinitialising PAGE inside a module form/callback. */
    public static function course(int $courseid,bool $manage=false,?\context $context=null): \stdClass {
        global $DB, $USER;
        if ($context !== null) {
            // Moodle modedit has already authenticated and prepared the page. Only check access here.
            $coursecontext=$context->get_course_context();
            if ((int)$coursecontext->instanceid !== $courseid) {
                throw new \invalid_parameter_exception('Konteks bukan kelas yang diminta');
            }
            if (!isloggedin() || isguestuser()) {throw new \moodle_exception('nopermissions','error');}
            if ($manage) {require_capability('local/obe:managecourse',$coursecontext);}
            else if (!access_guard::is_system_manager()
                    && !has_capability('local/obe:viewreport',$coursecontext)
                    && !has_capability('local/obe:managecourse',$coursecontext)) {
                require_capability('local/obe:viewownreport',$coursecontext);
                if (!is_enrolled($coursecontext,$USER,'',true)) {throw new \moodle_exception('nopermissions','error');}
            }
        } else if ($manage) {access_guard::require_course_manage($courseid);}
        else {access_guard::require_enrolled_course_user($courseid);}
        $relation=$DB->get_record_sql('SELECT rk.*, mk.id_prodi FROM {local_obe_relasi_kelas} rk
            JOIN {local_obe_mata_kuliah} mk ON mk.id=rk.id_mata_kuliah
            WHERE rk.id_kursus_moodle=:course',['course'=>$courseid],MUST_EXIST);
        $kaprodi=access_guard::get_kaprodi_prodi();
        if ($kaprodi && (int)$kaprodi->id !== (int)$relation->id_prodi) {throw new \moodle_exception('nopermissions','error');}
        $relation->frameworkid=(int)$DB->get_field('local_obe_prodi_framework','frameworkid',[
            'id_prodi'=>$relation->id_prodi,'id_tahun_akademik'=>$relation->id_tahun_akademik],MUST_EXIST);
        return $relation;
    }

    public static function competency(int $courseid,int $id,bool $manage=false,?\context $context=null): \stdClass {
        global $DB;
        $r=self::course($courseid,$manage,$context);
        $c=$DB->get_record('local_obe_kompetensi',['id'=>$id,'id_prodi'=>$r->id_prodi,
            'id_tahun_akademik'=>$r->id_tahun_akademik,'frameworkid'=>$r->frameworkid],'*',MUST_EXIST);
        if (!$c->competencyid || !$DB->record_exists('competency',[
            'id'=>$c->competencyid,'competencyframeworkid'=>$r->frameworkid])) {throw new \invalid_parameter_exception('Kompetensi belum tersinkron dengan framework aktif');}
        return $c;
    }

    public static function path(int $courseid,int $subid,?\context $context=null): array {
        $expected=['SUB-CPMK','CPMK','INDIKATOR','CPL']; $path=[]; $next=$subid;
        foreach ($expected as $type) {
            $c=self::competency($courseid,$next,false,$context);
            if (strtoupper($c->tipe)!==$type) {throw new \invalid_parameter_exception('Hierarki CPL → Indikator → CPMK → Sub-CPMK tidak lengkap');}
            $path[]=$c; $next=(int)$c->parent_id;
        }
        if ($next!==0) {throw new \invalid_parameter_exception('Akar CPL tidak valid');}
        return array_reverse($path);
    }

    public static function mapping(int $cmid): \stdClass {
        global $DB;
        $cm=get_coursemodule_from_id('', $cmid,0,false,MUST_EXIST);
        // A read helper may run after OUTPUT->header(): never reinitialise PAGE/login here.
        $context=\context_module::instance($cmid);
        self::course((int)$cm->course,false,$context);
        $map=$DB->get_record('local_obe_aktivitas_map',['cmid'=>$cmid],'*',IGNORE_MISSING);
        if (!$map) {throw new mapping_missing();}
        $path=self::path((int)$cm->course,(int)$map->sub_cpmk_id,$context);
        $map->courseid=(int)$cm->course; $map->path=$path;
        // Read the authoritative manager, never create a parallel activity/weight table.
        $listed=aktivitasnilai_manager::ambil_semua_pemetaan($cm->course);
        if (!array_filter($listed,static function($m) use($cmid) {return (int)$m->cmid===$cmid;})) {throw new \moodle_exception('nopermissions','error');}
        return $map;
    }

    public static function focus(int $courseid,int $focusid,int $subid=0,?\context $context=null): \stdClass {
        $focus=self::competency($courseid,$focusid,false,$context);
        if (!in_array(strtoupper($focus->tipe),['CPL','INDIKATOR','CPMK','SUB-CPMK'],true)) {throw new \invalid_parameter_exception('Fokus tidak valid');}
        if (!$subid) {
            $types=['CPL','INDIKATOR','CPMK','SUB-CPMK']; $position=array_search(strtoupper($focus->tipe),$types,true); $cursor=$focus;
            while ($position>0) {
                $cursor=self::competency($courseid,(int)$cursor->parent_id,false,$context); $position--;
                if (strtoupper($cursor->tipe)!==$types[$position]) {throw new \invalid_parameter_exception('Hierarki fokus tidak lengkap');}
            }
            if ((int)$cursor->parent_id!==0) {throw new \invalid_parameter_exception('Akar CPL tidak valid');}
        }
        if ($subid) {
            $ids=array_map(static function($c) {return (int)$c->id;},self::path($courseid,$subid,$context));
            if (!in_array($focusid,$ids,true)) {throw new \invalid_parameter_exception('Fokus bukan leluhur Sub-CPMK terpetakan');}
        }
        return $focus;
    }

    public static function options(int $courseid,?\context $context=null): array {
        global $DB;
        $r=self::course($courseid,true,$context); $out=[];
        $rows=$DB->get_records('local_obe_kompetensi',['id_prodi'=>$r->id_prodi,
            'id_tahun_akademik'=>$r->id_tahun_akademik,'frameworkid'=>$r->frameworkid],'id ASC');
        foreach ($rows as $c) {
            if ($c->competencyid && $DB->record_exists('competency',['id'=>$c->competencyid,'competencyframeworkid'=>$r->frameworkid])) {
                $out[$c->id]=$c->tipe.' — '.$c->nama_kompetensi;
            }
        }
        return $out;
    }
}
