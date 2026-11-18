<?php
namespace local_obe\ai;
defined('MOODLE_INTERNAL') || die();

/** Convert human-readable percent rows to the existing versioned rubric format. */
final class form_adapter {
    public static function rubric_json(array $data,string $original=''): string {
        $old=$original?rubric::parse($original):rubric::defaults();$criteria=[];$usedids=array_column($old['criteria'],'id');
        $labels=$data['criterionlabel']??[];
        if (!is_array($labels)||count($labels)>20) {throw new \invalid_parameter_exception('Gunakan 1 sampai 20 kriteria.');}
        foreach ($labels as $i=>$label) {
            $label=trim((string)$label);$weight=$data['criterionweight'][$i]??'';
            if ($label===''&&($weight===''||$weight===null)) {continue;}
            if ($label===''||!is_numeric($weight)||!is_finite((float)$weight)||(float)$weight<0||(float)$weight>100) {
                throw new \invalid_parameter_exception('Isi nama kriteria dan bobot 0–100%. Jumlah bobot harus 100%.');
            }
            $id=(string)($data['criterionid'][$i]??'');
            if ($id==='') {$id='k'.($i+1);while (in_array($id,$usedids,true)) {$id.='_new';}$usedids[]=$id;}
            // Preserve existing per-criterion metadata and identifiers when editing an unchanged rubric.
            $criterion=[];foreach ($old['criteria'] as $existing) {if ($existing['id']===$id) {$criterion=$existing;break;}}
            $criteria[]=array_replace($criterion,['id'=>$id,'label'=>$label,'weight'=>(float)$weight/100,'required'=>!empty($data['criterionrequired'][$i])]);
        }
        $next=$old;$next['criteria']=$criteria;
        $json=json_encode($next,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);rubric::parse($json);
        // Avoid false changes to immutable session configuration from JSON whitespace/order/number formats.
        $normalold=$old;foreach ($normalold['criteria'] as &$c) {$c['required']=$c['required']??true;}unset($c);
        if ($original && $next==$normalold) {return $original;}
        $next['version']=substr($old['version'],0,50).'-'.substr(hash('sha256',json_encode($criteria,JSON_THROW_ON_ERROR)),0,12);
        return json_encode($next,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    }
    public static function rows(array $rubric): array {
        $rows=[];foreach ($rubric['criteria'] as $i=>$c) {$rows[]=['id'=>$c['id'],'label'=>$c['label'],'weight'=>$c['weight']*100,'required'=>$c['required']??true];}
        return $rows;
    }
    public static function request_rows(): array {
        $rows=[];foreach (['criterionlabel'=>PARAM_TEXT,'criterionweight'=>PARAM_RAW_TRIMMED,'criterionrequired'=>PARAM_BOOL,'criterionid'=>PARAM_ALPHANUMEXT] as $key=>$type) {$rows[$key]=optional_param_array($key,[],$type);}
        return $rows;
    }
    public static function scores(array $rubric,array $input): array {
        $scores=[];
        if (array_diff(array_keys($input),array_column($rubric['criteria'],'id'))) {throw new \invalid_parameter_exception('Kriteria tidak dikenal; muat ulang usulan.');}
        foreach ($rubric['criteria'] as $criterion) {$raw=$input[$criterion['id']]??null;$scores[$criterion['id']]=($raw===''||$raw===null)?null:$raw;}
        if (rubric::total($rubric,$scores)===null) {throw new \invalid_parameter_exception('Lengkapi seluruh skor wajib antara 0 dan 100.');}
        return $scores;
    }
    public static function editor(string $prefix,string $original,array $submitted=[]): string {
        $rubric=rubric::parse($original);$rows=self::rows($rubric);$count=20;
        $out='<fieldset class="obe-rubric-editor"><legend class="h5">Rubrik penilaian</legend><p>Isi nama dan bobot kriteria. Jumlah bobot harus 100%. Baris kosong tidak digunakan.</p>';
        for ($i=0;$i<$count;$i++) {
            if ($i===count($rows)) {$out.='<details><summary>Tambah kriteria (maksimum 20)</summary>'; }
            $row=$rows[$i]??['id'=>'','label'=>'','weight'=>'','required'=>true];
            $label=$submitted['criterionlabel'][$i]??$row['label'];$weight=$submitted['criterionweight'][$i]??$row['weight'];
            $required=$submitted? !empty($submitted['criterionrequired'][$i]):$row['required'];
            $key=$prefix.'-'.$i;
            $out.='<div class="obe-rubric-row"><input type="hidden" name="criterionid['.$i.']" value="'.s($submitted['criterionid'][$i]??$row['id']).'">';
            $out.='<label for="'.$key.'-label">Kriteria '.($i+1).'</label><input class="form-control" id="'.$key.'-label" name="criterionlabel['.$i.']" value="'.s($label).'">';
            $out.='<label for="'.$key.'-weight">Bobot kriteria (%)</label><input class="form-control" type="number" min="0" max="100" step="any" id="'.$key.'-weight" name="criterionweight['.$i.']" value="'.s($weight).'">';
            $out.='<label><input type="checkbox" name="criterionrequired['.$i.']" value="1" '.($required?'checked':'').'> Skor wajib diisi</label></div>';
        }
        return $out.(count($rows)<20?'</details>':'').'</fieldset>';
    }
}
