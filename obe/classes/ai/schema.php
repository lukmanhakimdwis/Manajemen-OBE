<?php
namespace local_obe\ai;
defined('MOODLE_INTERNAL') || die();

/** Additive schema shared by fresh-install XMLDB and the 2026111502 migration. */
final class schema {
    public static function definitions(): array {
        return [
            'local_obe_ai_draft' => [
                'fields' => [
                    'courseid'=>['int','10',true,null], 'authorid'=>['int','10',true,null],
                    'competencyid'=>['int','10',true,null], 'contexttext'=>['text',null,true,null],
                    'body'=>['text',null,true,null], 'provider'=>['char','32',true,null],
                    'model'=>['char','100',true,null], 'rubricversion'=>['char','64',true,null],
                    'status'=>['char','20',true,'draft'], 'questionid'=>['int','10',true,'0'],
                    'timecreated'=>['int','10',true,'0'], 'timemodified'=>['int','10',true,'0'],
                ], 'indexes'=>[['courseauthor',false,['courseid','authorid']]],
            ],
            'local_obe_ai_session' => [
                'fields'=>[
                    'cmid'=>['int','10',true,null], 'userid'=>['int','10',true,null],
                    'configjson'=>['text',null,true,null], 'rubricversion'=>['char','64',true,null],
                    'snapshot'=>['char','64',true,null], 'snapshotid'=>['int','10',true,'0'],
                    'deadline'=>['int','10',true,null], 'status'=>['char','20',true,'active'],
                    'timecreated'=>['int','10',true,'0'], 'timemodified'=>['int','10',true,'0'],
                ], 'indexes'=>[['cmuser',true,['cmid','userid']]],
            ],
            'local_obe_ai_turn' => [
                'fields'=>[
                    'sessionid'=>['int','10',true,null], 'requestid'=>['char','80',true,null],
                    'speaker'=>['char','16',true,null], 'body'=>['text',null,true,null],
                    'questionkey'=>['char','80',true,null], 'competencyid'=>['int','10',true,null],
                    'provider'=>['char','32',true,null], 'model'=>['char','100',true,null],
                    'timecreated'=>['int','10',true,'0'],
                ], 'indexes'=>[['requestspeaker',true,['sessionid','requestid','speaker']]],
            ],
            'local_obe_ai_evidence' => [
                'fields'=>[
                    'cmid'=>['int','10',true,null], 'userid'=>['int','10',true,null],
                    'attemptkey'=>['char','80',true,null], 'source'=>['char','32',true,null],
                    'sessionid'=>['int','10',true,'0'], 'snapshot'=>['char','64',true,null],
                    'rubricversion'=>['char','64',true,null], 'body'=>['text',null,true,null],
                    'payloadhash'=>['char','64',true,null], 'score'=>['number','10,5',false,null],
                    'state'=>['char','20',true,'proposed'], 'revision'=>['int','10',true,'1'],
                    'approvedby'=>['int','10',true,'0'], 'timecreated'=>['int','10',true,'0'],
                    'timemodified'=>['int','10',true,'0'],
                ], 'indexes'=>[['attemptuser',true,['cmid','userid','attemptkey']]],
            ],
            'local_obe_ai_decision' => [
                'fields'=>[
                    'evidenceid'=>['int','10',true,null], 'actorid'=>['int','10',true,null],
                    'revision'=>['int','10',true,null], 'body'=>['text',null,true,null],
                    'timecreated'=>['int','10',true,'0'],
                ], 'indexes'=>[['evidencerevision',true,['evidenceid','revision']]],
            ],
        ];
    }

    public static function install(): void {
        global $DB;
        $dbman=$DB->get_manager();
        $types=['int'=>XMLDB_TYPE_INTEGER,'char'=>XMLDB_TYPE_CHAR,'text'=>XMLDB_TYPE_TEXT,'number'=>XMLDB_TYPE_NUMBER];
        foreach (self::definitions() as $name=>$definition) {
            $table=new \xmldb_table($name);
            if ($dbman->table_exists($table)) {continue;}
            $table->add_field('id',XMLDB_TYPE_INTEGER,'10',null,XMLDB_NOTNULL,XMLDB_SEQUENCE,null);
            foreach ($definition['fields'] as $field=>$f) {
                $table->add_field($field,$types[$f[0]],$f[1],null,$f[2]?XMLDB_NOTNULL:null,null,$f[3]);
            }
            $table->add_key('primary',XMLDB_KEY_PRIMARY,['id']);
            foreach ($definition['indexes'] as $index) {
                $table->add_index($index[0],$index[1]?XMLDB_INDEX_UNIQUE:XMLDB_INDEX_NOTUNIQUE,$index[2]);
            }
            $dbman->create_table($table);
        }
    }
}
