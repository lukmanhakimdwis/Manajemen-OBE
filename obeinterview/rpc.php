<?php
require_once(__DIR__.'/../../config.php');
use local_obe\ai\interview;
if ($_SERVER['REQUEST_METHOD']!=='POST') {http_response_code(405); exit;}
require_sesskey(); $cmid=required_param('cmid',PARAM_INT); $action=required_param('action',PARAM_ALPHA);
// Authenticate at the HTTP boundary once, before any response is rendered/streamed.
$cm=get_coursemodule_from_id('obeinterview',$cmid,0,false,MUST_EXIST);
require_login(get_course($cm->course),false,$cm);
$grading=in_array($action,['reopen','proposal','review'],true);
interview::activity($cmid,$grading);
$stream=optional_param('stream',0,PARAM_BOOL) && $action==='answer';
header('Cache-Control: no-store');
try {
    if ($action==='start') {$sid=interview::start($cmid,required_param('consent',PARAM_BOOL),optional_param('snapshotid',0,PARAM_INT)); $result=interview::read($sid);}
    else {
        $sid=required_param('sid',PARAM_INT);
        $session=$DB->get_record('local_obe_ai_session',['id'=>$sid,'cmid'=>$cmid],'*',MUST_EXIST);
        interview::session($sid,$grading); // Ownership checked before releasing the PHP session lock.
        \core\session\manager::write_close();
        switch ($action) {
            case 'read': $result=interview::read($sid); break;
            case 'answer': $result=interview::answer($sid,required_param('requestid',PARAM_ALPHANUMEXT),required_param('text',PARAM_RAW),required_param('questionkey',PARAM_ALPHANUMEXT)); break;
            case 'close': interview::close($sid); $result=interview::read($sid); break;
            case 'reopen': interview::reopen($sid,required_param('reason',PARAM_TEXT)); $result=interview::read($sid,true); break;
            case 'proposal': $result=['evidenceid'=>interview::proposal($sid)]; break;
            case 'review': $result=interview::read($sid,true); break;
            default: throw new invalid_parameter_exception('Aksi tidak tersedia');
        }
    }
    if ($stream) {
        header('Content-Type: text/event-stream; charset=utf-8'); header('X-Accel-Buffering: no');
        $last=$result['turns']?end($result['turns']):null;
        if ($last && $last->speaker==='assistant') {
            foreach (array_chunk(preg_split('//u',$last->body,-1,PREG_SPLIT_NO_EMPTY),16) as $letters) {
                $piece=implode('',$letters);
                // Fixture chunk transport. No claim that these are live Codex token events.
                echo 'event: delta' . "\n" . 'data: ' . json_encode(['text'=>$piece,'provider'=>'simulation'],JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
                if (ob_get_level()) {@ob_flush();} flush(); usleep(15000);
            }
        }
        echo "event: complete\ndata: ".json_encode(['result'=>$result],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
    } else {header('Content-Type: application/json; charset=utf-8'); echo json_encode(['result'=>$result],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
} catch (\Throwable $e) {
    http_response_code(400); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error'=>($action==='close'?'Pengakhiran belum terkonfirmasi. Klik Lanjutkan untuk memeriksa status server, lalu coba Akhiri wawancara lagi bila masih aktif.':'Permintaan belum dapat diselesaikan. Klik Lanjutkan untuk memuat giliran terakhir dan mencoba respons tertunda. Bila halaman lama masih terbuka, muat ulang halaman.')]);
}
