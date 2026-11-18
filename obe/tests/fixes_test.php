<?php
// Regression tests for the corrected business and security contracts.
use local_obe\access_guard;
use local_obe\controller\aktivitasnilai_controller;
use local_obe\controller\matakuliah_controller;
use local_obe\controller\angkatan_controller;
use local_obe\manager\bobot_relasi_manager;
use local_obe\manager\capaian_kelas_manager;
use local_obe\manager\capaian_prodi_manager;
use local_obe\manager\capaian_mahasiswa_manager;
use local_obe\manager\skala_nilai_manager;
use local_obe\manager\kompetensi_manager;
use local_obe\manager\matakuliah_manager;
use local_obe\manager\angkatan_manager;
use local_obe\manager\prodiframework_manager;

final class fixes_test extends advanced_testcase {
    private function fixture(?float $score = 80,float $subweight=100): object {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->setAdminUser();
        $g = $this->getDataGenerator();
        $p = $DB->insert_record('local_obe_prodi', (object)[
            'kode_prodi' => 'AUDIT', 'nama_prodi' => 'Audit Program']);
        $y = $DB->insert_record('local_obe_tahun_akademik', (object)[
            'nama_tahun' => '2026/2027', 'status_aktif' => 1]);
        $a = $DB->insert_record('local_obe_angkatan', (object)[
            'id_prodi' => $p, 'tahun_masuk' => 2026, 'nama_angkatan' => 'Audit 2026']);
        $mk = $DB->insert_record('local_obe_mata_kuliah', (object)[
            'id_prodi' => $p, 'kode_mk' => 'A01', 'nama_mk' => 'Audit MK', 'sks_mk' => 3]);
        $course = $g->create_course();
        $student = $g->create_user();
        $g->enrol_user($student->id, $course->id, 'student');
        $relasi = $DB->insert_record('local_obe_relasi_kelas', (object)[
            'id_mata_kuliah' => $mk, 'id_kursus_moodle' => $course->id,
            'id_angkatan' => $a, 'id_tahun_akademik' => $y]);
        set_config('enabled',1,'core_competency');
        $cg=$g->get_plugin_generator('core_competency');$fw=$cg->create_framework();
        prodiframework_manager::simpan((object)['id_prodi'=>$p,'frameworkid'=>$fw->get('id'),'id_tahun_akademik'=>$y]);
        $parent=0;
        foreach (['CPL','INDIKATOR','CPMK','SUB-CPMK'] as $type) {
            $node=$cg->create_competency(['competencyframeworkid'=>$fw->get('id'),'parentid'=>$parent,'shortname'=>'Audit '.$type]);$parent=$node->get('id');
        }
        kompetensi_manager::sync_competencies($p,$y);$nodes=[];
        foreach ($DB->get_records('local_obe_kompetensi',['id_prodi'=>$p,'id_tahun_akademik'=>$y]) as $node) {
            $nodes[$node->tipe]=$node->id;
            if ($node->parent_id) {bobot_relasi_manager::save_bobot($node->id,$node->tipe==='SUB-CPMK'?$subweight:100,$y);}
            foreach ([['D', 0, 59.99, 0], ['C', 60, 79.99, 1], ['A', 80, 100, 1]] as $band) {
                skala_nilai_manager::tambah((object)['id_prodi'=>$p,'id_tahun_akademik'=>$y,'tipe_kompetensi'=>$node->tipe,
                    'huruf_nilai'=>$band[0],'nilai_min'=>$band[1],'nilai_max'=>$band[2],'status_kompeten'=>$band[3]]);
            }
        }
        $parent=$nodes['SUB-CPMK'];
        $assign = $g->create_module('assign', ['course' => $course->id, 'grade' => 100]);
        if ($score!==null) {grade_update('mod/assign', $course->id, 'mod', 'assign', $assign->id, 0,
            ['userid' => $student->id, 'rawgrade' => $score],
            ['itemname' => $assign->name, 'grademax' => 100, 'grademin' => 0]);}
        $mapping = $DB->insert_record('local_obe_aktivitas_map', (object)[
            'cmid' => $assign->cmid, 'sub_cpmk_id' => $parent, 'bobot_aktivitas' => 100]);
        return (object)compact('p', 'y', 'a', 'mk', 'course', 'student', 'nodes', 'assign', 'mapping', 'relasi');
    }



    public function test_program_report_respects_noncompetent_grade(): void {
        $this->resetAfterTest(true);
        $f = $this->fixture(50);
        $class = capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($f->course->id, $f->nodes['SUB-CPMK'], 'SUB-CPMK', $f->y);
        $program = capaian_prodi_manager::ambil_detail_kompetensi_mahasiswa_prodi($f->p, $f->nodes['SUB-CPMK'], 'SUB-CPMK', $f->y);
        $this->assertEquals(50, $class->detail_mahasiswa[0]->nilai);
        $this->assertEquals('D', $class->detail_mahasiswa[0]->grade);
        $this->assertFalse($class->detail_mahasiswa[0]->kompeten);
        $this->assertFalse($program->detail_mahasiswa[0]->kompeten);
    }

    public function test_unmapped_subject_does_not_dilute_achievement(): void {
        global $DB;
        $this->resetAfterTest(true);
        $f = $this->fixture(80);
        $mk2 = $DB->insert_record('local_obe_mata_kuliah', (object)[
            'id_prodi' => $f->p, 'kode_mk' => 'B02', 'nama_mk' => 'No mapping to target', 'sks_mk' => 3]);
        $course2 = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($f->student->id, $course2->id, 'student');
        $DB->insert_record('local_obe_relasi_kelas', (object)[
            'id_mata_kuliah' => $mk2, 'id_kursus_moodle' => $course2->id,
            'id_angkatan' => $f->a, 'id_tahun_akademik' => $f->y]);
        $d = capaian_mahasiswa_manager::ambil_detail_subcpmk($f->student->id, $f->nodes['SUB-CPMK'], $f->p, $f->y);
        $this->assertCount(1, $d->matakuliah_pendukung);
        $this->assertEquals(80, $d->nilai);
        $program = capaian_prodi_manager::ambil_detail_kompetensi_mahasiswa_prodi($f->p, $f->nodes['SUB-CPMK'], 'SUB-CPMK', $f->y);
        $this->assertEquals(80, $program->detail_mahasiswa[0]->nilai);
    }

    public function test_grade_band_rejects_values_above_maximum(): void {
        global $DB;
        $this->resetAfterTest(true);
        $f = $this->fixture();
        $DB->delete_records('local_obe_skala_nilai', ['id_prodi' => $f->p]);
        skala_nilai_manager::tambah((object)[
            'id_prodi' => $f->p, 'id_tahun_akademik' => $f->y, 'tipe_kompetensi' => 'CPL',
            'huruf_nilai' => 'D', 'nilai_min' => 0, 'nilai_max' => 50, 'status_kompeten' => 0]);
        $this->assertEquals('-', skala_nilai_manager::cek_skala(50.5, 'CPL', $f->p, $f->y));
    }

    public function test_partial_weights_are_consistent_across_reports(): void {
        $this->resetAfterTest(true);
        $f = $this->fixture(80,50); // Set the intended weight before any grade/history exists.
        $class = capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($f->course->id, $f->nodes['CPMK'], 'CPMK', $f->y);
        $student = capaian_mahasiswa_manager::ambil_detail_cpmk($f->student->id, $f->nodes['CPMK'], $f->p, $f->y);
        $this->assertEquals(40, $class->detail_mahasiswa[0]->nilai);
        $this->assertEquals(40, $student->nilai);
    }

    public function test_only_active_students_are_counted(): void {
        $this->resetAfterTest(true);
        $f = $this->fixture();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $f->course->id, 'teacher');
        $suspended = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($suspended->id, $f->course->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $result = capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($f->course->id, $f->nodes['SUB-CPMK'], 'SUB-CPMK', $f->y);
        $this->assertEquals(1, $result->ringkasan->jumlah_mahasiswa);
        $this->assertEqualsWithDelta(80, $result->ringkasan->rata_rata, 0.001);
    }


    public function test_used_master_cannot_be_deleted(): void {
        $this->resetAfterTest(true); $f=$this->fixture();
        $this->expectException(\moodle_exception::class);
        matakuliah_controller::eksekusi_hapus($f->mk);
    }
    public function test_sync_removes_unreferenced_deleted_competency(): void {
        global $DB;
        $this->resetAfterTest(true); $this->setAdminUser();
        $g=$this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework=$g->create_framework(); $root=$g->create_competency(['competencyframeworkid'=>$framework->get('id')]);
        $child=$g->create_competency(['competencyframeworkid'=>$framework->get('id'),'parentid'=>$root->get('id')]);
        $p=$DB->insert_record('local_obe_prodi',(object)['kode_prodi'=>'SYNC','nama_prodi'=>'Sync']);
        $DB->insert_record('local_obe_prodi_framework',(object)['id_prodi'=>$p,'frameworkid'=>$framework->get('id'),'id_tahun_akademik'=>1]);
        kompetensi_manager::sync_competencies($p,1);
        \core_competency\api::delete_competency($child->get('id'));
        kompetensi_manager::sync_competencies($p,1);
        $this->assertFalse($DB->record_exists('local_obe_kompetensi',['competencyid'=>$child->get('id'),'id_prodi'=>$p]));
    }

    public function test_subject_export_filters_program_and_year(): void {
        global $DB;
        $this->resetAfterTest(true);
        $f = $this->fixture();
        $p2 = $DB->insert_record('local_obe_prodi', (object)[
            'kode_prodi' => 'OTHER', 'nama_prodi' => 'Other Program']);
        $other = $DB->insert_record('local_obe_kompetensi', (object)[
            'id_prodi' => $p2, 'id_tahun_akademik' => $f->y, 'parent_id' => 0,
            'nama_kompetensi' => 'OTHER PROGRAM CPL', 'tipe' => 'CPL']);
        $year2 = $DB->insert_record('local_obe_tahun_akademik', (object)['nama_tahun' => '2027/2028']);
        $wrongyear = $DB->insert_record('local_obe_kompetensi', (object)[
            'id_prodi' => $f->p, 'id_tahun_akademik' => $year2, 'parent_id' => 0,
            'nama_kompetensi' => 'OTHER YEAR CPL', 'tipe' => 'CPL']);
        $data = \local_obe\manager\capaian_matakuliah_manager::ambil_data_export_matakuliah($f->mk, $f->y, $f->a);
        $ids = array_map(fn($item) => (int)$item->kompetensi->id, $data);
        $this->assertCount(1, $ids);
        $this->assertNotContains($other, $ids);
        $this->assertNotContains($wrongyear, $ids);
    }

    public function test_one_percent_weight_is_preserved_on_resave(): void {
        global $DB, $SESSION, $USER;
        $this->resetAfterTest(true);
        // Ordinary editing is allowed only before the first recorded grade.
        $f = $this->fixture(null);
        $DB->set_field('local_obe_aktivitas_map', 'bobot_aktivitas', 1, ['id' => $f->mapping]);
        $SESSION->obe_id_tahun_akademik = $f->y;
        $oldget=$_GET;$oldpost=$_POST;
        try {
            $_GET = ['id' => $f->course->id, 'map_id' => $f->mapping];
            ob_start();
            try {
                global $CFG;
                include($CFG->dirroot . '/local/obe/views/dosen/aktivitas_nilai/form_pemetaan.php');
                $html = ob_get_contents();
            } finally {ob_end_clean();}
            $matched=preg_match('/name="bobot"[^>]*value="([^"]+)"/s', $html, $match);
            $this->assertSame(1,$matched,'Editable mapping must render the weight input');
            $this->assertEquals('1', $match[1]);
            $_GET=[];
            $_POST=['id'=>$f->mapping,'sub_cpmk_id'=>$f->nodes['SUB-CPMK'],'bobot'=>$match[1]];
            aktivitasnilai_controller::eksekusi_update($f->course->id);
            $this->assertEquals(1,$DB->get_field('local_obe_aktivitas_map','bobot_aktivitas',['id'=>$f->mapping],MUST_EXIST));
            // Grade only AFTER resave. Both report reads must retain 80 * 1% = 0.8.
            grade_update('mod/assign',$f->course->id,'mod','assign',$f->assign->id,0,
                ['userid'=>$f->student->id,'rawgrade'=>80],['itemname'=>$f->assign->name,'grademax'=>100,'grademin'=>0]);
            $before=capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($f->course->id,$f->nodes['SUB-CPMK'],'SUB-CPMK',$f->y);
            $this->assertEqualsWithDelta(0.8, $before->detail_mahasiswa[0]->nilai, 0.001);
            $after=capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($f->course->id,$f->nodes['SUB-CPMK'],'SUB-CPMK',$f->y);
            $this->assertEquals(0.8, $after->detail_mahasiswa[0]->nilai);
            // Once graded the same view must show protection, never an edit form.
            $_POST=[];$_GET=['id'=>$f->course->id,'map_id'=>$f->mapping];
            ob_start();
            try {include($CFG->dirroot . '/local/obe/views/dosen/aktivitas_nilai/form_pemetaan.php');$locked=ob_get_contents();}
            finally {ob_end_clean();}
            $this->assertStringContainsString('Aspek sudah memiliki nilai',$locked);
            $this->assertStringNotContainsString('name="bobot"',$locked);
        } finally {$_GET=$oldget;$_POST=$oldpost;}
    }

    public function test_parallel_enrolments_use_latest_course_consistently(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest(true);
        $f = $this->fixture(20);
        $DB->set_field('course', 'fullname', 'A First Course', ['id' => $f->course->id]);
        rebuild_course_cache($f->course->id, true);
        $c2 = $this->getDataGenerator()->create_course(['fullname' => 'Z Second Course']);
        $this->getDataGenerator()->enrol_user($f->student->id, $c2->id, 'student');
        $DB->insert_record('local_obe_relasi_kelas', (object)[
            'id_mata_kuliah' => $f->mk, 'id_kursus_moodle' => $c2->id,
            'id_angkatan' => $f->a, 'id_tahun_akademik' => $f->y]);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $c2->id, 'grade' => 100]);
        $DB->insert_record('local_obe_aktivitas_map', (object)[
            'cmid' => $assign->cmid, 'sub_cpmk_id' => $f->nodes['SUB-CPMK'], 'bobot_aktivitas' => 100]);
        grade_update('mod/assign', $c2->id, 'mod', 'assign', $assign->id, 0,
            ['userid' => $f->student->id, 'rawgrade' => 80], ['grademax' => 100, 'grademin' => 0]);
        $program = capaian_prodi_manager::ambil_detail_kompetensi_mahasiswa_prodi($f->p, $f->nodes['SUB-CPMK'], 'SUB-CPMK', $f->y);
        $student = capaian_mahasiswa_manager::ambil_detail_subcpmk($f->student->id, $f->nodes['SUB-CPMK'], $f->p, $f->y);
        $this->assertEquals(80, $program->detail_mahasiswa[0]->nilai);
        $this->assertEquals(80, $student->nilai);
    }

    public function test_class_export_uses_its_own_year(): void {
        global $DB, $SESSION;
        $this->resetAfterTest(true);
        $f = $this->fixture();
        $year2 = $DB->insert_record('local_obe_tahun_akademik', (object)['nama_tahun' => '2027/2028']);
        $SESSION->obe_id_tahun_akademik = $f->y;
        $this->assertCount(1, capaian_kelas_manager::ambil_data_export_kelas($f->course->id));
        $SESSION->obe_id_tahun_akademik = $year2;
        $this->assertCount(1, capaian_kelas_manager::ambil_data_export_kelas($f->course->id));
        $this->assertCount(1, capaian_kelas_manager::ambil_data_export_kelas($f->course->id, $f->y));
    }

    public function test_class_relation_update_rejects_duplicate_mapping(): void {
        global $DB;
        $this->resetAfterTest(true);
        $f = $this->fixture();
        $c2 = $this->getDataGenerator()->create_course();
        $id = $DB->insert_record('local_obe_relasi_kelas', (object)[
            'id_mata_kuliah' => $f->mk, 'id_kursus_moodle' => $c2->id,
            'id_angkatan' => $f->a, 'id_tahun_akademik' => $f->y]);
        $_POST = ['id' => $id, 'id_mata_kuliah' => $f->mk, 'id_angkatan' => $f->a,
            'id_kursus_moodle' => $f->course->id, 'id_tahun_akademik' => $f->y];
        $this->expectException(\moodle_exception::class);
        \local_obe\controller\relasikelas_controller::eksekusi_update();
        $this->assertEquals(2, $DB->count_records('local_obe_relasi_kelas', ['id_kursus_moodle' => $f->course->id]));
        $_POST = [];
    }

    public function test_legacy_threshold_action_has_explicit_migration_message(): void {
        $this->resetAfterTest(true); $f=$this->fixture();
        $_POST=['sesskey'=>sesskey()];
        $this->expectException(\moodle_exception::class);
        \local_obe\controller\kompetensi_controller::handle_update_ambang($f->nodes['CPL'],60,$f->p);
    }
    public function test_hidden_grade_is_rejected_for_student(): void {
        global $CFG;
        require_once($CFG->libdir.'/grade/grade_item.php');
        $this->resetAfterTest(true);$f=$this->fixture(87);
        $item=\grade_item::fetch(['courseid'=>$f->course->id,'itemtype'=>'mod','itemmodule'=>'assign','iteminstance'=>$f->assign->id]);
        $item->set_hidden(1);$this->setUser($f->student);
        $this->expectException(\moodle_exception::class);
        capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($f->course->id,$f->nodes['SUB-CPMK'],'SUB-CPMK',$f->y);
    }
    public function test_teacher_cannot_delete_another_courses_mapping(): void {
        $this->resetAfterTest(true);$f=$this->fixture();
        $course=$this->getDataGenerator()->create_course();$teacher=$this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id,$course->id,'editingteacher');$this->setUser($teacher);
        $this->expectException(\moodle_exception::class);
        aktivitasnilai_controller::eksekusi_hapus($f->mapping);
    }
    public function test_readonly_kaprodi_cannot_change_other_master(): void {
        global $DB;
        $this->resetAfterTest(true);$f=$this->fixture();$reader=$this->getDataGenerator()->create_user();
        $DB->insert_record('local_obe_prodi',(object)['kode_prodi'=>'OWN','nama_prodi'=>'Own','userid_kaprodi'=>$reader->id]);
        $role=create_role('Reader','reader','');assign_capability('local/obe:viewprogramreport',CAP_ALLOW,$role,context_system::instance());role_assign($role,$reader->id,context_system::instance());
        $this->setUser($reader);$_POST=['id'=>$f->mk,'id_prodi'=>$f->p,'kode_mk'=>'A01','nama_mk'=>'Changed','sks_mk'=>3];
        $this->expectException(\moodle_exception::class);matakuliah_controller::eksekusi_update();
    }
}
