<?php
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot.'/course/moodleform_mod.php');
class mod_obeinterview_mod_form extends moodleform_mod {
    public function definition() {
        $course=$this->get_course();
        $context=$this->get_context();
        $m=$this->_form; $m->addElement('header','general',get_string('general','form'));
        $m->addElement('text','name',get_string('name','obeinterview'),['size'=>64]); $m->setType('name',PARAM_TEXT); $m->addRule('name',null,'required');
        $this->standard_intro_elements();
        try {$focusoptions=\local_obe\ai\scope::options((int)$course->id,$context);}
        catch (\dml_missing_record_exception $exception) {$focusoptions=[];}
        if (!$focusoptions) {$m->addElement('static','focusnotice','','Kompetensi belum tersedia. Minta pengelola menghubungkan kelas, prodi, tahun dan kerangka kompetensi lalu menyinkronkannya. Formulir belum dapat disimpan sampai kompetensi tersedia.');}
        $m->addElement('select','focusid',get_string('focusid','obeinterview'),$focusoptions?:[0=>'Kompetensi belum tersedia']);
        $m->addRule('focusid',null,'required');
        $rubric=\local_obe\ai\rubric::parse(($this->current->rubricjson??'')?:json_encode(\local_obe\ai\rubric::defaults()));
        $m->addElement('header','rubricsection','Rubrik penilaian');
        $m->addElement('static','rubrichelp','','Bobot kriteria berjumlah 100%. Baris kosong diabaikan. Kriteria yang sudah digunakan sesi tidak dapat diubah.');
        $repeat=[$m->createElement('text','criterionlabel','Nama kriteria {no}',['size'=>50]),
            $m->createElement('text','criterionweight','Bobot kriteria {no} (%)',['size'=>8]),
            $m->createElement('advcheckbox','criterionrequired','Skor kriteria {no} wajib diisi'),$m->createElement('hidden','criterionid','')];
        $this->repeat_elements($repeat,count($rubric['criteria']),['criterionlabel'=>['type'=>PARAM_TEXT],
            'criterionweight'=>['type'=>PARAM_RAW_TRIMMED],'criterionrequired'=>['type'=>PARAM_BOOL],'criterionid'=>['type'=>PARAM_ALPHANUMEXT]],
            'criterioncount','addcriterion',1,'Tambah kriteria');
        foreach (\local_obe\ai\form_adapter::rows($rubric) as $i=>$row) {
            foreach (['criterionlabel'=>'label','criterionweight'=>'weight','criterionrequired'=>'required','criterionid'=>'id'] as $field=>$key) {$m->setDefault($field.'['.$i.']',$row[$key]);}
        }
        $m->addElement('header','interviewsection','Pertanyaan dan durasi');
        $m->addElement('textarea','corequestion',get_string('corequestion','obeinterview'),['rows'=>2]); $m->setType('corequestion',PARAM_TEXT); $m->setDefault('corequestion','Kasus batas apa yang Anda uji?'); $m->addRule('corequestion',null,'required');
        foreach (['durationminutes'=>10,'maxfollow'=>2,'retentiondays'=>180] as $key=>$value) {
            $m->addElement('text',$key,$key==='durationminutes'?'Durasi wawancara (menit)':get_string($key,'obeinterview')); $m->setType($key,$key==='durationminutes'?PARAM_RAW_TRIMMED:PARAM_INT); $m->setDefault($key,$value);
        }
        $projects=[0=>'Tanpa proyek terkait'];
        foreach (get_fast_modinfo($course)->get_cms() as $project) {
            if ($project->uservisible && empty($project->deletioninprogress) && in_array($project->modname,['assign','lti'],true)) {$projects[$project->id]=$project->name;}
        }
        $m->addElement('select','projectcmid','Proyek terkait',$projects);$m->setType('projectcmid',PARAM_INT);
        $m->addElement('static','mappingnotice','','Setelah disimpan, petakan aktivitas ke Sub-CPMK dan atur bobotnya melalui Pemetaan Aktivitas. Fokus pertanyaan harus berada dalam cabang kompetensi Sub-CPMK tersebut.');
        $this->standard_coursemodule_elements(); $this->add_action_buttons();
    }
    public function data_preprocessing(&$defaults) {
        parent::data_preprocessing($defaults);
        $r=\local_obe\ai\rubric::parse(($defaults['rubricjson']??'')?:json_encode(\local_obe\ai\rubric::defaults()));
        foreach (\local_obe\ai\form_adapter::rows($r) as $i=>$row) {
            foreach (['criterionlabel'=>'label','criterionweight'=>'weight','criterionrequired'=>'required','criterionid'=>'id'] as $field=>$key) {$defaults[$field][$i]=$row[$key];}
        }
        $defaults['durationminutes']=($defaults['duration']??600)/60;
    }
    public function get_data() {
        $data=parent::get_data();
        if ($data) {
            $data->rubricjson=\local_obe\ai\form_adapter::rubric_json((array)$data,$this->current->rubricjson??'');
            $data->duration=(int)round($data->durationminutes*60);
        }
        return $data;
    }
    public function validation($data,$files) {
        $errors=parent::validation($data,$files);
        // Use the form's trusted course, never a submitted course/context identifier.
        $course=$this->get_course(); $context=$this->get_context();
        try {\local_obe\ai\scope::course((int)$course->id,true,$context);}
        catch (\dml_missing_record_exception $exception) {$errors['focusid']='Kelas atau kerangka kompetensi belum siap. Hubungi pengelola OBE.';return $errors;}
        try {
            global $DB;
            $map=$this->_cm?$DB->get_record('local_obe_aktivitas_map',['cmid'=>$this->_cm->id]):null;
            \local_obe\ai\scope::focus((int)$course->id,(int)$data['focusid'],$map?(int)$map->sub_cpmk_id:0,$context);
        }
        catch (\moodle_exception $e) {$errors['focusid']='Kompetensi tidak sah untuk kelas, prodi atau tahun akademik ini';}
        try {\local_obe\ai\form_adapter::rubric_json($data,$this->current->rubricjson??'');} catch (\Throwable $e) {$errors['criterionlabel[0]']='Periksa nama kriteria dan jumlah bobot: harus 100%, maksimum 20 kriteria. Gunakan titik untuk desimal, misalnya 12.5.';}
        foreach (['durationminutes'=>[1,60],'maxfollow'=>[0,5],'retentiondays'=>[1,3650]] as $key=>$range) {
            if (!is_numeric($data[$key])||!is_finite((float)$data[$key])||$data[$key]<$range[0]||$data[$key]>$range[1]) {$errors[$key]='Nilai di luar batas';}
        }
        return $errors;
    }
}
