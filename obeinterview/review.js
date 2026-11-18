/* Preview only. rubric::total and evidence::approve are authoritative on submit. */
(() => {
    'use strict';
    for (const form of document.querySelectorAll('[data-obe-score-form]')) {
        const inputs = [...form.querySelectorAll('.obe-score-input')];
        const output = form.querySelector('[data-obe-preview]');
        function preview() {
            let total = 0;
            for (const input of inputs) {
                if (input.value.trim() === '') {
                    if (input.dataset.required === '1') {output.textContent = 'Lengkapi skor wajib untuk melihat nilai';return;}
                    continue;
                }
                const score = Number(input.value), weight = Number(input.dataset.weight);
                if (!Number.isFinite(score) || score < 0 || score > 100 || !Number.isFinite(weight)) {
                    output.textContent = 'Periksa skor: gunakan angka 0–100';return;
                }
                total += score * weight;
            }
            output.textContent = new Intl.NumberFormat('id-ID', {maximumFractionDigits:5}).format(Math.round(total * 100000) / 100000);
        }
        for (const input of inputs) input.addEventListener('input', preview);
        preview();
    }
})();
