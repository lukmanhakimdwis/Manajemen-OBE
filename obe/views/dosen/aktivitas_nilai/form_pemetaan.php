<?php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\aktivitasnilai_manager;
use local_obe\manager\kompetensi_manager;

global $SESSION, $DB, $OUTPUT;

// 1. Ambil parameter dari URL/Request
$courseid = required_param('id', PARAM_INT);
$failed=$SESSION->obe_mapping_form[$courseid]??null;unset($SESSION->obe_mapping_form[$courseid]); 
$map_id   = optional_param('map_id', 0, PARAM_INT);
$cmid     = optional_param('cmid', 0, PARAM_INT);

// 2. Logika Penentuan Tahun Akademik Aktif Sesi
$tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;

if ($tahun_akademik == 0) {
    $tahun_akademik = aktivitasnilai_manager::ambil_tahun_id_dari_course($courseid);
}

// 3. Ambil data utama untuk Form
$data = ($map_id > 0) ? aktivitasnilai_manager::ambil_satu_pemetaan($map_id) : null;

if ($data) { get_coursemodule_from_id('', $data->cmid, $courseid, false, MUST_EXIST); }
if ($data&&\local_obe\local\assessment_integrity::used((int)$data->cmid)) {
    echo $OUTPUT->notification('Aspek sudah memiliki nilai (termasuk 0) atau riwayat. Bobot dan target tidak dapat diubah melalui penyuntingan biasa. Gunakan penggantian aspek untuk melihat dampak dan menyimpan riwayat.','warning');
    echo html_writer::link(new moodle_url('/local/obe/views/dosen/aktivitas_nilai/replace.php',['courseid'=>$courseid,'id'=>$data->id]),'Tinjau penggantian aspek',['class'=>'btn btn-primary']);return;
}


// Ambil daftar Sub-CPMK berdasarkan tahun akademik yang sudah divalidasi
$daftar_sub_cpmk = kompetensi_manager::ambil_semua_sub_cpmk_berdasarkan_tahun($tahun_akademik, $courseid);

// =========================================================================
// CRITICAL OPTIMIZATION: Ekstraksi Data Pohon Hierarki Kurikulum Pendukung
// =========================================================================
$hierarki = [];
if (!empty($daftar_sub_cpmk)) {
    foreach ($daftar_sub_cpmk as $sc) {
        // Asumsi method 'ambil_hierarki_subcpmk' mengembalikan objek relasi hulu ke hilir
        $h = kompetensi_manager::ambil_hierarki_subcpmk($sc->id);
        
        $hierarki[$sc->id] = [
            'cpl'       => $h->cpl->nama_kompetensi ?? '-',
            'indikator' => $h->indikator->nama_kompetensi ?? '-',
            'cpmk'      => $h->cpmk->nama_kompetensi ?? '-',
            'subcpmk'   => $h->subcpmk->nama_kompetensi ?? '-'
        ];
    }
}

// Ambil data penggunaan bobot untuk validasi JavaScript
$usage_data = aktivitasnilai_manager::get_total_bobot_usage($courseid, $map_id);
$form_url = new moodle_url('/local/obe/views/dosen/aktivitas_nilai/proses_aktivitasnilai.php');

// 4. Normalisasi tampilan bobot (Desimal ke Persen)
$nilai_bobot = 0;

if ($data) {
    $nilai_bobot = $data->bobot_aktivitas;

    // Hilangkan desimal jika nilainya bulat
    if (floor($nilai_bobot) == $nilai_bobot) {
        $nilai_bobot = (int)$nilai_bobot;
    }
}
?>

<div class="card shadow-sm border-0 mt-3">
    <div class="card-header bg-primary text-white font-weight-bold py-3 shadow-sm">
    <i class="fa fa-<?= $map_id ? 'edit' : 'plus-circle' ?> mr-1"></i> <?= $map_id ? 'Edit Pemetaan Aktivitas' : 'Tambah Pemetaan Aktivitas' ?>
</div>
    <div class="card-body p-4">
        <?php $selectedcm=get_coursemodule_from_id('',$data?$data->cmid:$cmid,$courseid,false,MUST_EXIST); ?><p><strong>Aktivitas:</strong> <?= s(get_fast_modinfo($courseid)->get_cm($selectedcm->id)->name) ?></p><p class="text-muted">Perubahan pemetaan memengaruhi laporan OBE. Jika wawancara sudah memiliki sesi, perubahan target perlu ditinjau terhadap bukti historis.</p>
        <form action="<?= $form_url->out() ?>" method="post" id="form-pemetaan">
            <input type="hidden" name="courseid" value="<?= $courseid ?>">
            <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
            <input type="hidden" name="aksi" value="<?= $map_id ? 'update' : 'simpan' ?>">
            <input type="hidden" name="id" value="<?= $map_id ?>"> 
            <input type="hidden" name="cmid" value="<?= $data ? $data->cmid : $cmid ?>">

            <div class="form-group mb-3">
                <label for="sub_cpmk_id" class="font-weight-bold">Pilih Sub-CPMK <span class="text-danger">*</span></label>
                <select name="sub_cpmk_id" id="sub_cpmk_id" class="form-control custom-select" required>
                    <option value="0">-- Pilih Sub-CPMK --</option>
                    <?php if (!empty($daftar_sub_cpmk)): ?>
                        <?php foreach ($daftar_sub_cpmk as $sc): ?>
                            <option value="<?= $sc->id ?>" <?= (($failed?($failed['sub_cpmk_id']==$sc->id):($data && $data->sub_cpmk_id == $sc->id))) ? 'selected' : '' ?>>
                                <?= s($sc->nama_kompetensi) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="0" disabled>Data Sub-CPMK tidak ditemukan untuk tahun akademik ini.</option>
                    <?php endif; ?>
                </select>
                
                <div id="hierarki-subcpmk" class="alert alert-info mt-3 shadow-sm border-0 small" style="display:none; line-height: 1.6; border-radius: 8px;">
                </div>
                
                <div id="info-sisa-bobot" role="status" aria-live="polite" class="mt-2 small font-weight-bold"></div>
            </div>
            
            <div class="form-group mb-4">
                <label for="bobot" class="font-weight-bold">Bobot aktivitas terhadap Sub-CPMK (0 - 100%) <span class="text-danger">*</span></label>
                <div class="input-group" style="max-width: 300px;">
                    <input type="number" step="0.01" min="0" max="100" name="bobot" id="bobot" class="form-control text-center font-weight-bold" 
                           value="<?= s($failed?$failed['bobot']:$nilai_bobot) ?>" placeholder="Contoh: 25.5" required>
                    <div class="input-group-append">
                        <span class="input-group-text font-weight-bold">%</span>
                    </div>
                </div>
            </div>

            <div class="mt-4 pt-3 border-top d-flex justify-content-start">
                <button type="submit" class="btn btn-primary px-4 font-weight-bold shadow-sm mr-2">
                    <i class="fa fa-save mr-1"></i> Simpan pemetaan
                </button>
                <a href="?id=<?= $courseid ?>&halaman=aktivitas_nilai" class="btn btn-light px-4 border">Batal</a>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const usageData = <?= json_encode($usage_data) ?>;
    const hierarkiData = <?= json_encode($hierarki, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const select = document.getElementById('sub_cpmk_id');
    const inputBobot = document.getElementById('bobot');
    const infoBox = document.getElementById('info-sisa-bobot');
    const hierarkiBox = document.getElementById('hierarki-subcpmk');
    const form = document.getElementById('form-pemetaan');

    // Format persen (hilangkan .00 jika bilangan bulat)
    function formatPersen(nilai) {
        nilai = parseFloat(nilai);
        return Number.isInteger(nilai) ? nilai : parseFloat(nilai.toFixed(2));
    }

    // Validasi Bobot Sisa Aktivitas Moodle
    function checkBobot() {
        const subId = select.value;
        const currentBobot = parseFloat(inputBobot.value) || 0;

        if (subId == 0 || subId == "") {
            infoBox.innerHTML = '';
            return true;
        }

        const used = parseFloat(usageData[subId] || 0);
        const usedPercent = used;
        const sisa = 100 - usedPercent;

        if (currentBobot > (sisa + 0.000001)) {
            infoBox.innerHTML = '<span class="text-danger"><i class="fa fa-exclamation-circle mr-1"></i>Melebihi kapasitas! Sisa tersedia untuk Sub-CPMK ini: ' + formatPersen(sisa) + '%</span>';
            return false;
        } else {
            infoBox.innerHTML = '<span class="text-success"><i class="fa fa-check-circle mr-1"></i>Sisa bobot tersedia: ' + formatPersen(sisa) + '%</span>';
            return true;
        }
    }

    // Escape HTML (Anti-XSS Payload Guard)
    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function(chr) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[chr];
        });
    }

    // Render Struktur Hierarki OBE (High Contrast Text)
    function tampilkanHierarki() {
        const subId = select.value;

        if (!hierarkiData || !hierarkiData[subId]) {
            hierarkiBox.style.display = 'none';
            hierarkiBox.innerHTML = '';
            return;
        }

        const h = hierarkiData[subId];
        hierarkiBox.style.display = 'block';
        hierarkiBox.innerHTML = `
            <div class="font-weight-bold border-bottom pb-1 mb-2 text-dark" style="color: #000000 !important;">
                <i class="fa fa-sitemap mr-1"></i> Struktur Kurikulum OBE:
            </div>
            <table class="table table-sm table-borderless m-0 text-dark small">
                <tr>
                    <td width="100px" class="p-0 font-weight-bold" style="color: #000000 !important;">CPL</td>
                    <td class="p-0" style="color: #212529 !important;">: ${escapeHtml(h.cpl)}</td>
                </tr>
                <tr>
                    <td class="p-0 font-weight-bold" style="color: #000000 !important;">Indikator</td>
                    <td class="p-0" style="color: #212529 !important;">: ${escapeHtml(h.indikator)}</td>
                </tr>
                <tr>
                    <td class="p-0 font-weight-bold" style="color: #000000 !important;">CPMK</td>
                    <td class="p-0" style="color: #212529 !important;">: ${escapeHtml(h.cpmk)}</td>
                </tr>
                <tr>
                    <td class="p-0 font-weight-bold" style="color: #000000 !important;">Sub-CPMK</td>
                    <td class="p-0">: <span class="text-primary font-weight-bold">${escapeHtml(h.subcpmk)}</span></td>
                </tr>
            </table>`;
    }

    // Event Listeners
    select.addEventListener('change', function () {
        tampilkanHierarki();
        checkBobot();
    });

    inputBobot.addEventListener('input', checkBobot);

    // Form Intercept Guard
    form.addEventListener('submit', function(e) {
        if (select.value == 0 || select.value == "") {
            e.preventDefault();
            alert('Gagal memproses: Silakan pilih Sub-CPMK terlebih dahulu!');
            return;
        }
        if (!checkBobot()) {
            e.preventDefault();
            alert('Gagal menyimpan: Akumulasi total bobot aktivitas untuk Sub-CPMK ini tidak boleh melebihi jatah batas 100%!');
        }
    });

    // Initialize state
    tampilkanHierarki();
    checkBobot();
});
</script>