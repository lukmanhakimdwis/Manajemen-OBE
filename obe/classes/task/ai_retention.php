<?php
namespace local_obe\task;
defined('MOODLE_INTERNAL') || die();

/** Expire sensitive payloads without changing academic maps, grades, scales or historical numeric decisions. */
final class ai_retention extends \core\task\scheduled_task {
    public function get_name() {return 'OBE AI · Retensi isi percakapan dan bukti';}
    public function execute() {
        global $DB;
        $now=time();
        $sessions=$DB->get_recordset_select('local_obe_ai_session','status <> :expired',['expired'=>'expired']);
        foreach ($sessions as $s) {
            $config=json_decode($s->configjson,true);
            $days=(int)($config['retentiondays']??180);
            // Active extensions have the full retention interval after their last configured deadline.
            if (max($s->timecreated,$s->deadline)+$days*DAYSECS >= $now) {continue;}
            $factory=\core\lock\lock_config::get_lock_factory('local_obe_ai'); $lock=$factory->get_lock('session:'.$s->id,1);
            if (!$lock) {continue;}
            try {
                $s=$DB->get_record('local_obe_ai_session',['id'=>$s->id],'*',MUST_EXIST);
                $config=json_decode($s->configjson,true);
                if (max($s->timecreated,$s->deadline)+(int)$config['retentiondays']*DAYSECS >= $now || $s->status==='expired') {continue;}
                $tx=$DB->start_delegated_transaction();
                $DB->set_field('local_obe_ai_turn','body','[Isi dihapus sesuai masa simpan]',['sessionid'=>$s->id]);
                foreach ($DB->get_records('local_obe_ai_evidence',['sessionid'=>$s->id]) as $e) {
                    $e->body=json_encode(['expired'=>true,'rubricversion'=>$e->rubricversion,'score_retained'=>$e->state==='approved']);
                    if ($e->state!=='approved') {$e->state='expired';}
                    $DB->update_record('local_obe_ai_evidence',$e);
                    foreach ($DB->get_records('local_obe_ai_decision',['evidenceid'=>$e->id]) as $d) {
                        $b=json_decode($d->body,true); unset($b['reason']); $b['reason']='[Alasan dihapus sesuai masa simpan]'; $d->body=json_encode($b); $DB->update_record('local_obe_ai_decision',$d);
                    }
                }
                unset($config['reopenhistory']);
                $DB->set_field('local_obe_ai_session','configjson',json_encode($config),['id'=>$s->id]);
                $DB->set_field('local_obe_ai_session','status','expired',['id'=>$s->id]);
                $tx->allow_commit();
            } finally {$lock->release();}
        }
        $sessions->close();
        // Non-session evidence and drafts have a separately configured fixed campus retention interval.
        $days=(int)(get_config('local_obe','aievidencedays')?:180);
        if ($days<1||$days>3650) {return;}
        $cutoff=$now-$days*DAYSECS;
        $evidence=$DB->get_recordset_select('local_obe_ai_evidence','sessionid=0 AND timemodified < :cutoff',['cutoff'=>$cutoff]);
        foreach ($evidence as $e) {
            $e->body=json_encode(['expired'=>true,'score_retained'=>$e->state==='approved']);
            if ($e->state!=='approved') {$e->state='expired';}
            $DB->update_record('local_obe_ai_evidence',$e);
            foreach ($DB->get_records('local_obe_ai_decision',['evidenceid'=>$e->id]) as $d) {
                $b=json_decode($d->body,true); $b['reason']='[Alasan dihapus sesuai masa simpan]'; $d->body=json_encode($b); $DB->update_record('local_obe_ai_decision',$d);
            }
        }
        $evidence->close();
        $drafts=$DB->get_recordset_select('local_obe_ai_draft','timemodified < :cutoff',['cutoff'=>$cutoff]);
        foreach ($drafts as $d) {$d->contexttext='[Dihapus sesuai masa simpan]'; $d->body='{"expired":true}'; if ($d->status!=='published') {$d->status='expired';} $DB->update_record('local_obe_ai_draft',$d);}
        $drafts->close();
    }
}
