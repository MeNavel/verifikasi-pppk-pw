<?php

use Livewire\Component;
use App\Models\Pppk;
use Illuminate\Support\Facades\DB;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public array $pendingNips = []; // Hanya menyimpan NIP yang BENAR-BENAR butuh diverifikasi
    public int $currentPegawaiIndex = 0;
    public ?Pppk $pegawai = null;

    public array $availableFiles = [];
    public int $currentFileIndex = 0;

    public array $fileConfig = [
        'berkas2'  => ['label' => 'Ijazah', 'ver' => 'ver2', 'cat' => 'cat2', 'opsi' => ['Dokumen buram / tidak terbaca', 'Kualifikasi pendidikan tidak sesuai']],
        'berkas3'  => ['label' => 'Transkrip Nilai', 'ver' => 'ver3', 'cat' => 'cat3', 'opsi' => ['Dokumen buram / terpotong', 'IPK tidak memenuhi syarat']],
        'berkas11' => ['label' => 'Kartu Keluarga (KK)', 'ver' => 'ver11', 'cat' => 'cat11', 'opsi' => ['Dokumen buram / terpotong', 'KK tidak valid / kadaluarsa']],
        'berkas17' => ['label' => 'SK PPPK Paruh Waktu', 'ver' => 'ver17', 'cat' => 'cat17', 'opsi' => ['Dokumen tidak sesuai format', 'Tidak ada tanda tangan']],
        'berkas28' => ['label' => 'Scan MOOC', 'ver' => 'ver28', 'cat' => 'cat28', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak lengkap', 'Dokumen tidak terbaca', 'Tidak ada TTD Elektronik']],
        'berkas29' => ['label' => 'SKP 2026', 'ver' => 'ver29', 'cat' => 'cat29', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak lengkap', 'Dokumen tidak terbaca', 'Tidak ada TTD pejabat penilai kinerja', 'Tidak ada TTD pegawai yang dinilai', 'Nilai tidak sesuai dengan sistem']],
        'berkas30' => ['label' => 'Suket Kesehatan', 'ver' => 'ver30', 'cat' => 'cat30', 'opsi' => ['Dokumen tidak sesuai', 'Dokumen tidak terbaca', 'Tidak ada keterangan \'Sehat\' atau \'Tidak Sehat\'', 'Tidak berasal dari faskes pemerintah', 'Tidak ada TTD atau stempel basah dokter pemeriksa', 'Tanggal surat kesehatan sebelum bulan Agustus 2026', 'Tidak ada nomor dan/atau tanggal surat']],
    ];

    public function mount()
    {
        $this->initAntrean();
    }

    /**
     * OPTIMASI UTAMA:
     * Filter kondisi (tgl_submit terisi & ada file yang belum diapprove)
     * diselesaikan HANYA DENGAN 1 QUERY ke database kantor.
     */
    public function initAntrean()
    {
        // 1. Ambil 899 target NIP dari DB lokal
        $targetNips = DB::table('target_nips')->pluck('nip')->toArray();

        if (empty($targetNips)) {
            $this->pendingNips = [];
            return;
        }

        // 2. Query 1 kali ke DB Kantor
        $this->pendingNips = Pppk::whereIn('nip', $targetNips)
            ->whereNotNull('tgl_submit') // Pegawai sudah submit
            ->where('tgl_submit', '!=', '')
            ->where(function ($query) {
                // Pegawai minimal punya 1 berkas yang terisi TAPI status ver != 1
                foreach ($this->fileConfig as $col => $cfg) {
                    $verCol = $cfg['ver'];
                    $query->orWhere(function ($sub) use ($col, $verCol) {
                        $sub->whereNotNull($col)
                            ->where($col, '!=', '')
                            ->where(function($qVer) use ($verCol) {
                                $qVer->whereNull($verCol)
                                    ->orWhere($verCol, '!=', 1);
                            });
                    });
                }
            })
            ->pluck('nip')
            ->toArray();

        $this->currentPegawaiIndex = 0;
        $this->loadPegawaiAktif();
    }

    public function loadPegawaiAktif()
    {
        // Jika antrean yang valid sudah habis
        if (empty($this->pendingNips) || $this->currentPegawaiIndex >= count($this->pendingNips)) {
            $this->pegawai = null;
            return;
        }

        $nip = $this->pendingNips[$this->currentPegawaiIndex];

        // Ambil data detail pegawai (Sangat cepat karena diambil langsung via 1 NIP)
        $this->pegawai = Pppk::where('nip', $nip)->first();

        if ($this->pegawai) {
            $this->availableFiles = [];
            foreach ($this->fileConfig as $col => $cfg) {
                $verCol = $cfg['ver'];

                // Kumpulkan berkas mana saja dari orang ini yang perlu diverif
                if (!empty($this->pegawai->$col) && $this->pegawai->$verCol != 1) {
                    $this->availableFiles[] = $col;
                }
            }
            $this->currentFileIndex = 0;
        }
    }

    // --- AKSI 1 KLIK: APPROVE ---
    public function approve()
    {
        $currentBerkasCol = $this->availableFiles[$this->currentFileIndex];
        $verCol = $this->fileConfig[$currentBerkasCol]['ver'];
        $catCol = $this->fileConfig[$currentBerkasCol]['cat'];

        $this->pegawai->update([
            $verCol => 1,
            $catCol => null,
        ]);

        $this->success("Di-Approve!");
        $this->lanjut();
    }

    // --- AKSI 1 KLIK: TOLAK DENGAN CATATAN ---
    public function tolakDenganCatatan(int $indexOpsi)
    {
        $currentBerkasCol = $this->availableFiles[$this->currentFileIndex];
        $verCol = $this->fileConfig[$currentBerkasCol]['ver'];
        $catCol = $this->fileConfig[$currentBerkasCol]['cat'];

        $catatanPilihan = $this->fileConfig[$currentBerkasCol]['opsi'][$indexOpsi];

        $this->pegawai->update([
            $verCol => 0,
            $catCol => $catatanPilihan,
            'tgl_submit' => null, // Reset submit
        ]);

        $this->warning("Ditolak: {$catatanPilihan}");
        $this->lanjut();
    }

    private function lanjut()
    {
        $this->currentFileIndex++;

        if ($this->currentFileIndex >= count($this->availableFiles)) {
            $this->success("Semua berkas {$this->pegawai->nama} Selesai!");
            $this->currentPegawaiIndex++;
            $this->loadPegawaiAktif();
        }
    }
}; ?>

{{-- TAMPILAN BLADE SAMA SEPERTI SEBELUMNYA --}}
<div class="h-screen w-full flex flex-col lg:flex-row gap-4 p-4 bg-base-100 overflow-hidden">
    @if(!$pegawai)
        <div class="w-full flex flex-col items-center justify-center h-full bg-base-200 rounded-xl">
            <x-icon name="o-check-circle" class="w-20 h-20 text-success mb-4" />
            <h2 class="text-3xl font-bold">Semua Selesai!</h2>
            <p class="text-gray-500 mt-2">Tidak ada berkas/NIP yang perlu diverifikasi saat ini.</p>
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

        {{-- KIRI: PDF --}}
        <div class="w-full lg:w-8/12 h-full bg-base-200 rounded-xl border shadow-sm overflow-hidden flex flex-col">
            @if($fileName)
                <iframe src="{{ $fileUrl }}" class="w-full h-full border-0"></iframe>
            @else
                <div class="flex items-center justify-center h-full text-gray-400">Berkas tidak diunggah.</div>
            @endif
        </div>

        {{-- KANAN: Panel Tombol --}}
        <div class="w-full lg:w-4/12 h-full flex flex-col gap-4 overflow-y-auto pr-1">
            <div class="bg-base-200 p-4 rounded-xl border shadow-sm">
                <div class="flex justify-between items-start mb-2">
                    <div>
                        <h2 class="text-lg font-extrabold leading-tight">{{ $pegawai->nama }}</h2>
                        <p class="text-xs font-semibold">{{ $pegawai->nip }}</p>
                        <p class="text-xs font-semibold">{{ $pegawai->nama_jabatan }}</p>
                    </div>
                    {{-- Counter sekarang menggunakan count($pendingNips) yang 100% valid butuh diverif --}}
                    <x-badge value="{{ $currentPegawaiIndex + 1 }} / {{ count($pendingNips) }}" class="badge-neutral badge-sm" />
                </div>
                <hr class="border-base-300 my-2" />
                <div class="flex justify-between items-center">
                    <p class="font-bold text-md uppercase">{{ $config['label'] }}</p>
                    <x-badge value="Dokumen {{ $currentFileIndex + 1 }} / {{ count($availableFiles) }}" class="badge-primary badge-outline badge-sm" />
                </div>
            </div>

            <div class="flex-1 flex flex-col gap-3">
                <button wire:click="approve" wire:loading.attr="disabled" class="btn btn-success w-full h-16 text-white text-lg font-bold shadow-md hover:scale-[1.02] transition-transform">
                    <x-icon name="o-check-circle" class="w-6 h-6 mr-1" />
                    APPROVE DOKUMEN
                </button>

                <div class="divider text-xs font-bold text-gray-400 my-1">TOLAK & BERI CATATAN:</div>

                <div class="flex flex-col gap-2 overflow-y-auto pb-4">
                    @foreach($config['opsi'] as $index => $opsi)
                        <button wire:click="tolakDenganCatatan({{ $index }})" wire:loading.attr="disabled" class="btn btn-outline btn-error h-auto min-h-12 py-2 px-4 justify-start text-left text-sm leading-tight hover:scale-[1.01] transition-transform">
                            {{ $opsi }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
