<?php
defined('MOODLE_INTERNAL') || die();
class mod_obeinterview_generator extends testing_module_generator {
    public function create_instance($record=null,array $options=null) {
        $r=(object)(array)$record;
        foreach (['name'=>'Wawancara stok demo','rubricjson'=>json_encode(\local_obe\ai\rubric::defaults()),
            'corequestion'=>'Kasus batas apa yang Anda uji?','duration'=>600,'maxfollow'=>2,'retentiondays'=>180,'projectcmid'=>0] as $key=>$value) {
            if (!isset($r->$key)) {$r->$key=$value;}
        }
        return parent::create_instance($r,$options);
    }
}
