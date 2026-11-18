<?php
// Test-only bridge: preserve legacy local IDs/labels while creating genuine Moodle framework metadata.
defined('MOODLE_INTERNAL') || die();
function local_obe_test_bind_framework($generator,int $prodi,int $year): void {
    global $DB;
    if (!defined('PHPUNIT_TEST')||!PHPUNIT_TEST) {throw new LogicException('PHPUnit fixture only');}
    set_config('enabled',1,'core_competency');
    $cg=$generator->get_plugin_generator('core_competency');$fw=$cg->create_framework();$fid=$fw->get('id');
    $DB->insert_record('local_obe_prodi_framework',(object)['id_prodi'=>$prodi,'id_tahun_akademik'=>$year,'frameworkid'=>$fid]);
    $pending=$DB->get_records('local_obe_kompetensi',['id_prodi'=>$prodi,'id_tahun_akademik'=>$year],'id ASC');$ids=[];
    while ($pending) {
        $progress=false;
        foreach ($pending as $id=>$local) {
            if ($local->parent_id&&!isset($ids[$local->parent_id])) {continue;}
            $core=$cg->create_competency(['competencyframeworkid'=>$fid,'parentid'=>$local->parent_id?$ids[$local->parent_id]:0,
                'shortname'=>$local->nama_kompetensi,'description'=>$local->deskripsi??'','idnumber'=>'LEGACY-'.$id]);
            $ids[$id]=$core->get('id');$local->frameworkid=$fid;$local->competencyid=$ids[$id];
            $DB->update_record('local_obe_kompetensi',$local);unset($pending[$id]);$progress=true;
        }
        if (!$progress) {throw new LogicException('Legacy fixture has a missing or cyclic parent; fix its hierarchy');}
    }
}
