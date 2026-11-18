<?php
namespace local_obe\ai;
defined('MOODLE_INTERNAL') || die();

/** Optional backend worker bridge; no browser credential handling or inherited host login. */
final class agent {
    public static function run(string $action,array $context): array {
        global $USER;
        $request=['action'=>$action,'teacherid'=>(int)($context['ownerid']??$USER->id),'callerid'=>(int)$USER->id,'context'=>$context];
        $root=get_config('local_obe','aiworkerroot');
        if ($root) {
            $real=realpath($root); $entry=$real ? $real.'/worker/moodle_provider.py' : '';
            if (!$entry || !is_file($entry)) {throw new \invalid_parameter_exception('Worker belum dipasang');}
            // argv array bypasses shell; untrusted submission text is carried only over stdin.
            $pipes=[];
            $proc=proc_open(['/usr/bin/python3','-m','worker.moodle_provider'],[
                0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes,$real,
                ['PATH'=>'/usr/local/bin:/usr/bin:/bin','LANG'=>'C.UTF-8']);
            if (!is_resource($proc)) {throw new \moodle_exception('Worker tidak dapat dimulai');}
            fwrite($pipes[0],json_encode($request,JSON_THROW_ON_ERROR)); fclose($pipes[0]);
            stream_set_blocking($pipes[1],false); $raw=''; $deadline=microtime(true)+25;
            do {
                $raw.=stream_get_contents($pipes[1]);
                if (strlen($raw)>262144) {proc_terminate($proc,9); break;}
                $state=proc_get_status($proc); if (!$state['running']) {break;}
                usleep(10000);
            } while (microtime(true)<$deadline);
            if ($state['running']) {proc_terminate($proc,9);}
            $raw.=stream_get_contents($pipes[1]); fclose($pipes[1]); proc_close($proc);
            $out=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            if (!is_array($out)||!isset($out['provider'],$out['model'])) {throw new \invalid_parameter_exception('Output worker tidak valid');}
            return $out;
        }
        return self::simulate($action,$context);
    }
    public static function simulate(string $action,array $c): array {
        $meta=['provider'=>'simulation','model'=>'fixture-stok-v3'];
        if ($action==='draft') {
            return $meta+['kind'=>'multichoice','title'=>'Kasus batas pengurangan stok',
                'question'=>($c['kind']??'')==='coding'?'Implementasikan Stock::reduce(int $stock, int $quantity) dan tes PHPUnit: jumlah sama dengan stok menghasilkan nol; jumlah melebihi stok ditolak tanpa perubahan stok.':(($c['kind']??'')==='essay'?'Jelaskan kasus batas pengurangan stok, assertion hasil dan exception untuk jumlah melebihi stok.':'Stok awal 5. Pasangan pengujian mana yang membedakan pembelian tepat batas dan melewati batas?'),
                'answers'=>[['text'=>'Beli 5 dan beli 6','fraction'=>100],['text'=>'Beli 1 dan beli 2','fraction'=>0]],
                'solution'=>'Pembelian 5 menghasilkan stok 0; pembelian 6 ditolak tanpa perubahan stok.',
                'reference_php'=>'if ($quantity <= 0 || $quantity > $stock) { throw new InvalidArgumentException(); } return $stock - $quantity;',
                'mutants'=>['Ganti > dengan >=: harus gagal pada quantity == stock','Hapus guard > stock: harus gagal pada quantity > stock'],
                'tests_executed'=>false,'rubric'=>rubric::defaults(),'tests'=>[['stock'=>5,'quantity'=>5,'expected'=>0],['stock'=>5,'quantity'=>6,'expected'=>'InvalidArgumentException']],
                'notice'=>'Draf fixture SIMULASI; bukan hasil membaca RPS oleh model.'];
        }
        if ($action==='followup') {
            $text=(string)($c['answer']??'');
            $boundary='Bagaimana Anda membedakan pembelian dengan jumlah sama dengan stok dan pembelian dengan jumlah melebihi stok?';
            $assertion='Apa assertion untuk stok tersisa dan exception ketika jumlah pembelian melebihi stok?';
            $asked=array_map('trim',$c['asked_questions']??[]);
            $candidates=stripos($text,'stok habis')!==false?[$boundary,$assertion]:[$assertion,$boundary];
            foreach ($candidates as $question) {
                if (!in_array($question,$asked,true)) {return $meta+['text'=>$question,'complete'=>false];}
            }
            return $meta+['text'=>'','complete'=>true];
        }
        if ($action==='analyze') {
            return stock_simulation::analyze($c);
        }
        throw new \invalid_parameter_exception('Aksi agen tidak tersedia');
    }
}
