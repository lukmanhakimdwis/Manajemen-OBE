/* Server-owned session state; durable IDs identify a question submission, never its text. */
(() => {
    'use strict';
    const el = id => document.getElementById(id);
    const c = JSON.parse(el('obe-config').textContent);
    const base = `obe-interview-${c.userid}-${c.cmid}`;
    let sid = Number(c.sid), data = null, busy = false, deadline = 0, timer = null, expiryNeeded = false;
    const pendingKey = () => `${base}-${sid}-pending`;
    const getPending = () => {
        try { return JSON.parse(sessionStorage.getItem(pendingKey()) || 'null'); } catch (_) { return null; }
    };
    const putPending = p => sessionStorage.setItem(pendingKey(), JSON.stringify(p));
    const clearPending = () => sessionStorage.removeItem(pendingKey());
    const active = () => data && data.session.status === 'active' && deadline > Date.now();
    function controls() {
        const pending = getPending();
        const canAnswer = active() && data.cananswer && !data.pending && !pending;
        el('setup').hidden = Boolean(sid);
        el('composer').hidden = !canAnswer;
        el('answer').disabled = busy || !canAnswer;
        el('send').disabled = busy || !canAnswer;
        if(el('obe-refresh-result'))el('obe-refresh-result').disabled=busy||!sid;
        el('start').disabled = busy || !el('consent').checked || (c.projectrequired && !Number(el('snapshotid').value));
        el('end').disabled = busy || !active();
        el('resume').disabled = busy || !sid;
        el('resume').hidden = !sid;
        el('resume').textContent = active() && (data.pending || pending) ? 'Lanjutkan respons tertunda' : 'Muat ulang status / transkrip';
        el('obe-chat').setAttribute('aria-busy', busy ? 'true' : 'false');
    }
    function stopTimer() { if (timer !== null) clearInterval(timer); timer = null; }
    function statusText() {
        if (!data) return 'Centang persetujuan, lalu mulai wawancara.';
        if (data.session.status === 'expired') return 'Masa simpan sesi telah berakhir. Hubungi dosen.';
        if (data.session.status !== 'active') {
            const why = data.session.status === 'closed' ? 'Wawancara telah diakhiri.' :
                data.session.status === 'timedout' ? 'Durasi wawancara berakhir.' : 'Batas pertanyaan tercapai atau pertanyaan simulasi telah habis.';
            return `${why} Transkrip tersimpan. Status penilaian terbaru ditampilkan pada Hasil penilaian saya.`;
        }
        if (data.pending || getPending()) return 'Jawaban tersimpan di server atau perangkat ini. Klik Lanjutkan respons tertunda untuk memeriksa dan melanjutkan dari giliran terakhir.';
        return 'Sesi aktif. Jawab pertanyaan AI terakhir.';
    }
    function tick() {
        const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        el('remaining').textContent = `${remaining} detik`;
        if (!remaining && data && data.session.status === 'active') {
            stopTimer(); controls();
            el('status').textContent = 'Durasi berakhir. Sedang memeriksa status penyimpanan di server…';
            if (busy) expiryNeeded = true;
            else void act('Memeriksa akhir durasi…', async () => render(await rpc('read', {sid})));
        }
    }
    function render(next) {
        if (next.assessment) {
            const result=next.assessment;
            const status=el('obe-assessment-status'), grade=el('obe-assessment-grade'), note=el('obe-assessment-note');
            if(status)status.textContent=result.status;
            if(grade)grade.textContent=result.hasgrade?`Nilai yang berlaku: ${result.grade}/100`:result.message;
            if(note)note.textContent=result.newproposal;
        }
        data = next; sid = Number(next.session.id); deadline = Date.now() + Number(next.remaining) * 1000;
        const turns = next.turns;
        const pending = getPending();
        let restoredDraft = false;
        if (next.pending) {
            const last = turns.filter(t => t.speaker === 'student').slice(-1)[0];
            if (last) putPending({requestid:last.requestid, text:last.body, questionkey:last.questionkey});
        } else if (pending && (next.session.status !== 'active' || turns.some(t => t.speaker === 'student' &&
            (t.requestid === pending.requestid || t.questionkey === pending.questionkey)))) {
            clearPending(); el('answer').value = '';
        } else if (pending && !pending.questionkey && next.cananswer) {
            // An unconfirmed draft from the old client has no question identity: require review, not auto-replay.
            clearPending(); el('answer').value = pending.text || ''; restoredDraft = true;
        }
        const container = el('turns'); container.replaceChildren();
        const seen = new Set();
        for (const t of turns) {
            if (seen.has(String(t.id))) continue;
            seen.add(String(t.id));
            const item = document.createElement('li');
            item.className = `obe-message ${t.speaker === 'student' ? 'obe-message-student' : 'obe-message-ai'}`;
            item.dataset.turnid = t.id;
            const label = document.createElement('strong'); label.className = 'obe-message-label';
            label.textContent = t.speaker === 'student' ? 'Anda' : 'AI simulasi';
            const text = document.createElement('div'); text.className = 'obe-message-body'; text.textContent = t.body;
            item.append(label, text); container.append(item);
        }
        el('stream').textContent = ''; el('stream').hidden = true;
        el('status').textContent = restoredDraft ? 'Draf lama dipulihkan. Periksa pertanyaan aktif sebelum mengirim jawaban.' : statusText();
        stopTimer(); controls();
        el('remaining').textContent = next.session.status === 'active' ? `${next.remaining} detik` : 'Sesi berakhir';
        if (active()) { timer = setInterval(tick, 1000); }
    }
    async function rpc(action, args = {}, stream = false) {
        const response = await fetch(c.url, {method:'POST', credentials:'same-origin',
            body:new URLSearchParams({cmid:c.cmid, sesskey:c.sesskey, action, ...args, stream:stream ? 1 : 0})});
        if (!response.ok) {
            let error;
            try { error = (await response.json()).error; } catch (_) { /* Login expiry/HTML errors. */ }
            throw Error(error || `Permintaan gagal (${response.status}). Muat ulang halaman dan periksa login.`);
        }
        if (!stream) {
            const reply = await response.json();
            if (reply.error) throw Error(reply.error);
            if (!reply.result) throw Error('Respons server tidak lengkap. Klik Muat ulang status / transkrip.');
            return reply.result;
        }
        const reader = response.body.getReader(), decoder = new TextDecoder();
        let buffer = '', result = null;
        el('stream').textContent = ''; el('stream').hidden = false;
        for (;;) {
            const {value, done} = await reader.read();
            buffer += decoder.decode(value || new Uint8Array(), {stream:!done});
            let end;
            while ((end = buffer.indexOf('\n\n')) >= 0) {
                const frame = buffer.slice(0, end); buffer = buffer.slice(end + 2);
                const event = frame.split('\n').find(x => x.startsWith('event: '))?.slice(7);
                const raw = frame.split('\n').filter(x => x.startsWith('data: ')).map(x => x.slice(6)).join('\n');
                if (!raw) continue;
                const message = JSON.parse(raw);
                if (event === 'delta') el('stream').textContent += message.text;
                if (event === 'complete') result = message.result;
            }
            if (done) break;
        }
        if (!result) throw Error('Koneksi terputus. Klik Lanjutkan respons tertunda; jawaban akan diperiksa memakai ID yang sama.');
        return result;
    }
    async function act(message, fn) {
        if (busy) return;
        busy = true; controls(); el('status').textContent = message;
        try { await fn(); }
        catch (error) {
            el('status').textContent = `${message.startsWith('Mengakhiri')?'Pengakhiran belum terkonfirmasi. ':''}${error.message} Jika sesi masih aktif, gunakan tombol Lanjutkan untuk memeriksa giliran terakhir.`;
        } finally {
            busy = false; controls();
            // Do not display a partial streamed question a second time beside the saved transcript.
            el('stream').hidden = true; el('stream').textContent = '';
            if (expiryNeeded) {
                expiryNeeded = false;
                if (data && data.session.status === 'active' && deadline <= Date.now()) {
                    void act('Memeriksa akhir durasi…', async () => render(await rpc('read', {sid})));
                }
            }
        }
    }
    if(el('obe-refresh-result'))el('obe-refresh-result').onclick=()=>act('Memuat penilaian terbaru…',async()=>{if(sid)render(await rpc('read',{sid}));});
    el('consent').onchange = controls; el('snapshotid').onchange = controls;
    el('start').onclick = () => act('Memulai wawancara…', async () => render(await rpc('start', {
        consent:el('consent').checked ? 1 : 0, snapshotid:el('snapshotid').value
    })));
    el('resume').onclick = () => act('Memuat giliran terakhir…', async () => {
        if (!sid) return;
        render(await rpc('read', {sid}));
        const pending = getPending();
        if (active() && pending) {
            el('status').textContent = 'Melanjutkan respons untuk jawaban tersimpan…';
            render(await rpc('answer', {sid, ...pending}, true));
        }
    });
    el('send').onclick = () => act('Menyimpan jawaban dan memproses respons…', async () => {
        if (!active() || !data.cananswer) throw Error('Tidak ada pertanyaan aktif yang menunggu jawaban.');
        const text = el('answer').value;
        if (!text.trim()) throw Error('Isi jawaban terlebih dahulu.');
        const pending = {requestid:crypto.randomUUID(), questionkey:data.questionkey, text};
        putPending(pending); controls();
        render(await rpc('answer', {sid, ...pending}, true));
    });
    el('end').onclick = () => act('Mengakhiri wawancara dan menyimpan status…', async () => {
        if (!sid) throw Error('Sesi belum dimulai.');
        const result = await rpc('close', {sid}); render(result);
        if (result.session.status === 'active') throw Error('Server belum mengakhiri sesi. Coba Akhiri wawancara lagi.');
    });
    controls(); el('status').textContent = 'Memuat status sesi…';
    if (sid) void act('Memuat status sesi…', async () => render(await rpc('read', {sid})));
    else el('status').textContent = statusText();
})();
