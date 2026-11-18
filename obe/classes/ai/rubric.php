<?php
namespace local_obe\ai;
defined('MOODLE_INTERNAL') || die();

/** Rubric aggregation produces an activity grade only; OBE status remains in legacy managers. */
final class rubric {
    public static function defaults(): array {
        return ['version'=>'stok-v1','criteria'=>[
            ['id'=>'kasusbatas','label'=>'Membedakan jumlah sama dengan stok dan melebihi stok','weight'=>0.6,'required'=>true],
            ['id'=>'assertion','label'=>'Menjelaskan assertion hasil dan exception','weight'=>0.4,'required'=>true],
        ]];
    }
    public static function parse(string $json): array {
        $r=json_decode($json,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($r) || empty($r['version']) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/',$r['version']) || empty($r['criteria']) || count($r['criteria'])>20) {throw new \invalid_parameter_exception('Rubrik tidak valid');}
        $sum=0; $ids=[];
        foreach ($r['criteria'] as $c) {
            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/',$c['id']??'') || isset($ids[$c['id']]) || empty($c['label']) || !is_numeric($c['weight']??null)) {throw new \invalid_parameter_exception('Kriteria tidak valid/ganda');}
            $w=(float)$c['weight']; if (!is_finite($w)||$w<0||$w>1) {throw new \invalid_parameter_exception('Bobot kriteria tidak valid');}
            $ids[$c['id']]=true; $sum+=$w;
        }
        if (abs($sum-1)>0.000001) {throw new \invalid_parameter_exception('Jumlah bobot kriteria harus 1');}
        return $r;
    }
    public static function total(array $rubric,array $scores): ?float {
        $total=0;
        foreach ($rubric['criteria'] as $c) {
            if (!isset($scores[$c['id']])) {if ($c['required']??true) {return null;} continue;}
            if (!is_numeric($scores[$c['id']])) {throw new \invalid_parameter_exception('Skor harus angka');}
            $s=(float)$scores[$c['id']]; if (!is_finite($s)||$s<0||$s>100) {throw new \invalid_parameter_exception('Skor harus 0–100');}
            $total+=$s*(float)$c['weight'];
        }
        $known=array_column($rubric['criteria'],'id');
        if (array_diff(array_keys($scores),$known)) {throw new \invalid_parameter_exception('Kriteria asing');}
        return round($total,5);
    }
}
