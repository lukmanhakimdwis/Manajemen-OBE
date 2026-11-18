<?php
namespace local_obe\ai;
defined('MOODLE_INTERNAL') || die();
final class drafts {
    public static function create(int $courseid,int $competencyid,string $context,bool $approved,string $kind='multichoice'): int {
        global $DB,$USER;
        scope::course($courseid,true,\context_course::instance($courseid)); require_capability('local/obe:useai',\context_course::instance($courseid));
        $c=scope::focus($courseid,$competencyid,0,\context_course::instance($courseid));
        if (!$approved||!trim($context)||strlen($context)>60000||!in_array($kind,['multichoice','essay','coding'],true)) {throw new \invalid_parameter_exception('Materi/RPS harus disahkan dan jenis soal harus valid');}
        $out=agent::run('draft',['competency'=>['id'=>$c->id,'type'=>$c->tipe,'name'=>$c->nama_kompetensi],
            'approved_context'=>$context,'kind'=>$kind]);
        $out['kind']=$kind; $out['contextsha256']=hash('sha256',$context); $out['competencyid']=$competencyid;
        $rubric=rubric::parse(json_encode($out['rubric'],JSON_THROW_ON_ERROR));
        if (empty($out['question'])||empty($out['solution'])) {throw new \invalid_parameter_exception('Draf tidak lengkap');}
        return (int)$DB->insert_record('local_obe_ai_draft',(object)['courseid'=>$courseid,'authorid'=>$USER->id,'competencyid'=>$competencyid,
            'contexttext'=>$context,'body'=>json_encode($out,JSON_THROW_ON_ERROR),'provider'=>$out['provider'],'model'=>$out['model'],
            'rubricversion'=>$rubric['version'],'status'=>'draft','questionid'=>0,'timecreated'=>time(),'timemodified'=>time()]);
    }
    public static function xml(array $d): string {
        $esc=static function($s) {return htmlspecialchars((string)$s,ENT_XML1|ENT_QUOTES,'UTF-8');};
        $kind=$d['kind'];
        if (!in_array($kind,['multichoice','essay'],true)) {throw new \invalid_parameter_exception('Soal coding memerlukan CodeRunner dan bukti tes referensi/mutan sebelum publikasi');}
        $xml='<?xml version="1.0" encoding="UTF-8"?><quiz><question type="'.$kind.'"><name><text>'.$esc($d['title']).'</text></name>';
        $xml.='<questiontext format="plain_text"><text>'.$esc($d['question']).'</text></questiontext><generalfeedback format="plain_text"><text>'.$esc($d['solution']).'</text></generalfeedback><defaultgrade>1</defaultgrade><penalty>0</penalty>';
        if ($kind==='multichoice') {
            $answers=$d['answers']??[];
            if (count($answers)<2||count(array_filter($answers,static function($a) {return $a['fraction']==100;}))!==1) {throw new \invalid_parameter_exception('Pilihan jawaban tidak valid');}
            $xml.='<single>true</single><shuffleanswers>true</shuffleanswers><answernumbering>abc</answernumbering>';
            foreach ($answers as $a) {
                if (!in_array($a['fraction'],[0,100],true)) {throw new \invalid_parameter_exception('Fraksi tidak valid');}
                $xml.='<answer fraction="'.$a['fraction'].'" format="plain_text"><text>'.$esc($a['text']).'</text><feedback format="plain_text"><text/></feedback></answer>';
            }
        } else {$xml.='<responseformat>editor</responseformat><responserequired>1</responserequired><responsefieldlines>10</responsefieldlines><attachments>0</attachments><attachmentsrequired>0</attachmentsrequired><graderinfo format="plain_text"><text>'.$esc(json_encode($d['rubric'],JSON_UNESCAPED_UNICODE)).'</text></graderinfo>';}
        return $xml.'</question></quiz>';
    }
    public static function publish(int $id): int {
        global $DB,$CFG,$USER;
        $d=$DB->get_record('local_obe_ai_draft',['id'=>$id],'*',MUST_EXIST);
        scope::course((int)$d->courseid,true,\context_course::instance($d->courseid)); $ctx=\context_course::instance($d->courseid);
        require_capability('local/obe:useai',$ctx); require_capability('moodle/question:add',$ctx);
        scope::focus((int)$d->courseid,(int)$d->competencyid,0,$ctx);
        $lock=\core\lock\lock_config::get_lock_factory('local_obe_ai')->get_lock('publish:'.$id,10);
        if (!$lock) {throw new \moodle_exception('locktimeout');}
        try {
            $d=$DB->get_record('local_obe_ai_draft',['id'=>$id],'*',MUST_EXIST);
            if ($d->status==='published') {return (int)$d->questionid;}
            if ($d->status==='expired') {throw new \invalid_parameter_exception('Draf melewati masa simpan');}
            require_once($CFG->libdir.'/questionlib.php'); require_once($CFG->dirroot.'/question/format/xml/format.php');
            $body=json_decode($d->body,true,64,JSON_THROW_ON_ERROR);
            $file=make_request_directory().'/obe-draft.xml'; file_put_contents($file,self::xml($body));
            $format=new \qformat_xml(); $format->displayprogress=false; $format->displayerrors=false;
            // Fresh courses may not have a Question Bank category yet.
            question_make_default_categories([$ctx]);
            $format->setCategory(question_get_default_category($ctx->id)); $format->setContexts([$ctx]);
            $format->setCourse(get_course($d->courseid)); $format->setFilename($file); $format->setRealfilename('obe-draft.xml');
            $format->setMatchgrades('error'); $format->setCatfromfile(false); $format->setContextfromfile(false); $format->setStoponerror(true);
            $tx=$DB->start_delegated_transaction();
            if (!$format->importpreprocess()||!$format->importprocess()||!$format->importpostprocess()||count($format->questionids)!==1) {throw new \moodle_exception('Impor soal gagal');}
            $d->questionid=(int)reset($format->questionids); $d->status='published'; $d->timemodified=time(); $DB->update_record('local_obe_ai_draft',$d);
            \local_obe\access_guard::audit('ai_publish','ai_draft',$id,['questionid'=>$d->questionid,'actor'=>$USER->id,'model'=>$d->model]);
            $tx->allow_commit(); return $d->questionid;
        } finally {$lock->release();}
    }
}
