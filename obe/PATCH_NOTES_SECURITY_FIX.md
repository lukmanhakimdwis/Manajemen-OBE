# OBE Moodle Plugin - Security & Business Process Fix Notes

Versi patch: 2026061801 / 1.0.1-security-fix

## Perbaikan keamanan utama

1. Menambahkan `classes/access_guard.php` sebagai pusat kontrol akses.
2. Menambahkan capability baru:
   - `local/obe:manageprogram`
   - `local/obe:viewownreport`
3. Mengunci dashboard admin dengan `local/obe:manage` atau Kaprodi yang terdaftar pada tabel prodi.
4. Mencegah user biasa diperlakukan seperti admin ketika bukan Kaprodi.
5. Mengunci endpoint proses langsung:
   - tahun akademik
   - prodi
   - relasi kelas
   - framework
   - kompetensi
   - bobot relasi
   - skala nilai
   - aktivitas nilai dosen
6. Mengunci endpoint export CSV dengan capability, enrollment, prodi ownership, dan `sesskey`.
7. Mengganti pesan error export agar tidak membocorkan path file dan line number server.
8. Mengunci dashboard mahasiswa agar hanya user enrolled atau role berwenang yang bisa membuka laporan course.
9. Memvalidasi mapping aktivitas nilai secara server-side:
   - cmid wajib milik course yang sedang diproses
   - Sub-CPMK wajib satu prodi dan satu tahun akademik dengan course
   - bobot wajib 0-100
   - total bobot Sub-CPMK tidak boleh melebihi 100
   - satu aktivitas Moodle tidak boleh punya mapping ganda
10. Mengurangi risiko stored XSS pada form pemetaan Sub-CPMK dengan JSON_HEX dan escaping sebelum render HTML.

## Perbaikan proses bisnis OBE

1. `cek_skala()` sekarang memakai `nilai_min` dan `nilai_max`, bukan hanya `nilai_min`.
2. Fallback ambang kompeten 60 dihapus. Jika skala belum dikonfigurasi, sistem menulis debugging log dan mengembalikan `null`.
3. Validasi rentang skala nilai ditambah: prodi/tahun wajib, nilai 0-100, min < max, dan tidak overlap.
4. Fungsi `tahun_akademik_manager::aktifkan()` ditambahkan agar status tahun akademik aktif konsisten.
5. Daftar Sub-CPMK pada form dosen dibatasi berdasarkan course/prodi/tahun akademik terkait.
6. Audit log ringan ditambahkan melalui tabel `local_obe_audit_log` dan helper `access_guard::audit()`.

## Catatan pemasangan

- Upload folder `obe` ke `local/obe` pada Moodle.
- Jalankan upgrade plugin dari Site administration > Notifications atau CLI Moodle.
- Setelah upgrade, review role/capability di Moodle agar role Manager/Admin OBE memiliki `local/obe:manage`.
- Untuk Kaprodi berbasis kolom `userid_kaprodi`, pastikan field tersebut diisi benar.
- Uji fitur utama di staging sebelum production: admin dashboard, export CSV, mapping aktivitas dosen, dashboard mahasiswa, sinkronisasi kompetensi, skala nilai, dan bobot relasi.


## RBAC Capability Correction 1.0.2
- Kaprodi access now requires BOTH: a program-level Moodle capability and a `userid_kaprodi` relation in `local_obe_prodi`.
- Added granular capabilities: `local/obe:viewprogramreport` and `local/obe:configprogramscale`.
- Program report exports now require `local/obe:viewprogramreport`.
- Skala nilai configuration now requires `local/obe:configprogramscale`.
- Program structure/configuration actions now require `local/obe:manageprogram`.
