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

    public array $availableFiles = [];
    public int $currentFileIndex = 0;

    public array $fileConfig = [
        'berkas2'  => ['label' => 'Ijazah', 'ver' => 'ver2', 'cat' => 'cat2', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak terbaca']],
        'berkas3'  => ['label' => 'Transkrip Nilai', 'ver' => 'ver3', 'cat' => 'cat3', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak terbaca']],
        'berkas11' => ['label' => 'Kartu Keluarga (KK)', 'ver' => 'ver11', 'cat' => 'cat11', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak terbaca']],
        'berkas17' => ['label' => 'SK PPPK Paruh Waktu', 'ver' => 'ver17', 'cat' => 'cat17', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak terbaca']],
        'berkas28' => ['label' => 'Sertifikat MOOC', 'ver' => 'ver28', 'cat' => 'cat28', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak terbaca']],
        'berkas29' => ['label' => 'Dokumen SKP', 'ver' => 'ver29', 'cat' => 'cat29', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak terbaca']],
        'berkas30' => ['label' => 'Suket Kesehatan', 'ver' => 'ver30', 'cat' => 'cat30', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak terbaca']],
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
            $this->pendingNips = [];
            return;
        }

        // Ambil NIP yang belum lengkap verifikasinya
        $this->pendingNips = DB::connection('kantor')->table('tbpppk')
            ->whereIn('nip', $targetNips)
            ->whereNotNull('tgl_submit')
            ->where(function ($q) {
                $q->where('ver2', '!=', 1)->orWhereNull('ver2')
                    ->orWhere('ver3', '!=', 1)->orWhereNull('ver3')
                    ->orWhere('ver11', '!=', 1)->orWhereNull('ver11')
                    ->orWhere('ver17', '!=', 1)->orWhereNull('ver17')
                    ->orWhere('ver28', '!=', 1)->orWhereNull('ver28')
                    ->orWhere('ver29', '!=', 1)->orWhereNull('ver29')
                    ->orWhere('ver30', '!=', 1)->orWhereNull('ver30');
            })
            ->pluck('nip')
            ->toArray();
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
            // Hanya masukkan file yang belum berstatus 1 (valid)
            if ($this->pegawai->$verCol != 1 && !empty($this->pegawai->$fileKey)) {
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

        $this->pegawai->$verCol = 3;
        $this->pegawai->$catCol = $catatanText;
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

    <div class="border-b border-base-300 px-6 py-3 flex flex-wrap items-center justify-between gap-3 shrink-0 shadow-sm z-10">
        <div class="flex items-center gap-2">
            <x-input
                    placeholder="Cari NIP Pegawai..."
                    wire:model.defer="searchNip"
                    wire:keydown.enter="cariNip"
                    icon="o-magnifying-glass"
                    class="input-sm w-56 lg:w-72"
            />
            <x-button label="Cari" wire:click="cariNip" class="btn-primary btn-sm text-white" />
        </div>

        <div class="flex items-center gap-3">
            @if(count($pendingNips) > 0)
                <span class="text-sm font-semibold">
                    Pegawai {{ $currentPegawaiIndex + 1 }} dari {{ count($pendingNips) }} Antrean
                </span>
                <div class="join shadow-sm mr-10">
                    <x-button icon="o-chevron-left" wire:click="prevPegawai" class="btn-sm join-item bg-base-100" tooltip-bottom="Pegawai Sebelumnya" />
                    <x-button icon="o-chevron-right" wire:click="nextPegawai" class="btn-sm join-item bg-base-100" tooltip-bottom="Pegawai Selanjutnya" />
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

            <div class="flex-1 bg-gray-900 flex flex-col h-full relative overflow-hidden">
                @if($fileName)
                    <iframe src="{{ $fileUrl }}#toolbar=0&view=Fit" class="w-full h-full border-0"></iframe>
                @else
                    <div class="flex-1 flex items-center justify-center text-gray-500 flex-col gap-3">
                        <x-icon name="o-document-magnifying-glass" class="w-20 h-20 opacity-30" />
                        <p class="text-lg">Berkas {{ $config['label'] }} tidak diunggah.</p>
                    </div>
                @endif
            </div>

            <div class="w-100 h-full border-l border-base-300 flex flex-col shrink-0 overflow-y-auto p-5 justify-between">

                <div class="flex flex-col gap-4">
                    <div class="p-4 rounded-xl border border-blue-100 shadow-sm">
                        <div class="flex justify-between items-start mb-1">
                            <p class="font-bold text-lg leading-tight">{{ $pegawai->nama ?? 'Pegawai' }}</p>
                        </div>
                        <p class="text-sm font-mono">NIP: {{ $pegawai->nip }}</p>
                        <p class="text-sm font-mono mb-3">Jabatan: {{ $pegawai->nama_jabatan }}</p>
                        <x-badge value="Menunggu Verifikasi" class="badge-warning font-semibold" />
                    </div>

                    <div class="flex justify-between items-center p-3 rounded-lg border border-blue-100 mt-2">
                        <p class="font-bold text-md uppercase text-primary">{{ $config['label'] }}</p>
                        <x-badge value="Berkas {{ $currentFileIndex + 1 }} / {{ count($availableFiles) }}" class="badge-primary badge-outline badge-sm" />
                    </div>

                    <button wire:click="approve" wire:loading.attr="disabled" class="btn btn-success w-full h-14 text-white text-base font-bold shadow-md hover:scale-[1.02] transition-transform mt-2">
                        <x-icon name="o-check-circle" class="w-6 h-6 mr-1" />
                        APPROVE DOKUMEN
                    </button>

                    <div class="divider text-xs font-bold text-gray-400 my-2">ATAU TOLAK & BERI CATATAN:</div>

                    <div class="flex flex-col gap-2">
                        @foreach($config['opsi'] as $index => $opsi)
                            <button wire:click="tolakDenganCatatan({{ $index }})" wire:loading.attr="disabled" class="btn btn-outline btn-error h-auto min-h-12 py-2 px-3 justify-start text-left text-sm leading-tight hover:scale-[1.01] transition-transform">
                                <x-icon name="o-x-circle" class="w-5 h-5 mr-2 shrink-0" />
                                {{ $opsi }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="pt-5 border-t border-base-200 mt-4">
                    <div class="flex justify-between items-center text-sm font-semibold">
                        <span>Pindah Berkas:</span>
                        <div class="join shadow-sm">
                            <button
                                    class="btn btn-sm join-item bg-base-200"
                                    @if($currentFileIndex <= 0) disabled @endif
                                    wire:click="$set('currentFileIndex', {{ max(0, $currentFileIndex - 1) }})">
                                Prev
                            </button>
                            <button
                                    class="btn btn-sm join-item bg-base-200"
                                    @if($currentFileIndex >= count($availableFiles) - 1) disabled @endif
                                    wire:click="$set('currentFileIndex', {{ min(count($availableFiles) - 1, $currentFileIndex + 1) }})">
                                Next
                            </button>
                        </div>
                    </div>
                </div>

            </div>

        @else
            <div class="flex-1 flex flex-col items-center justify-center bg-base-100 p-8 text-center">
                <x-icon name="o-check-badge" class="w-24 h-24 text-green-500 mb-4" />
                <h2 class="text-3xl font-bold">Semua Berkas Tuntas!</h2>
                <p class="text-gray-500 text-base mt-2">
                    Tidak ada antrean atau NIP yang Anda cari sudah selesai diverifikasi.
                </p>
                <x-button label="Muat Ulang Antrean" wire:click="loadPendingNips" class="btn-primary text-white mt-6" icon="o-arrow-path" />
            </div>
        @endif

    </div>
</div>
