<?php
defined('MOODLE_INTERNAL') || die();
$functions=['local_obe_ai_runner'=>['classname'=>'local_obe\ai\external','methodname'=>'runner',
    'description'=>'Menyimpan bukti runner dari snapshot; tidak menulis nilai final.','type'=>'write','capabilities'=>'local/obe:ingestai','ajax'=>false]];
$services=['OBE AI runner pilot'=>['shortname'=>'obe_ai_runner','functions'=>['local_obe_ai_runner'],'restrictedusers'=>1,'enabled'=>0]];
