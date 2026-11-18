<?php
namespace mod_obeinterview\privacy;
defined('MOODLE_INTERNAL') || die();
final class provider implements \core_privacy\local\metadata\provider, \core_privacy\local\request\plugin\provider {
    public static function get_metadata(\core_privacy\local\metadata\collection $c): \core_privacy\local\metadata\collection {
        $c->add_database_table('obeinterview',['ownerid'=>'privacy:metadata'],'privacy:metadata'); return $c;
    }
    public static function get_contexts_for_userid(int $userid): \core_privacy\local\request\contextlist {
        $c=new \core_privacy\local\request\contextlist();
        $c->add_from_sql('SELECT ctx.id FROM {context} ctx JOIN {course_modules} cm ON cm.id=ctx.instanceid JOIN {modules} m ON m.id=cm.module AND m.name=:name JOIN {obeinterview} a ON a.id=cm.instance WHERE ctx.contextlevel=:level AND a.ownerid=:u',['name'=>'obeinterview','level'=>CONTEXT_MODULE,'u'=>$userid]); return $c;
    }
    public static function export_user_data(\core_privacy\local\request\approved_contextlist $list) {
        global $DB;
        foreach ($list->get_contexts() as $ctx) {
            if ($ctx->contextlevel!==CONTEXT_MODULE) {continue;}
            $cm=get_coursemodule_from_id('obeinterview',$ctx->instanceid,0,false,IGNORE_MISSING);
            if (!$cm) {continue;}
            $a=$DB->get_record('obeinterview',['id'=>$cm->instance,'ownerid'=>$list->get_user()->id]);
            if ($a) {\core_privacy\local\request\writer::with_context($ctx)->export_data(['Wawancara OBE AI'],(object)['ownerid'=>$a->ownerid]);}
        }
    }
    public static function delete_data_for_all_users_in_context(\context $ctx) {self::erase($ctx,0);}
    public static function delete_data_for_user(\core_privacy\local\request\approved_contextlist $list) {foreach ($list->get_contexts() as $ctx) {self::erase($ctx,(int)$list->get_user()->id);}}
    private static function erase(\context $ctx,int $userid) {
        global $DB; if ($ctx->contextlevel!==CONTEXT_MODULE) {return;}
        $cm=get_coursemodule_from_id('obeinterview',$ctx->instanceid,0,false,IGNORE_MISSING); if (!$cm) {return;}
        $where=['id'=>$cm->instance]; if ($userid) {$where['ownerid']=$userid;}
        $DB->set_field('obeinterview','ownerid',0,$where);
    }
}
