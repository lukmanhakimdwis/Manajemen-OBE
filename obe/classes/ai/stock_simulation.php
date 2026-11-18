<?php
namespace local_obe\ai;
defined('MOODLE_INTERNAL') || die();

/** Conservative fixture, not a semantic assessor. Only the two documented stock criteria. */
final class stock_simulation {
    public static function analyze(array $context): array {
        $scores=[];$quotes=[];$missing=[];
        foreach ($context['rubric']['criteria']??[] as $criterion) {
            $id=$criterion['id'];$label=strtolower($criterion['label']??'');$matches=[];
            $supported=($id==='kasusbatas'&&strpos($label,'stok')!==false)
                ||($id==='assertion'&&(strpos($label,'assert')!==false||strpos($label,'exception')!==false));
            foreach ($context['turns']??[] as $turn) {
                if (!$supported||$turn['speaker']!=='student') {continue;}
                $text=strtolower($turn['body']);
                $stock=strpos($text,'stok')!==false;
                $boundary=$stock&&strpos($text,'sama')!==false&&strpos($text,'melebihi')!==false;
                $result=strpos($text,'nol')!==false||preg_match('/\b0\b/',$text)||strpos($text,'tersisa')!==false;
                $assertion=$stock&&$result&&strpos($text,'assert')!==false&&strpos($text,'exception')!==false;
                if (($id==='kasusbatas'&&$boundary)||($id==='assertion'&&$assertion)) {
                    $matches[]=['criterion'=>$id,'turnid'=>(int)$turn['id'],'text'=>$turn['body']];
                }
            }
            $scores[$id]=$matches?60:null;$quotes=array_merge($quotes,$matches);
            if (!$matches) {$missing[]=($criterion['label']??$id).': '.(!$supported
                ?'Rubrik ini belum didukung simulasi stok; perlu pemeriksaan dosen.'
                :($id==='kasusbatas'?'Belum ada jawaban yang membedakan jumlah sama dengan stok dan melebihi stok.'
                :'Belum ada jawaban tentang stok hasil yang diharapkan, assertion dan exception.'));}
        }
        return ['provider'=>'simulation','model'=>'fixture-stok-v4','scores'=>$scores,'quotes'=>$quotes,
            'missing'=>$missing,'reason'=>'SIMULASI berbasis pola per kriteria; bukan penilaian semantik. Skor fixture 60 hanya usulan, wajib diperiksa dosen.'];
    }
}
