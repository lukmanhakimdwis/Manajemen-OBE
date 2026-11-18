<?php
namespace local_obe\privacy;
defined('MOODLE_INTERNAL') || die();
use core_privacy\local\metadata\collection;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
/** Privacy handling for NEW AI feature data. Legacy OBE academic data retains its original lifecycle. */
final class provider implements \core_privacy\local\metadata\provider, \core_privacy\local\request\plugin\provider {
    public static function get_metadata(collection $c): collection {
        foreach (['local_obe_ai_session'=>['userid','configjson','snapshot'], 'local_obe_ai_turn'=>['sessionid','body'],
            'local_obe_ai_evidence'=>['userid','body','score'], 'local_obe_ai_decision'=>['actorid','body'],
            'local_obe_ai_draft'=>['authorid','contexttext','body']] as $table=>$fields) {
            $descriptions=[]; foreach ($fields as $field) {$descriptions[$field]='privacy:ai';}
            $c->add_database_table($table,$descriptions,'privacy:ai');
        }
        return $c;
    }
    public static function get_contexts_for_userid(int $userid): contextlist {
        $c=new contextlist();
        $c->add_from_sql('SELECT DISTINCT ctx.id FROM {context} ctx JOIN {local_obe_ai_draft} d ON d.courseid=ctx.instanceid WHERE ctx.contextlevel=:level AND d.authorid=:u',['level'=>CONTEXT_COURSE,'u'=>$userid]);
        foreach (['local_obe_ai_session','local_obe_ai_evidence'] as $table) {
            $c->add_from_sql("SELECT DISTINCT ctx.id FROM {context} ctx JOIN {course_modules} cm ON cm.course=ctx.instanceid JOIN {{$table}} e ON e.cmid=cm.id WHERE ctx.contextlevel=:level AND e.userid=:u",['level'=>CONTEXT_COURSE,'u'=>$userid]);
        }
        $c->add_from_sql('SELECT DISTINCT ctx.id FROM {context} ctx JOIN {course_modules} cm ON cm.course=ctx.instanceid JOIN {local_obe_ai_evidence} e ON e.cmid=cm.id JOIN {local_obe_ai_decision} d ON d.evidenceid=e.id WHERE ctx.contextlevel=:level AND d.actorid=:u',['level'=>CONTEXT_COURSE,'u'=>$userid]);
        return $c;
    }
    public static function export_user_data(approved_contextlist $list) {
        global $DB; $userid=$list->get_user()->id;
        foreach ($list->get_contexts() as $ctx) {
            if ($ctx->contextlevel!==CONTEXT_COURSE) {continue;}
            $sessions=$DB->get_records_sql('SELECT s.* FROM {local_obe_ai_session} s JOIN {course_modules} cm ON cm.id=s.cmid WHERE cm.course=:course AND s.userid=:u',['course'=>$ctx->instanceid,'u'=>$userid]);
            $turns=[]; foreach ($sessions as $s) {$turns=array_merge($turns,array_values($DB->get_records('local_obe_ai_turn',['sessionid'=>$s->id])));}
            $e=$DB->get_records_sql('SELECT e.* FROM {local_obe_ai_evidence} e JOIN {course_modules} cm ON cm.id=e.cmid WHERE cm.course=:course AND e.userid=:u',['course'=>$ctx->instanceid,'u'=>$userid]);
            $d=$DB->get_records_sql('SELECT d.* FROM {local_obe_ai_decision} d JOIN {local_obe_ai_evidence} e ON e.id=d.evidenceid JOIN {course_modules} cm ON cm.id=e.cmid WHERE cm.course=:course AND (d.actorid=:actor OR e.userid=:student)',['course'=>$ctx->instanceid,'actor'=>$userid,'student'=>$userid]);
            $drafts=$DB->get_records('local_obe_ai_draft',['courseid'=>$ctx->instanceid,'authorid'=>$userid]);
            writer::with_context($ctx)->export_data(['OBE AI'],(object)['sessions'=>array_values($sessions),'turns'=>$turns,'evidence'=>array_values($e),'decisions'=>array_values($d),'drafts'=>array_values($drafts)]);
        }
    }
    public static function delete_data_for_all_users_in_context(\context $ctx) {self::erase($ctx,0);}
    public static function delete_data_for_user(approved_contextlist $list) {
        foreach ($list->get_contexts() as $ctx) {self::erase($ctx,(int)$list->get_user()->id);}
    }
    private static function erase(\context $ctx,int $userid) {
        global $DB;
        if ($ctx->contextlevel!==CONTEXT_COURSE) {return;}
        $where=''; $params=['course'=>$ctx->instanceid]; if ($userid) {$where=' AND e.userid=:u';$params['u']=$userid;}
        $tx=$DB->start_delegated_transaction();
        $evidence=$DB->get_records_sql("SELECT e.* FROM {local_obe_ai_evidence} e JOIN {course_modules} cm ON cm.id=e.cmid WHERE cm.course=:course $where",$params);
        foreach ($evidence as $e) {$DB->delete_records('local_obe_ai_decision',['evidenceid'=>$e->id]); $DB->delete_records('local_obe_ai_evidence',['id'=>$e->id]);}
        $sessions=$DB->get_records_sql("SELECT e.* FROM {local_obe_ai_session} e JOIN {course_modules} cm ON cm.id=e.cmid WHERE cm.course=:course $where",$params);
        foreach ($sessions as $s) {$DB->delete_records('local_obe_ai_turn',['sessionid'=>$s->id]); $DB->delete_records('local_obe_ai_session',['id'=>$s->id]);}
        $drafts=['courseid'=>$ctx->instanceid]; if ($userid) {$drafts['authorid']=$userid;}
        $DB->delete_records('local_obe_ai_draft',$drafts);
        // Reviewer identity is anonymised only within the approved course context.
        if ($userid) {
            $sql='actorid=:u AND evidenceid IN (SELECT e.id FROM {local_obe_ai_evidence} e JOIN {course_modules} cm ON cm.id=e.cmid WHERE cm.course=:course)';
            $DB->set_field_select('local_obe_ai_decision','actorid',0,$sql,['u'=>$userid,'course'=>$ctx->instanceid]);
        }
        $tx->allow_commit();
    }
}
