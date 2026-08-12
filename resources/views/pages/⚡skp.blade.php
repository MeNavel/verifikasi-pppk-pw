<?php

use Livewire\Component;
use App\Models\Pppk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public array $pendingNips = [];
    public int $currentPegawaiIndex = 0;
    public ?Pppk $pegawai = null;
    public ?array $skpTriwulan = null;

    public string $searchNip = '';
    public string $selectedOpd = '';

    public array $opdOptions = [
        ['id' => '67', 'name' => 'Dinas Kepemudaan dan Olahraga, Kebudayaan dan Pariwisata'],
        ['id' => '27', 'name' => 'Dinas Perpustakaan dan Kearsipan'],
        ['id' => '23', 'name' => 'Dinas Pendidikan'],
        ['id' => '68', 'name' => 'Dinas Kesehatan, Pengendalian Pendudukan dan Keluarga Berencana'],
        ['id' => '39', 'name' => 'Kecamatan Bangsalsari'],
        ['id' => '40', 'name' => 'Kecamatan Gumukmas'],
        ['id' => '57', 'name' => 'Kecamatan Sukorambi'],
        ['id' => '59', 'name' => 'Kecamatan Sumberbaru'],
    ];

    // Opsi penolakan khusus SKP (tidak perlu lagi dibungkus fileConfig)
    public array $opsiTolak = [
        'Dokumen tidak sesuai',
        'Dokumen tidak terbaca',
        'Dokumen tidak lengkap',
        'Tidak ada TTD pejabat penilai kinerja',
        'Tidak ada TTD pegawai yang dinilai',
        'Nilai tidak sesuai dengan sistem',
    ];

    public function mount(): void
    {
        $this->loadPendingNips();
    }

    public function loadPendingNips(): void
    {
        $targetNips = DB::table('target_nips')->pluck('nip')->toArray();

        if (empty($targetNips)) {
            $targetNips = ['KOSONG'];
        }

        $query = DB::connection('kantor')->table('tbpppk')
            ->select('tbpppk.nip')
            ->join('v_pegawai_lengkap', 'tbpppk.nip', '=', 'v_pegawai_lengkap.nip')
            ->join('satuan_kerja', 'v_pegawai_lengkap.kodesatker', '=', 'satuan_kerja.kode_satuan_kerja')
            ->whereIn('tbpppk.nip', $targetNips)
            ->whereNotNull('tbpppk.tgl_submit')
            ->where(function ($q) {
                $q->where('tbpppk.ver29', '!=', 1)->orWhereNull('tbpppk.ver29');
            });

        if (!empty($this->searchNip)) {
            $query->where('tbpppk.nip', 'like', '%' . $this->searchNip . '%');
        }

        if (!empty($this->selectedOpd)) {
            $query->where(function ($q) {
                $q->where('satuan_kerja.kode_satuan_kerja', $this->selectedOpd)
                    ->orWhere('satuan_kerja.parent_kode', $this->selectedOpd);

                if ($this->selectedOpd === '23' || $this->selectedOpd === '68') {
                    $q->orWhere('satuan_kerja.kode_satuan_kerja', 'like', $this->selectedOpd . '%')
                        ->orWhere('satuan_kerja.parent_kode', 'like', $this->selectedOpd . '%');
                }
            });
        }

        $this->pendingNips = $query->distinct()->pluck('tbpppk.nip')->toArray();
        $this->currentPegawaiIndex = 0;
        $this->loadCurrentPegawai();
    }

    public function loadCurrentPegawai(): void
    {
        if (empty($this->pendingNips) || !isset($this->pendingNips[$this->currentPegawaiIndex])) {
            $this->pegawai = null;
            $this->skpTriwulan = null;
            return;
        }

        $nip = $this->pendingNips[$this->currentPegawaiIndex];
        $this->pegawai = Pppk::on('kantor')->where('nip', $nip)->first();
        $this->skpTriwulan = null; // di-load belakangan lewat wire:init, tidak blocking
    }

    public function fetchSkpTriwulan(): void
    {
        if (!$this->pegawai) {
            return;
        }

        $nip = $this->pegawai->nip;

        $this->skpTriwulan = Cache::remember(
            "skp_triwulan_{$nip}",
            now()->addMinutes(15),
            function () use ($nip) {
                try {
                    $response = Http::withOptions(['verify' => false])
                        ->timeout(5)
                        ->retry(1, 200)
                        ->get("https://skp-asn.jemberkab.go.id/api/get_skp_pppkpw_2026/{$nip}");

                    $data = $response->json();

                    return [
                        'tw1' => $data[0]['predikat_kinerja_tw_1'] ?? '-',
                        'tw2' => $data[0]['predikat_kinerja_tw_2'] ?? '-',
                    ];
                } catch (\Throwable $e) {
                    return ['tw1' => 'Gagal dimuat', 'tw2' => 'Gagal dimuat'];
                }
            }
        );
    }

    public function cariNip(): void
    {
        $nipClean = trim($this->searchNip);

        if (empty($nipClean)) {
            $this->warning('Masukkan NIP terlebih dahulu.');
            return;
        }

        $pegawaiCari = Pppk::on('kantor')
            ->where('nip', $nipClean)
            ->whereNotNull('tgl_submit')
            ->first();

        if (!$pegawaiCari) {
            $cekAda = Pppk::on('kantor')->where('nip', $nipClean)->exists();
            $this->error($cekAda
                ? "NIP $nipClean ditemukan, tetapi statusnya BELUM SUBMIT atau sedang DIREVISI."
                : "NIP $nipClean tidak ditemukan di database.");
            return;
        }

        $this->pegawai = $pegawaiCari;
        $this->skpTriwulan = null;

        if ($pegawaiCari->ver29 == 1) {
            $this->info("NIP $nipClean sudah terverifikasi SKP-nya!");
        } else {
            $this->success("Menampilkan data verifikasi untuk NIP $nipClean.");
        }
    }

    public function approve(): void
    {
        if (!$this->pegawai) return;

        $this->pegawai->ver29 = 1;
        $this->pegawai->cat29 = null;
        $this->pegawai->save();

        $this->success('SKP disetujui!');
        $this->afterAction();
    }

    public function tolakDenganCatatan(int $opsiIndex): void
    {
        if (!$this->pegawai) return;

        $catatanText = $this->opsiTolak[$opsiIndex] ?? 'Dokumen tidak sesuai';

        $this->pegawai->ver29 = 0;
        $this->pegawai->cat29 = $catatanText;
        $this->pegawai->tgl_submit = null;
        $this->pegawai->save();

        $this->warning("SKP ditolak dengan catatan: $catatanText");
        $this->afterAction();
    }

    private function afterAction(): void
    {
        unset($this->pendingNips[$this->currentPegawaiIndex]);
        $this->pendingNips = array_values($this->pendingNips);

        if ($this->currentPegawaiIndex >= count($this->pendingNips)) {
            $this->currentPegawaiIndex = 0;
        }

        $this->searchNip = '';
        $this->loadCurrentPegawai();
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
};
?>

<div class="flex flex-col h-[calc(100vh-40px)] -mx-4 -mt-4 overflow-hidden bg-base-200">

    <x-header
            title="Verifikasi Berkas PPPK PW"
            subtitle="SKP"
            size="text-lg"
            separator
            progress-indicator="loadPendingNips,cariNip,approve,tolakDenganCatatan,nextPegawai,prevPegawai"
            progress-indicator-class="progress-primary"
            class="px-5 pt-3 pb-0 shrink-0"
    >
        <x-slot:middle class="!justify-end">
            <div class="flex flex-wrap items-center gap-2">
                <x-select
                        wire:model="selectedOpd"
                        :options="$opdOptions"
                        option-value="id"
                        option-label="name"
                        placeholder="Semua Satuan Kerja"
                        placeholder-value=""
                        icon="o-building-office"
                        class="select-sm w-44 lg:w-52"
                />
                <x-button
                        wire:click="loadPendingNips"
                        spinner="loadPendingNips"
                        icon="o-arrow-path"
                        class="btn-primary btn-sm text-white"
                        tooltip-bottom="Terapkan Filter OPD"
                />

                <div class="divider divider-horizontal mx-0"></div>

                <x-input
                        placeholder="Cari NIP..."
                        wire:model.defer="searchNip"
                        wire:keydown.enter="cariNip"
                        icon="o-magnifying-glass"
                        class="input-sm w-40 lg:w-52"
                />
                <x-button wire:click="cariNip" spinner="cariNip" icon="o-magnifying-glass" class="btn-secondary btn-sm text-white" tooltip-bottom="Cari NIP" />
            </div>
        </x-slot:middle>

        <x-slot:actions>
            @if(count($pendingNips) > 0)
                <div class="flex items-center gap-3">
                    <div class="flex flex-col text-right">
                        <span class="text-xs text-base-content/70 font-semibold uppercase tracking-wider">Antrean</span>
                        <span class="text-sm font-bold text-primary">
                            {{ $currentPegawaiIndex + 1 }} / {{ count($pendingNips) }}
                        </span>
                    </div>
                    <div class="join shadow-sm">
                        <x-button icon="o-chevron-left" wire:click="prevPegawai" class="btn-sm join-item bg-base-200 hover:bg-base-300 border-base-300"/>
                        <x-button icon="o-chevron-right" wire:click="nextPegawai" class="btn-sm join-item bg-base-200 hover:bg-base-300 border-base-300"/>
                    </div>
                </div>
            @endif
        </x-slot:actions>
    </x-header>

    <div class="flex-1 flex overflow-hidden">

        @if($pegawai)
            @php
                $fileUrl = $pegawai->berkas29
                    ? route('pdf.sftp.preview', ['username' => $pegawai->username, 'filename' => $pegawai->berkas29])
                    : null;
            @endphp

            <div
                    class="flex-1 bg-neutral text-neutral-content flex flex-col h-full relative overflow-hidden shadow-inner"
                    x-data="{ iframeLoading: true }"
            >
                <div
                        wire:loading.flex
                        wire:target="nextPegawai,prevPegawai,loadPendingNips,cariNip,approve,tolakDenganCatatan"
                        class="absolute inset-0 z-30 bg-neutral/90 hidden flex-col items-center justify-center gap-3"
                >
                    <x-loading class="loading-lg text-primary" />
                    <span class="text-sm text-neutral-content/70">Memuat data pegawai...</span>
                </div>

                @if($fileUrl)
                    <div
                            x-show="iframeLoading"
                            x-transition.opacity
                            class="absolute inset-0 z-20 flex flex-col items-center justify-center gap-3 bg-neutral"
                    >
                        <x-loading class="loading-lg text-primary" />
                        <span class="text-sm text-neutral-content/70">Memuat dokumen...</span>
                    </div>

                    <iframe
                            src="{{ $fileUrl }}#toolbar=0&zoom=page-fit"
                            class="w-full h-full border-0 rounded-tl-lg"
                            wire:key="file-{{ $pegawai->nip }}"
                            x-init="iframeLoading = true"
                            @load="iframeLoading = false"
                    ></iframe>
                @else
                    <div class="flex-1 flex items-center justify-center flex-col gap-4 opacity-60">
                        <x-icon name="o-document-magnifying-glass" class="w-24 h-24" />
                        <p class="text-xl font-medium tracking-wide">Berkas <span class="text-primary">SKP</span> tidak diunggah.</p>
                    </div>
                @endif
            </div>

            <div class="w-88 lg:w-96 border-l border-base-300 flex flex-col shrink-0 h-full shadow-2xl z-10">

                <div class="flex-1 overflow-y-auto p-5 flex flex-col gap-5">

                    <div
                            class="bg-base-200/50 p-4 rounded-2xl border border-base-300 shadow-sm"
                            wire:init="fetchSkpTriwulan"
                            wire:key="identitas-{{ $pegawai->nip }}"
                    >
                        <p class="font-extrabold text-lg leading-tight text-base-content mb-2">{{ $pegawai->nama ?? 'Pegawai' }}</p>
                        <div class="flex flex-col gap-1 text-sm font-mono text-base-content/80">
                            <span class="flex items-center gap-2"><x-icon name="o-identification" class="w-4 h-4 text-primary"/> {{ $pegawai->nip }}</span>
                            <span class="flex items-start gap-2"><x-icon name="o-briefcase" class="w-4 h-4 text-primary shrink-0"/> <span class="line-clamp-2">{{ $pegawai->nama_jabatan }}</span></span>
                        </div>
                        <div class="mt-3">
                            <x-badge value="Menunggu Verifikasi" class="badge-warning badge-sm font-semibold w-full py-3" />
                        </div>

                        <div class="grid grid-cols-2 gap-2 mt-3">
                            <div class="bg-base-100 rounded-xl p-2.5 text-center border border-base-300">
                                <p class="text-[10px] uppercase font-bold text-base-content/50 tracking-wider">Triwulan 1</p>
                                @if(is_null($skpTriwulan))
                                    <div class="flex justify-center mt-1"><x-loading class="loading-xs" /></div>
                                @else
                                    <p class="text-sm font-extrabold text-primary mt-0.5">{{ $skpTriwulan['tw1'] }}</p>
                                @endif
                            </div>
                            <div class="bg-base-100 rounded-xl p-2.5 text-center border border-base-300">
                                <p class="text-[10px] uppercase font-bold text-base-content/50 tracking-wider">Triwulan 2</p>
                                @if(is_null($skpTriwulan))
                                    <div class="flex justify-center mt-1"><x-loading class="loading-xs" /></div>
                                @else
                                    <p class="text-sm font-extrabold text-primary mt-0.5">{{ $skpTriwulan['tw2'] }}</p>
                                @endif
                            </div>
                        </div>
                    </div>

                    <x-button
                            wire:click="approve"
                            spinner="approve"
                            label="SETUJUI DOKUMEN"
                            icon="o-check-circle"
                            class="btn-success w-full min-h-14 h-auto text-white text-base font-extrabold shadow-md hover:scale-[1.02] transition-transform"
                    />

                    <div class="divider text-xs font-bold text-base-content/40 my-0 uppercase tracking-wider">Tolak & Beri Catatan</div>

                    <div class="flex flex-col gap-2.5">
                        @foreach($opsiTolak as $index => $opsi)
                            <x-button
                                    wire:click="tolakDenganCatatan({{ $index }})"
                                    spinner="tolakDenganCatatan"
                                    label="{{ $opsi }}"
                                    icon="o-x-circle"
                                    class="btn-outline btn-error h-auto min-h-12 py-2 px-4 justify-start text-left text-sm hover:scale-[1.01] transition-transform border-base-300 hover:border-error bg-base-100 shadow-sm"
                            />
                        @endforeach
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
                <x-button label="Muat Ulang Antrean" wire:click="loadPendingNips" spinner="loadPendingNips" class="btn-primary text-white mt-8 px-8 shadow-lg" icon="o-arrow-path" />
            </div>
        @endif

    </div>
</div>
