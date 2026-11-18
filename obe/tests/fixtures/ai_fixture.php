<?php
namespace local_obe\tests;
defined('MOODLE_INTERNAL') || die();
trait ai_fixture {
    private function environment(string $subname='SUB-CPMK stok'): array {
        global $DB;
        $this->setAdminUser(); set_config('enabled',1,'core_competency');
        // Welcome emails render output and initialise the global page during fixture setup.
        set_config('sendcoursewelcomemessage',0,'enrol_manual');
        $g=$this->getDataGenerator(); $course=$g->create_course(); $teacher=$g->create_user(); $student=$g->create_user(); $other=$g->create_user();
        $g->enrol_user($teacher->id,$course->id,'editingteacher'); $g->enrol_user($student->id,$course->id,'student'); $g->enrol_user($other->id,$course->id,'student');
        $prodi=$DB->insert_record('local_obe_prodi',(object)['kode_prodi'=>'PILOT-'.random_int(1000,9999),'nama_prodi'=>'TRPL sintetis']);
        $year=$DB->insert_record('local_obe_tahun_akademik',(object)['nama_tahun'=>'2026/2027','status_aktif'=>1]);
        $cohort=$DB->insert_record('local_obe_angkatan',(object)['id_prodi'=>$prodi,'tahun_masuk'=>2026,'nama_angkatan'=>'Pilot 2026','status_aktif'=>1]);
        $mk=$DB->insert_record('local_obe_mata_kuliah',(object)['id_prodi'=>$prodi,'kode_mk'=>'TEST','nama_mk'=>'Pengujian Perangkat Lunak TRPL','sks_mk'=>3]);
        \local_obe\manager\relasikelas_manager::simpan((object)['id_mata_kuliah'=>$mk,'id_kursus_moodle'=>$course->id,'id_angkatan'=>$cohort,'id_tahun_akademik'=>$year,'waktu_koneksi'=>time()]);
        $cg=$g->get_plugin_generator('core_competency'); $fw=$cg->create_framework(); $fid=$fw->get('id');
        \local_obe\manager\prodiframework_manager::simpan((object)['id_prodi'=>$prodi,'frameworkid'=>$fid,'id_tahun_akademik'=>$year]);
        $parent=0;
        foreach (['CPL','INDIKATOR','CPMK','SUB-CPMK'] as $type) {
            $c=$cg->create_competency(['competencyframeworkid'=>$fid,'parentid'=>$parent,'shortname'=>($type==='SUB-CPMK'?$subname:$type.' stok'),'idnumber'=>$type.'-'.random_int(100,999)]);
            $parent=$c->get('id');
        }
        \local_obe\manager\kompetensi_manager::sync_competencies($prodi,$year);
        $nodes=$DB->get_records('local_obe_kompetensi',['id_prodi'=>$prodi,'id_tahun_akademik'=>$year]); $ids=[];
        foreach ($nodes as $c) {$ids[$c->tipe]=(int)$c->id; if ($c->parent_id) {\local_obe\manager\bobot_relasi_manager::save_bobot($c->id,100,$year);}}
        foreach (['CPL','INDIKATOR','CPMK','SUB-CPMK'] as $type) {
            foreach ([[0,74.99,0,'B'],[75,100,1,'A']] as $range) {
                \local_obe\manager\skala_nilai_manager::tambah((object)['id_prodi'=>$prodi,'id_tahun_akademik'=>$year,'tipe_kompetensi'=>$type,'nilai_min'=>$range[0],'nilai_max'=>$range[1],'status_kompeten'=>$range[2],'huruf_nilai'=>$range[3]]);
            }
        }
        $activity=$g->create_module('obeinterview',['course'=>$course->id,'focusid'=>$ids['CPL']]);
        \local_obe\manager\aktivitasnilai_manager::insert($activity->cmid,$ids['SUB-CPMK'],100,$course->id);
        return compact('course','teacher','student','other','prodi','year','ids','activity');
    }
    private function academic_snapshot(): string {
        global $DB;
        $out=[];
        foreach (['local_obe_prodi','local_obe_tahun_akademik','local_obe_angkatan','local_obe_mata_kuliah','local_obe_relasi_kelas','local_obe_prodi_framework','local_obe_kompetensi','local_obe_bobot_relasi','local_obe_aktivitas_map','local_obe_skala_nilai','grade_items','grade_grades'] as $table) {
            $out[$table]=$DB->get_records($table,[],'id ASC');
        }
        return json_encode($out,JSON_THROW_ON_ERROR);
    }
}
