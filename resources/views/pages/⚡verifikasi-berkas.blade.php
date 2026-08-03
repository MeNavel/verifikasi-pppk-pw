<?php

use Livewire\Component;
use App\Models\Pppk;
use Illuminate\Support\Facades\DB;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public array $targetNips = [];
    public int $currentPegawaiIndex = 0;
    public ?Pppk $pegawai = null;

    public array $availableFiles = [];
    public int $currentFileIndex = 0;

    // Konfigurasi File (Sama Persis)
    public array $fileConfig = [
        'berkas2' => ['label' => 'Ijazah', 'ver' => 'ver2', 'cat' => 'cat2', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak terbaca',]],
        'berkas3' => ['label' => 'Transkrip Nilai', 'ver' => 'ver3', 'cat' => 'cat3', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak terbaca',]],
        'berkas11' => ['label' => 'Kartu Keluarga (KK)', 'ver' => 'ver11', 'cat' => 'cat11', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak terbaca',]],
        'berkas17' => ['label' => 'SK PPPK Paruh Waktu', 'ver' => 'ver17', 'cat' => 'cat17', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak terbaca',]],
        'berkas30' => ['label' => 'Suket Kesehatan', 'ver' => 'ver30', 'cat' => 'cat30', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak terbaca', 'Tidak ada keterangan \'Sehat\' atau \'Tidak Sehat\'', 'Tidak berasal dari faskes pemerintah', 'Tidak ada TTD atau stempel basah dokter pemeriksa', 'Tanggal surat kesehatan sebelum bulan Agustus 2026', 'Tidak ada nomor dan/atau tanggal surat']],
    ];

    public function mount(): void
    {
        $this->targetNips = DB::table('target_nips')->pluck('nip')->toArray();
        $this->loadPegawai();
    }

    public function loadPegawai(): void
    {
        if ($this->currentPegawaiIndex >= count($this->targetNips)) {
            $this->pegawai = null;
            return;
        }

        $nip = $this->targetNips[$this->currentPegawaiIndex];
        $this->pegawai = Pppk::where('nip', $nip)->first();

        if ($this->pegawai) {
            $this->availableFiles = [];
            foreach ($this->fileConfig as $col => $cfg) {
                if (!empty($this->pegawai->$col)) {
                    $this->availableFiles[] = $col;
                }
            }

            if (empty($this->availableFiles)) {
                $this->currentPegawaiIndex++;
                $this->loadPegawai();
                return;
            }

            $this->currentFileIndex = 0;
        } else {
            $this->currentPegawaiIndex++;
            $this->loadPegawai();
        }
    }

    // --- AKSI 1 KLIK: APPROVE ---
    public function approve(): void
    {
        $currentBerkasCol = $this->availableFiles[$this->currentFileIndex];
        $verCol = $this->fileConfig[$currentBerkasCol]['ver'];
        $catCol = $this->fileConfig[$currentBerkasCol]['cat'];

        // Langsung simpan ver=1, cat=NULL, lalu next
        $this->pegawai->update([
            $verCol => 1,
            $catCol => null,
        ]);

        $this->success("Di-Approve!");
        $this->lanjutKeBerkasBerikutnya();
    }

    // --- AKSI 1 KLIK: TOLAK DENGAN CATATAN (Berdasarkan Index Tombol) ---
    public function tolakDenganCatatan(int $indexOpsi): void
    {
        $currentBerkasCol = $this->availableFiles[$this->currentFileIndex];
        $verCol = $this->fileConfig[$currentBerkasCol]['ver'];
        $catCol = $this->fileConfig[$currentBerkasCol]['cat'];

        // Ambil string catatan dari array berdasarkan tombol yang diklik
        $catatanPilihan = $this->fileConfig[$currentBerkasCol]['opsi'][$indexOpsi];

        // Langsung simpan ver=0, cat=Isi Catatan, lalu next
        $this->pegawai->update([
            $verCol => 0,
            $catCol => $catatanPilihan,
            'tgl_submit' => null,
        ]);

        $this->warning("Ditolak: $catatanPilihan");
        $this->lanjutKeBerkasBerikutnya();
    }

    private function lanjutKeBerkasBerikutnya(): void
    {
        $this->currentFileIndex++;

        if ($this->currentFileIndex >= count($this->availableFiles)) {
            $this->success("Semua berkas {$this->pegawai->nama} Selesai!");
            $this->currentPegawaiIndex++;
            $this->loadPegawai();
        }
    }
}; ?>

<div class="h-screen w-full flex flex-col lg:flex-row gap-4 p-4 bg-base-100 overflow-hidden">

    @if(!$pegawai)
        <div class="w-full flex flex-col items-center justify-center h-full bg-base-200 rounded-xl">
            <x-icon name="o-check-circle" class="w-20 h-20 text-success mb-4" />
            <h2 class="text-3xl font-bold">Semua Selesai!</h2>
            <p class="text-gray-500 mt-2">Tidak ada lagi NIP dalam antrean.</p>
        </div>
    @else
        @php
            $currentBerkasCol = $availableFiles[$currentFileIndex];
            $config = $fileConfig[$currentBerkasCol];
            $fileName = $pegawai->$currentBerkasCol;

            $fileUrl = route('pdf.sftp.preview', [
                'username' => $pegawai->username,
                'filename' => $fileName
            ]);
        @endphp

        {{-- KIRI: Viewer PDF --}}
        <div class="w-full lg:w-8/12 h-full bg-base-200 rounded-xl border shadow-sm overflow-hidden flex flex-col">
            @if($fileName)
                <iframe src="{{ $fileUrl }}" class="w-full h-full border-0"></iframe>
            @else
                <div class="flex items-center justify-center h-full text-gray-400">Berkas tidak diunggah.</div>
            @endif
        </div>

        {{-- KANAN: Panel Informasi & Tombol 1-Klik --}}
        <div class="w-full lg:w-4/12 h-full flex flex-col gap-4 overflow-y-auto pr-1">

            {{-- Panel Info (Dibuat lebih ringkas) --}}
            <div class="bg-base-200 p-4 rounded-xl border shadow-sm">
                <div class="flex justify-between items-start mb-2">
                    <div>
                        <h2 class="text-lg font-extrabold text-primary leading-tight">{{ $pegawai->nama }}</h2>
                        <p class="text-xs font-semibold text-gray-500">{{ $pegawai->nip }}</p>
                    </div>
                    <x-badge value="{{ $currentPegawaiIndex + 1 }} / {{ count($targetNips) }}" class="badge-neutral badge-sm" />
                </div>

                <hr class="border-base-300 my-2" />

                <div class="flex justify-between items-center">
                    <p class="font-bold text-md uppercase">{{ $config['label'] }}</p>
                    <x-badge value="Dokumen {{ $currentFileIndex + 1 }} / {{ count($availableFiles) }}" class="badge-primary badge-outline badge-sm" />
                </div>
            </div>

            {{-- Panel Tombol Aksi --}}
            <div class="flex-1 flex flex-col gap-3">

                {{-- TOMBOL APPROVE UTAMA --}}
                <button
                        wire:click="approve"
                        wire:loading.attr="disabled"
                        class="btn btn-success w-full h-16 text-white text-lg font-bold shadow-md hover:scale-[1.02] transition-transform">
                    <x-icon name="o-check-circle" class="w-6 h-6 mr-1" />
                    APPROVE DOKUMEN
                </button>

                <div class="divider text-xs font-bold text-gray-400 my-1">TOLAK & BERI CATATAN:</div>

                {{-- LOOPING TOMBOL CATATAN (1 KLIK LANGSUNG TOLAK) --}}
                <div class="flex flex-col gap-2 overflow-y-auto pb-4">
                    @foreach($config['opsi'] as $index => $opsi)
                        <button
                                wire:click="tolakDenganCatatan({{ $index }})"
                                wire:loading.attr="disabled"
                                class="btn btn-outline btn-error h-auto min-h-12 py-2 px-4 justify-start text-left text-sm leading-tight hover:scale-[1.01] transition-transform">
                            {{ $opsi }}
                        </button>
                    @endforeach
                </div>

            </div>
        </div>
    @endif
</div>
