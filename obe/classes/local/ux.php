<?php
namespace local_obe\local;
defined('MOODLE_INTERNAL') || die();

/** Presentation helpers. No curriculum, grade or permission mutations. */
final class ux {
    public static function weight(float $used): array {
        return ['used'=>$used,'remaining'=>max(0,100-$used),'complete'=>abs($used-100)<0.00001];
    }
    public static function course_links(int $courseid): string {
        $links=[['/course/view.php',['id'=>$courseid],'Kembali ke kursus'],['/grade/report/index.php',['id'=>$courseid],'Nilai di Moodle (Gradebook)'],
            ['/local/obe/views/dosen/dashboard.php',['id'=>$courseid,'halaman'=>'laporan'],'Capaian OBE kelas']];
        if (has_capability('local/obe:managecourse',\context_course::instance($courseid))) {$links[]=['/local/obe/views/dosen/dashboard.php',['id'=>$courseid,'halaman'=>'aktivitas_nilai'],'Pemetaan aktivitas'];}
        $out='<nav class="obe-quicklinks mb-3" aria-label="Navigasi kelas">';
        foreach ($links as [$route,$args,$label]) {
            if ($route==='/grade/report/index.php' && !has_capability('moodle/grade:viewall',\context_course::instance($courseid))) {continue;}$out.=\html_writer::link(new \moodle_url($route,$args),$label,['class'=>'btn btn-outline-secondary btn-sm mr-2 mb-2']);}
        return $out.'</nav>';
    }
    public static function grade_readiness(int $courseid,int $subid,int $userid): array {
        global $CFG;
        require_once($CFG->libdir.'/gradelib.php');$missing=[];
        foreach (\local_obe\manager\aktivitasnilai_manager::ambil_semua_pemetaan($courseid) as $activity) {
            if ((int)$activity->sub_cpmk_id!==$subid) {continue;}
            $grade=grade_get_grades($courseid,'mod',$activity->modulename,$activity->instance,[$userid]);
            $item=$grade->items?reset($grade->items):null;
            if (($item->grades[$userid]->grade??null)===null) {$missing[]=$activity->name;}
        }
        return ['complete'=>!$missing,'missing'=>$missing];
    }
    /** Scope the existing preference to a link/form so parallel tabs restore their own year. */
    public static function restore_year(): void {
        global $DB,$SESSION;
        $year=optional_param('obe_year',-1,PARAM_INT);
        if ($year<0) {return;}
        if ($year===0) {unset($SESSION->obe_id_tahun_akademik,$SESSION->obe_tahun_akademik);return;}
        $record=$DB->get_record('local_obe_tahun_akademik',['id'=>$year],'*',MUST_EXIST);
        $SESSION->obe_id_tahun_akademik=$record->id;$SESSION->obe_tahun_akademik=$record->nama_tahun;
    }
    public static function glossary(): string {
        return '<details class="mb-3"><summary>Arti CPL, CPMK, Sub-CPMK dan bobot</summary><dl class="mt-2"><dt>CPL</dt><dd>Kemampuan lulusan yang dituju program studi.</dd><dt>Indikator</dt><dd>Tanda terukur untuk menilai CPL.</dd><dt>CPMK</dt><dd>Kemampuan yang dituju dalam mata kuliah.</dd><dt>Sub-CPMK</dt><dd>Kemampuan terukur pada bagian pembelajaran.</dd><dt>Bobot aktivitas</dt><dd>Kontribusi nilai aktivitas terhadap Sub-CPMK.</dd><dt>Bobot kriteria</dt><dd>Kontribusi tiap kriteria rubrik terhadap nilai suatu aktivitas.</dd></dl></details>';
    }
}
