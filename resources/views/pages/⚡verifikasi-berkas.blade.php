<?php

use Livewire\Component;
use App\Models\Pppk;
use Illuminate\Support\Facades\DB;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public array $pendingNips = []; // Menyimpan NIP yang butuh diverifikasi
    public int $currentPegawaiIndex = 0;
    public ?Pppk $pegawai = null;

    // Properti untuk Pencarian NIP Spesifik
    public string $searchNip = '';

    // --- TAMBAHAN PROPERTI FILTER OPD ---
    public string $selectedOpd = '';

    // Silakan lengkapi array ini sesuai dengan angka (kode_satuan_kerja) dan nama OPD yang ada di gambar Anda
    public array $opdOptions = [
        ['id' => '67', 'name' => 'Dinas Kepemudaan dan Olahraga, Kebudayaan dan Pariwisata'],
        ['id' => '27', 'name' => 'Dinas Perpustakaan dan Kearsipan'],
        ['id' => '2310', 'name' => 'Dinas Pendidikan Kec. Bangsalsari'],
        ['id' => '2311', 'name' => 'Dinas Pendidikan Kec. Gumukmas'],
        ['id' => '2328', 'name' => 'Dinas Pendidikan Kec. Sukorambi'],
        ['id' => '2330', 'name' => 'Dinas Pendidikan Kec. Sumberbaru'],
        ['id' => '39', 'name' => 'Kecamatan Bangsalsari'],
        ['id' => '40', 'name' => 'Kecamatan Gumukmas'],
        ['id' => '57', 'name' => 'Kecamatan Sukorambi'],
        ['id' => '59', 'name' => 'Kecamatan Sumberbaru'],
        ['id' => '6812', 'name' => 'Puskesmas Bangsalsari'],
        ['id' => '6817', 'name' => 'Puskesmas Gumukmas'],
        ['id' => '6841', 'name' => 'Puskesmas Rowotengah'],
        ['id' => '6846', 'name' => 'Puskesmas Sukorambi'],
        ['id' => '6847', 'name' => 'Puskesmas Sukorejo'],
        ['id' => '6849', 'name' => 'Puskesmas Sumberbaru'],
        ['id' => '6853', 'name' => 'Puskesmas Tembokrejo'],
    ];

    public array $availableFiles = [];
    public int $currentFileIndex = 0;

    public array $fileConfig = [
        'berkas2'  => ['label' => 'Ijazah', 'ver' => 'ver2', 'cat' => 'cat2', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak terbaca']],
        'berkas3'  => ['label' => 'Transkrip Nilai', 'ver' => 'ver3', 'cat' => 'cat3', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak terbaca']],
        'berkas11' => ['label' => 'Kartu Keluarga (KK)', 'ver' => 'ver11', 'cat' => 'cat11', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak terbaca']],
        'berkas17' => ['label' => 'SK PPPK Paruh Waktu', 'ver' => 'ver17', 'cat' => 'cat17', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak terbaca']],
        // 'berkas28' => ['label' => 'Sertifikat MOOC', 'ver' => 'ver28', 'cat' => 'cat28', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak terbaca']],
        // 'berkas29' => ['label' => 'Dokumen SKP', 'ver' => 'ver29', 'cat' => 'cat29', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak terbaca']],
        'berkas30' => ['label' => 'Suket Kesehatan', 'ver' => 'ver30', 'cat' => 'cat30', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak terbaca', 'Tidak ada keterangan Sehat atau Tidak Sehat', 'Tidak berasal dari faskes pemerintah', 'Tidak ada TTD atau stempel basah dokter pemeriksa', 'Tanggal surat kesehatan sebelum bulan Agustus 2026', 'Tidak ada nomor dan/atau tanggal surat']],
    ];

    public function mount(): void
    {
        $this->loadPendingNips();
        $this->loadCurrentPegawai();
    }

    public function loadPendingNips(): void
    {
        $targetNips = DB::table('target_nips')->pluck('nip')->toArray();

        if (empty($targetNips)) {
            $targetNips = ['KOSONG'];
        }

        // 1. Mulai Query dengan JOIN untuk kebutuhan Filter OPD
        $query = DB::connection('kantor')->table('tbpppk')
            ->select('tbpppk.nip')
            ->join('v_pegawai_lengkap', 'tbpppk.nip', '=', 'v_pegawai_lengkap.nip')
            ->join('satuan_kerja', 'v_pegawai_lengkap.kodesatker', '=', 'satuan_kerja.kode_satuan_kerja')
            ->whereIn('tbpppk.nip', $targetNips)
            ->whereNotNull('tbpppk.tgl_submit'); // LOGIKA LAMA: Harus sudah submit

        // 2. FILTER: Pencarian NIP Spesifik
        if (!empty($this->searchNip)) {
            $query->where('tbpppk.nip', 'like', '%' . $this->searchNip . '%');
        }

        // 3. FILTER BARU: Berdasarkan OPD (Mencocokkan kode atau parent_kode dari dropdown gambar)
        if (!empty($this->selectedOpd)) {
            $query->where(function ($q) {
                $q->where('satuan_kerja.kode_satuan_kerja', $this->selectedOpd)
                    ->orWhere('satuan_kerja.parent_kode', $this->selectedOpd);
            });
        }

        // 4. LOGIKA LAMA: Ambil yang status verifikasinya selain 1 (termasuk NULL/kosong)
        $query->where(function ($q) {
            $q->where(function($sub) { $sub->where('ver2', '!=', 1)->orWhereNull('ver2'); })
                ->orWhere(function($sub) { $sub->where('ver3', '!=', 1)->orWhereNull('ver3'); })
                ->orWhere(function($sub) { $sub->where('ver11', '!=', 1)->orWhereNull('ver11'); })
                ->orWhere(function($sub) { $sub->where('ver17', '!=', 1)->orWhereNull('ver17'); })
                ->orWhere(function($sub) { $sub->where('ver28', '!=', 1)->orWhereNull('ver28'); })
                ->orWhere(function($sub) { $sub->where('ver29', '!=', 1)->orWhereNull('ver29'); })
                ->orWhere(function($sub) { $sub->where('ver30', '!=', 1)->orWhereNull('ver30'); });
        });

        // Simpan hasil ke array pendingNips
        $this->pendingNips = $query->pluck('tbpppk.nip')->toArray();

        // Reset indeks pegawai yang sedang dibuka
        $this->currentPegawaiIndex = 0;
        $this->loadCurrentPegawai();
    }

    public function loadCurrentPegawai(): void
    {
        if (empty($this->pendingNips) || !isset($this->pendingNips[$this->currentPegawaiIndex])) {
            $this->pegawai = null;
            $this->availableFiles = [];
            return;
        }

        $nip = $this->pendingNips[$this->currentPegawaiIndex];
        $this->pegawai = Pppk::on('kantor')->where('nip', $nip)->first();

        $this->setupAvailableFiles();
    }

    public function setupAvailableFiles(): void
    {
        $this->availableFiles = [];
        $this->currentFileIndex = 0;

        if (!$this->pegawai) return;

        foreach ($this->fileConfig as $fileKey => $config) {
            $verCol = $config['ver'];
            $statusVer = $this->pegawai->$verCol;

            // Hanya masukkan file yang statusnya SELAIN 1 (yang belum diapprove)
            // Kita juga mengecek agar file-nya memang sudah diupload (!empty)
            if ($statusVer != 1 && !empty($this->pegawai->$fileKey)) {
                $this->availableFiles[] = $fileKey;
            }
        }
    }

    // FUNGSI BARU: Pencarian NIP Spesifik
    public function cariNip(): void
    {
        $nipClean = trim($this->searchNip);

        if (empty($nipClean)) {
            $this->warning('Masukkan NIP terlebih dahulu.');
            return;
        }

        $pegawaiCari = Pppk::on('kantor')->where('nip', $nipClean)->first();

        if (!$pegawaiCari) {
            $this->error("NIP $nipClean tidak ditemukan di database.");
            return;
        }

        $this->pegawai = $pegawaiCari;
        $this->setupAvailableFiles();

        if (empty($this->availableFiles)) {
            $this->info("NIP $nipClean sudah terverifikasi lengkap semua berkasnya!");
        } else {
            $this->success("Menampilkan data verifikasi untuk NIP $nipClean.");
        }
    }

    public function approve(): void
    {
        if (!$this->pegawai || empty($this->availableFiles)) return;

        $currentFile = $this->availableFiles[$this->currentFileIndex];
        $config = $this->fileConfig[$currentFile];

        $verCol = $config['ver'];
        $catCol = $config['cat'];

        $this->pegawai->$verCol = 1;
        $this->pegawai->$catCol = null; // Hapus catatan jika diapprove
        $this->pegawai->save();

        $this->success("{$config['label']} disetujui!");
        $this->nextFileOrPegawai();
    }

    public function tolakDenganCatatan(int $opsiIndex): void
    {
        if (!$this->pegawai || empty($this->availableFiles)) return;

        $currentFile = $this->availableFiles[$this->currentFileIndex];
        $config = $this->fileConfig[$currentFile];

        $verCol = $config['ver'];
        $catCol = $config['cat'];
        $catatanText = $config['opsi'][$opsiIndex] ?? 'Dokumen tidak sesuai';

        $this->pegawai->$verCol = 0;
        $this->pegawai->$catCol = $catatanText;
        $this->pegawai->tgl_submit = null;
        $this->pegawai->save();

        $this->warning("{$config['label']} ditolak dengan catatan: $catatanText");
        $this->nextFileOrPegawai();
    }

    public function nextFileOrPegawai(): void
    {
        // Re-check available files untuk pegawai saat ini
        $this->setupAvailableFiles();

        // Jika semua berkas pegawai ini sudah tuntas, lanjut ke pegawai berikutnya
        if (empty($this->availableFiles)) {
            $this->loadPendingNips();
            if ($this->currentPegawaiIndex >= count($this->pendingNips)) {
                $this->currentPegawaiIndex = 0;
            }
            $this->loadCurrentPegawai();
        }
    }

    public function nextPegawai(): void
    {
        if (count($this->pendingNips) > 0) {
            $this->currentPegawaiIndex = ($this->currentPegawaiIndex + 1) % count($this->pendingNips);
            $this->loadCurrentPegawai();
        }
    }

    public function prevPegawai(): void
    {
        if (count($this->pendingNips) > 0) {
            $this->currentPegawaiIndex = ($this->currentPegawaiIndex - 1 + count($this->pendingNips)) % count($this->pendingNips);
            $this->loadCurrentPegawai();
        }
    }
}; ?>

<div class="flex flex-col h-[calc(100vh-40px)] -mx-4 -mt-4 overflow-hidden bg-base-200">

    <div class="border-b border-base-300 px-5 py-3 flex flex-wrap items-center justify-between gap-4 shrink-0 shadow-sm z-20">

        <div class="flex flex-wrap items-center gap-2">
            <x-select
                    wire:model="selectedOpd"
                    :options="$opdOptions"
                    option-value="id"
                    option-label="name"
                    placeholder="Semua Satuan Kerja"
                    placeholder-value=""
                    icon="o-building-office"
                    class="select-sm w-48 lg:w-56"
            />
            <x-button
                    label="Terapkan"
                    wire:click="loadPendingNips"
                    class="btn-primary btn-sm text-white"
                    icon="o-arrow-path"
            />

            <div class="divider divider-horizontal mx-0"></div>

            <x-input
                    placeholder="Cari NIP Pegawai..."
                    wire:model.defer="searchNip"
                    wire:keydown.enter="cariNip"
                    icon="o-magnifying-glass"
                    class="input-sm w-48 lg:w-64"
            />
            <x-button label="Cari" wire:click="cariNip" class="btn-secondary btn-sm text-white" />
        </div>

        <div class="flex items-center gap-4">
            @if(count($pendingNips) > 0)
                <div class="flex flex-col text-right">
                    <span class="text-xs text-base-content/70 font-semibold uppercase tracking-wider">Antrean Saat Ini</span>
                    <span class="text-sm font-bold text-primary">
                        Pegawai {{ $currentPegawaiIndex + 1 }} dari {{ count($pendingNips) }}
                    </span>
                </div>
                <div class="join shadow-sm">
                    <x-button icon="o-chevron-left" wire:click="prevPegawai" class="btn-sm join-item bg-base-200 hover:bg-base-300 border-base-300"/>
                    <x-button icon="o-chevron-right" wire:click="nextPegawai" class="btn-sm join-item bg-base-200 hover:bg-base-300 border-base-300"/>
                </div>
            @endif
        </div>
    </div>

    <div class="flex-1 flex overflow-hidden">

        @if($pegawai && !empty($availableFiles))
            @php
                $currentFileKey = $availableFiles[$currentFileIndex];
                $config = $fileConfig[$currentFileKey];
                $fileName = $pegawai->$currentFileKey;

                $fileUrl = route('pdf.sftp.preview', [
                    'username' => $pegawai->username,
                    'filename' => $fileName
                ]);
            @endphp

            <div class="flex-1 bg-neutral text-neutral-content flex flex-col h-full relative overflow-hidden shadow-inner">
                @if($fileName)
                    <iframe src="{{ $fileUrl }}#toolbar=0&zoom=page-fit" class="w-full h-full border-0 rounded-tl-lg"></iframe>
                @else
                    <div class="flex-1 flex items-center justify-center flex-col gap-4 opacity-60">
                        <x-icon name="o-document-magnifying-glass" class="w-24 h-24" />
                        <p class="text-xl font-medium tracking-wide">Berkas <span class="text-primary">{{ $config['label'] }}</span> tidak diunggah.</p>
                    </div>
                @endif
            </div>

            <div class="w-[22rem] lg:w-96 border-l border-base-300 flex flex-col shrink-0 h-full shadow-2xl z-10">

                <div class="flex-1 overflow-y-auto p-5 flex flex-col gap-5">

                    <div class="bg-base-200/50 p-4 rounded-2xl border border-base-300 shadow-sm">
                        <p class="font-extrabold text-lg leading-tight text-base-content mb-2">{{ $pegawai->nama ?? 'Pegawai' }}</p>
                        <div class="flex flex-col gap-1 text-sm font-mono text-base-content/80">
                            <span class="flex items-center gap-2"><x-icon name="o-identification" class="w-4 h-4 text-primary"/> {{ $pegawai->nip }}</span>
                            <span class="flex items-start gap-2"><x-icon name="o-briefcase" class="w-4 h-4 text-primary shrink-0"/> <span class="line-clamp-2">{{ $pegawai->nama_jabatan }}</span></span>
                        </div>
                        <div class="mt-3">
                            <x-badge value="Menunggu Verifikasi" class="badge-warning badge-sm font-semibold w-full py-3" />
                        </div>
                    </div>

                    <div class="flex justify-between items-center p-3 rounded-xl bg-primary/10 border border-primary/20">
                        <div class="flex flex-col">
                            <span class="text-xs font-bold text-primary/70 uppercase tracking-widest">Memeriksa Berkas</span>
                            <span class="font-bold text-md text-primary">{{ $config['label'] }}</span>
                        </div>
                        <x-badge value="{{ $currentFileIndex + 1 }} / {{ count($availableFiles) }}" class="badge-primary badge-sm font-mono" />
                    </div>

                    <button wire:click="approve" wire:loading.attr="disabled" class="btn btn-success w-full min-h-[3.5rem] h-auto text-white text-base font-extrabold shadow-md hover:scale-[1.02] transition-transform">
                        <x-icon name="o-check-circle" class="w-7 h-7 mr-1" />
                        SETUJUI DOKUMEN
                    </button>

                    <div class="divider text-xs font-bold text-base-content/40 my-0 uppercase tracking-wider">Tolak & Beri Catatan</div>

                    <div class="flex flex-col gap-2.5">
                        @foreach($config['opsi'] as $index => $opsi)
                            <button wire:click="tolakDenganCatatan({{ $index }})" wire:loading.attr="disabled" class="btn btn-outline btn-error h-auto min-h-[3rem] py-2 px-4 justify-start text-left text-sm hover:scale-[1.01] transition-transform border-base-300 hover:border-error bg-base-100 shadow-sm">
                                <x-icon name="o-x-circle" class="w-5 h-5 mr-2 shrink-0" />
                                <span class="leading-snug">{{ $opsi }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="p-4 border-t border-base-300 bg-base-200/30">
                    <div class="flex justify-between items-center">
                        <span class="text-sm font-bold text-base-content/70">Navigasi Berkas:</span>
                        <div class="join shadow-sm">
                            <button
                                    class="btn btn-sm join-item bg-base-100 border-base-300 hover:bg-base-200"
                                    @if($currentFileIndex <= 0) disabled @endif
                                    wire:click="$set('currentFileIndex', {{ max(0, $currentFileIndex - 1) }})">
                                <x-icon name="o-arrow-left" class="w-4 h-4" /> Prev
                            </button>
                            <button
                                    class="btn btn-sm join-item bg-base-100 border-base-300 hover:bg-base-200"
                                    @if($currentFileIndex >= count($availableFiles) - 1) disabled @endif
                                    wire:click="$set('currentFileIndex', {{ min(count($availableFiles) - 1, $currentFileIndex + 1) }})">
                                Next <x-icon name="o-arrow-right" class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                </div>

            </div>

        @else
            <div class="flex-1 flex flex-col items-center justify-center bg-base-100 p-8 text-center">
                <div class="p-6 bg-success/10 rounded-full mb-6">
                    <x-icon name="o-check-badge" class="w-24 h-24 text-success" />
                </div>
                <h2 class="text-3xl font-extrabold text-base-content">Antrean Kosong!</h2>
                <p class="text-base-content/60 text-lg mt-3 max-w-md">
                    Tidak ada dokumen yang menunggu antrean verifikasi atau filter NIP yang Anda cari sudah selesai diverifikasi.
                </p>
                <x-button label="Muat Ulang Antrean" wire:click="loadPendingNips" class="btn-primary text-white mt-8 px-8 shadow-lg" icon="o-arrow-path" />
            </div>
        @endif

    </div>
</div>
