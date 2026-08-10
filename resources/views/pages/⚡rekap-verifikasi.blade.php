<?php

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new class extends Component {
    use WithPagination;

    public string $search = '';

    // Properti Drawer
    public bool $drawerFilter = false;

    // Filter OPD
    public string $opdFilter = '';

    // Filter 7 Jenis Berkas
    public string $f_ver2 = ''; // Ijazah
    public string $f_ver3 = ''; // Transkrip
    public string $f_ver11 = ''; // KTP/KK
    public string $f_ver17 = ''; // SK PPPK
    public string $f_ver30 = ''; // Suket Sehat
    public string $f_ver28 = ''; // MOOC
    public string $f_ver29 = ''; // SKP

    // Properti Modal Catatan Revisi
    public bool $modalCatatan = false;
    public string $judulCatatan = '';
    public string $isiCatatan = '';

    // Mapping jenis berkas -> kolom & label
    public array $jenisBerkasMap = [
        'ijazah'    => ['cat' => 'cat2',  'label' => 'Ijazah'],
        'transkrip' => ['cat' => 'cat3',  'label' => 'Transkrip'],
        'ktpkk'     => ['cat' => 'cat11', 'label' => 'KK'],
        'skpppk'    => ['cat' => 'cat17', 'label' => 'SK PPPK'],
        'suket'     => ['cat' => 'cat30', 'label' => 'Suket Sehat'],
        'mooc'      => ['cat' => 'cat28', 'label' => 'MOOC'],
        'skp'       => ['cat' => 'cat29', 'label' => 'SKP'],
    ];

    // Pilihan Status di Dropdown Drawer
    public array $statusFileOptions = [
        ['id' => 'valid', 'name' => 'Valid'],
        ['id' => 'belum_valid', 'name' => 'Belum Valid'],
        ['id' => 'revisi', 'name' => 'Revisi (Ada Catatan)'],
    ];

    public array $opdOptions = [
        ['id' => '67', 'name' => 'Dinas Kepemudaan dan Olahraga, Kebudayaan dan Pariwisata'],
        ['id' => '27', 'name' => 'Dinas Perpustakaan dan Kearsipan'],
        ['id' => '23', 'name' => 'Dinas Pendidikan'],
        ['id' => '68', 'name' => 'Dinas Kesehatan, Pengendalian Pendudukan dan KB'],
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

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    // Ganti total method showCatatan lama
    public function showCatatan(string $nip, string $jenis): void
    {
        if (!isset($this->jenisBerkasMap[$jenis])) {
            return;
        }

        $map = $this->jenisBerkasMap[$jenis];

        $row = DB::connection('kantor')->table('tbpppk')
            ->where('nip', $nip)
            ->select('nama', $map['cat'])
            ->first();

        if (!$row) {
            return;
        }

        $this->judulCatatan = "Catatan {$map['label']} ($row->nama)";
        $this->isiCatatan   = $row->{$map['cat']} ?? '';
        $this->modalCatatan = true;
    }

    // Cek apakah ada filter tabel yang aktif
    public function getIsFilteredProperty(): bool
    {
        return $this->opdFilter !== '' ||
            $this->f_ver2 !== '' || $this->f_ver3 !== '' ||
            $this->f_ver11 !== '' || $this->f_ver17 !== '' ||
            $this->f_ver28 !== '' || $this->f_ver29 !== '' ||
            $this->f_ver30 !== '';
    }

    public function applyFilter(): void
    {
        $this->resetPage();
        $this->drawerFilter = false;
    }

    public function resetFilter(): void
    {
        $this->opdFilter = '';
        $this->f_ver2 = '';
        $this->f_ver3 = '';
        $this->f_ver11 = '';
        $this->f_ver17 = '';
        $this->f_ver28 = '';
        $this->f_ver29 = '';
        $this->f_ver30 = '';

        $this->resetPage();
        $this->drawerFilter = false;
    }

    public function with(): array
    {
        $targetNips = DB::table('target_nips')->pluck('nip')->toArray();
        if (empty($targetNips)) { $targetNips = ['KOSONG']; }
        $connection = 'kantor';

        // 1. QUERY STATISTIK
        $statsQuery = DB::connection($connection)->table('tbpppk')
            ->whereIn('tbpppk.nip', $targetNips);

        if ($this->opdFilter !== '') {
            $statsQuery->join('v_pegawai_lengkap', 'tbpppk.nip', '=', 'v_pegawai_lengkap.nip')
                ->join('satuan_kerja as sk', 'v_pegawai_lengkap.kodesatker', '=', 'sk.kode_satuan_kerja')
                ->where('sk.kode_satuan_kerja', 'like', $this->opdFilter . '%');
        }

        $stats = $statsQuery->selectRaw("
                SUM(CASE WHEN tbpppk.tgl_submit IS NOT NULL THEN 1 ELSE 0 END) as total_submit,
                SUM(CASE WHEN tbpppk.tgl_submit IS NULL THEN 1 ELSE 0 END) as total_belum_submit,

                SUM(CASE WHEN tbpppk.ver2=1 AND tbpppk.ver3=1 AND tbpppk.ver11=1 AND tbpppk.ver17=1 AND tbpppk.ver28=1 AND tbpppk.ver29=1 AND tbpppk.ver30=1 THEN 1 ELSE 0 END) as total_verif_lengkap,
                SUM(CASE WHEN tbpppk.ver2=1 AND tbpppk.ver3=1 AND tbpppk.ver11=1 AND tbpppk.ver17=1 AND tbpppk.ver28=1 AND tbpppk.ver29=1 AND tbpppk.ver30=1 THEN 0 ELSE 1 END) as total_verif_belum_lengkap,

                SUM(CASE WHEN tbpppk.ver28=1 THEN 1 ELSE 0 END) as total_mooc_diverif,
                SUM(CASE WHEN tbpppk.ver29=1 THEN 1 ELSE 0 END) as total_skp_diverif,
                SUM(CASE WHEN tbpppk.ver28=1 THEN 0 ELSE 1 END) as total_mooc_belum_diverif,
                SUM(CASE WHEN tbpppk.ver29=1 THEN 0 ELSE 1 END) as total_skp_belum_diverif,

                SUM(
                    (CASE WHEN tbpppk.ver2=1 THEN 1 ELSE 0 END) +
                    (CASE WHEN tbpppk.ver3=1 THEN 1 ELSE 0 END) +
                    (CASE WHEN tbpppk.ver11=1 THEN 1 ELSE 0 END) +
                    (CASE WHEN tbpppk.ver17=1 THEN 1 ELSE 0 END) +
                    (CASE WHEN tbpppk.ver28=1 THEN 1 ELSE 0 END) +
                    (CASE WHEN tbpppk.ver29=1 THEN 1 ELSE 0 END) +
                    (CASE WHEN tbpppk.ver30=1 THEN 1 ELSE 0 END)
                ) as total_berkas_diverif,

                SUM(
                    (CASE WHEN tbpppk.ver2=1 THEN 0 ELSE 1 END) +
                    (CASE WHEN tbpppk.ver3=1 THEN 0 ELSE 1 END) +
                    (CASE WHEN tbpppk.ver11=1 THEN 0 ELSE 1 END) +
                    (CASE WHEN tbpppk.ver17=1 THEN 0 ELSE 1 END) +
                    (CASE WHEN tbpppk.ver28=1 THEN 0 ELSE 1 END) +
                    (CASE WHEN tbpppk.ver29=1 THEN 0 ELSE 1 END) +
                    (CASE WHEN tbpppk.ver30=1 THEN 0 ELSE 1 END)
                ) as total_berkas_belum_diverif,

                SUM(
                    (CASE WHEN tbpppk.cat2 IS NOT NULL AND tbpppk.cat2 != '' THEN 1 ELSE 0 END) +
                    (CASE WHEN tbpppk.cat3 IS NOT NULL AND tbpppk.cat3 != '' THEN 1 ELSE 0 END) +
                    (CASE WHEN tbpppk.cat11 IS NOT NULL AND tbpppk.cat11 != '' THEN 1 ELSE 0 END) +
                    (CASE WHEN tbpppk.cat17 IS NOT NULL AND tbpppk.cat17 != '' THEN 1 ELSE 0 END) +
                    (CASE WHEN tbpppk.cat28 IS NOT NULL AND tbpppk.cat28 != '' THEN 1 ELSE 0 END) +
                    (CASE WHEN tbpppk.cat29 IS NOT NULL AND tbpppk.cat29 != '' THEN 1 ELSE 0 END) +
                    (CASE WHEN tbpppk.cat30 IS NOT NULL AND tbpppk.cat30 != '' THEN 1 ELSE 0 END)
                ) as total_berkas_revisi
            ")
            ->first();

        // 2. QUERY TABEL DATA
        $data = DB::connection($connection)->table('tbpppk')
            ->whereIn('tbpppk.nip', $targetNips)
            ->join('v_pegawai_lengkap', 'tbpppk.nip', '=', 'v_pegawai_lengkap.nip')
            ->join('satuan_kerja as sk', 'v_pegawai_lengkap.kodesatker', '=', 'sk.kode_satuan_kerja')
            ->leftJoin('satuan_kerja as parent_sk', 'sk.parent_kode', '=', 'parent_sk.kode_satuan_kerja')
            ->select(
                'tbpppk.nip', 'tbpppk.nama',
                'tbpppk.ver2', 'tbpppk.cat2', 'tbpppk.ver3', 'tbpppk.cat3',
                'tbpppk.ver11', 'tbpppk.cat11', 'tbpppk.ver17', 'tbpppk.cat17',
                'tbpppk.ver28', 'tbpppk.cat28', 'tbpppk.ver29', 'tbpppk.cat29',
                'tbpppk.ver30', 'tbpppk.cat30',
                'sk.satuan_kerja as nama_satker',
                'parent_sk.satuan_kerja as nama_parent_satker'
            )
            ->when($this->search, function ($query) {
                $query->where(function($q) {
                    $q->where('tbpppk.nip', 'like', '%' . $this->search . '%')
                        ->orWhere('tbpppk.nama', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->opdFilter !== '', function ($query) {
                $query->where('sk.kode_satuan_kerja', 'like', $this->opdFilter . '%');
            });

        // Terapkan Dinamis 7 Filter Berkas
        $fileFilters = [
            ['val' => $this->f_ver2, 'ver' => 'tbpppk.ver2', 'cat' => 'tbpppk.cat2'],
            ['val' => $this->f_ver3, 'ver' => 'tbpppk.ver3', 'cat' => 'tbpppk.cat3'],
            ['val' => $this->f_ver11, 'ver' => 'tbpppk.ver11', 'cat' => 'tbpppk.cat11'],
            ['val' => $this->f_ver17, 'ver' => 'tbpppk.ver17', 'cat' => 'tbpppk.cat17'],
            ['val' => $this->f_ver30, 'ver' => 'tbpppk.ver30', 'cat' => 'tbpppk.cat30'],
            ['val' => $this->f_ver28, 'ver' => 'tbpppk.ver28', 'cat' => 'tbpppk.cat28'],
            ['val' => $this->f_ver29, 'ver' => 'tbpppk.ver29', 'cat' => 'tbpppk.cat29'],
        ];

        foreach ($fileFilters as $f) {
            if ($f['val'] === 'valid') {
                $data->where($f['ver'], 1);
            } elseif ($f['val'] === 'belum_valid') {
                $data->where(function($q) use ($f) {
                    $q->where($f['ver'], '!=', 1)->orWhereNull($f['ver']);
                });
            } elseif ($f['val'] === 'revisi') {
                $data->whereNotNull($f['cat'])->where($f['cat'], '!=', '');
            }
        }

        $data = $data->paginate(10);

        $headers = [
            ['key' => 'pegawai', 'label' => 'NIP & Nama', 'class' => 'w-64'],
            ['key' => 'satker', 'label' => 'Satuan Kerja'],
            ['key' => 'berkas_ijazah', 'label' => 'Ijazah', 'class' => 'text-center'],
            ['key' => 'berkas_transkrip', 'label' => 'Transkrip', 'class' => 'text-center'],
            ['key' => 'berkas_str', 'label' => 'KTP/KK', 'class' => 'text-center'],
            ['key' => 'berkas_skpppk', 'label' => 'SK PPPK', 'class' => 'text-center'],
            ['key' => 'berkas_suket', 'label' => 'Suket Sehat', 'class' => 'text-center'],
            ['key' => 'berkas_mooc', 'label' => 'MOOC', 'class' => 'text-center'],
            ['key' => 'berkas_skp', 'label' => 'SKP', 'class' => 'text-center'],
        ];

        return [
            'stats' => $stats,
            'data' => $data,
            'headers' => $headers,
        ];
    }

    private function getBadge(?string $ver, ?string $cat): array
    {
        if ($ver === '1') { return ['text' => 'Valid', 'class' => 'badge-success', 'icon' => 'o-check-circle']; }
        if (!empty($cat)) { return ['text' => 'Revisi', 'class' => 'badge-warning', 'icon' => 'o-exclamation-triangle']; }
        return ['text' => 'Belum Valid', 'class' => 'badge-error', 'icon' => 'o-x-circle'];
    }
};
?>

<div>
    <div class="mb-6">
        <x-header title="Rekapitulasi Verifikasi Berkas" subtitle="Pantau progres verifikasi PPPK Paruh Waktu" size="text-2xl" separator progress-indicator />
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-stat title="Target Submit" value="{{ number_format($stats->total_submit ?? 0) }}" icon="o-users" class="bg-base-100">
            <x-slot:description>
                <div class="mt-1 pt-1 border-t border-gray-200">
                    <span class="text-xs font-semibold text-gray-500">Lihat Belum Submit: {{ number_format($stats->total_belum_submit ?? 0) }}</span>
                </div>
            </x-slot:description>
        </x-stat>
        <x-stat title="Verifikasi Tuntas" value="{{ number_format($stats->total_verif_lengkap ?? 0) }}" icon="o-check-badge" class="bg-base-100">
            <x-slot:description>
                <div class="mt-1 pt-1 border-t border-gray-200">
                    <span class="text-xs font-semibold text-gray-500">Belum Tuntas: {{ number_format($stats->total_verif_belum_lengkap ?? 0) }}</span>
                </div>
            </x-slot:description>
        </x-stat>
        <x-stat title="Total Berkas Valid" value="{{ number_format($stats->total_berkas_diverif ?? 0) }}" icon="o-document-check" class="bg-base-100">
            <x-slot:description>
                <div class="mt-1 pt-1 border-t border-gray-200">
                    <span class="text-xs font-semibold text-gray-500">Belum Valid: {{ number_format($stats->total_berkas_belum_diverif ?? 0) }}</span>
                </div>
            </x-slot:description>
        </x-stat>
        <x-stat title="Total Butuh Revisi" value="{{ number_format($stats->total_berkas_revisi ?? 0) }}" icon="o-pencil-square" class="bg-base-100">
            <x-slot:description>
                <div class="mt-1 pt-1 border-t border-gray-200">
                    <span class="text-xs font-semibold text-gray-500">Berkas ditolak dan diberi catatan</span>
                </div>
            </x-slot:description>
        </x-stat>
    </div>

    <x-card>
        <div class="mb-4 flex flex-col md:flex-row gap-4 justify-between items-center">
            <div class="flex gap-2 w-full md:w-1/2">
                <x-input placeholder="Cari NIP" wire:model.live.debounce.500ms="search" icon="o-magnifying-glass" clearable class="grow" />

                <x-button label="Filter Data" icon="o-funnel" wire:click="$set('drawerFilter', true)" class="btn-primary relative">
                    @if($this->isFiltered)
                        <span class="absolute -top-1 -right-1 flex h-3 w-3">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                        </span>
                    @endif
                </x-button>
            </div>

            @if($this->isFiltered)
                <x-button label="Hapus Semua Filter" icon="o-x-mark" wire:click="resetFilter" class="btn-sm btn-error" />
            @endif
        </div>

        <x-table :headers="$headers" :rows="$data" with-pagination striped hover class="text-sm">

            @scope('cell_pegawai', $row)
            <div>
                <div class="font-bold">{{ $row->nama }}</div>
                <div class="text-xs text-gray-500">{{ $row->nip }}</div>
            </div>
            @endscope

            @scope('cell_satker', $row)
            <div>
                <div class="font-semibold">{{ $row->nama_satker }}</div>
                @if($row->nama_parent_satker)
                    <div class="text-xs text-gray-500">{{ $row->nama_parent_satker }}</div>
                @endif
            </div>
            @endscope

            @scope('cell_berkas_ijazah', $row)
            @php $badge = $this->getBadge($row->ver2, $row->cat2); @endphp
            <div class="flex justify-center" title="{{ $badge['text'] === 'Revisi' ? 'Revisi (Klik lihat catatan)' : $badge['text'] }}">
                @if($badge['text'] === 'Revisi')
                    <div wire:click="showCatatan('{{ $row->nip }}', 'ijazah')" class="cursor-pointer hover:scale-110 hover:opacity-80 transition-all">
                        <x-badge :class="$badge['class']" :icon="$badge['icon']" />
                    </div>
                @else
                    <x-badge :class="$badge['class']" :icon="$badge['icon']" />
                @endif
            </div>
            @endscope

            @scope('cell_berkas_transkrip', $row)
            @php $badge = $this->getBadge($row->ver3, $row->cat3); @endphp
            <div class="flex justify-center" title="{{ $badge['text'] === 'Revisi' ? 'Revisi (Klik lihat catatan)' : $badge['text'] }}">
                @if($badge['text'] === 'Revisi')
                    <div wire:click="showCatatan('{{ $row->nip }}', 'transkrip')" class="cursor-pointer hover:scale-110 hover:opacity-80 transition-all">
                        <x-badge :class="$badge['class']" :icon="$badge['icon']" />
                    </div>
                @else
                    <x-badge :class="$badge['class']" :icon="$badge['icon']" />
                @endif
            </div>
            @endscope

            @scope('cell_berkas_str', $row)
            @php $badge = $this->getBadge($row->ver11, $row->cat11); @endphp
            <div class="flex justify-center" title="{{ $badge['text'] === 'Revisi' ? 'Revisi (Klik lihat catatan)' : $badge['text'] }}">
                @if($badge['text'] === 'Revisi')
                    <div wire:click="showCatatan('{{ $row->nip }}', 'ktpkk')" class="cursor-pointer hover:scale-110 hover:opacity-80 transition-all">
                        <x-badge :class="$badge['class']" :icon="$badge['icon']" />
                    </div>
                @else
                    <x-badge :class="$badge['class']" :icon="$badge['icon']" />
                @endif
            </div>
            @endscope

            @scope('cell_berkas_skpppk', $row)
            @php $badge = $this->getBadge($row->ver17, $row->cat17); @endphp
            <div class="flex justify-center" title="{{ $badge['text'] === 'Revisi' ? 'Revisi (Klik lihat catatan)' : $badge['text'] }}">
                @if($badge['text'] === 'Revisi')
                    <div wire:click="showCatatan('{{ $row->nip }}', 'skpppk')" class="cursor-pointer hover:scale-110 hover:opacity-80 transition-all">
                        <x-badge :class="$badge['class']" :icon="$badge['icon']" />
                    </div>
                @else
                    <x-badge :class="$badge['class']" :icon="$badge['icon']" />
                @endif
            </div>
            @endscope

            @scope('cell_berkas_suket', $row)
            @php $badge = $this->getBadge($row->ver30, $row->cat30); @endphp
            <div class="flex justify-center" title="{{ $badge['text'] === 'Revisi' ? 'Revisi (Klik lihat catatan)' : $badge['text'] }}">
                @if($badge['text'] === 'Revisi')
                    <div wire:click="showCatatan('{{ $row->nip }}', 'suket')" class="cursor-pointer hover:scale-110 hover:opacity-80 transition-all">
                        <x-badge :class="$badge['class']" :icon="$badge['icon']" />
                    </div>
                @else
                    <x-badge :class="$badge['class']" :icon="$badge['icon']" />
                @endif
            </div>
            @endscope

            @scope('cell_berkas_mooc', $row)
            @php $badge = $this->getBadge($row->ver28, $row->cat28); @endphp
            <div class="flex justify-center" title="{{ $badge['text'] === 'Revisi' ? 'Revisi (Klik lihat catatan)' : $badge['text'] }}">
                @if($badge['text'] === 'Revisi')
                    <div wire:click="showCatatan('{{ $row->nip }}', 'mooc')" class="cursor-pointer hover:scale-110 hover:opacity-80 transition-all">
                        <x-badge :class="$badge['class']" :icon="$badge['icon']" />
                    </div>
                @else
                    <x-badge :class="$badge['class']" :icon="$badge['icon']" />
                @endif
            </div>
            @endscope

            @scope('cell_berkas_skp', $row)
            @php $badge = $this->getBadge($row->ver29, $row->cat29); @endphp
            <div class="flex justify-center" title="{{ $badge['text'] === 'Revisi' ? 'Revisi (Klik lihat catatan)' : $badge['text'] }}">
                @if($badge['text'] === 'Revisi')
                    <div wire:click="showCatatan('{{ $row->nip }}', 'skp')" class="cursor-pointer hover:scale-110 hover:opacity-80 transition-all">
                        <x-badge :class="$badge['class']" :icon="$badge['icon']" />
                    </div>
                @else
                    <x-badge :class="$badge['class']" :icon="$badge['icon']" />
                @endif
            </div>
            @endscope
        </x-table>
    </x-card>

    <x-drawer wire:model="drawerFilter" title="Filter Spesifik Tabel" right class="w-11/12 lg:w-1/3 p-4">

        <div class="space-y-4 mt-4">
            <x-select
                    label="Berdasarkan OPD / Unit Kerja"
                    wire:model="opdFilter"
                    :options="$opdOptions"
                    option-value="id"
                    option-label="name"
                    placeholder="--- Semua OPD ---"
                    placeholder-value=""
                    icon="o-building-office-2"
            />

            <hr class="my-4 border-gray-200" />
            <div class="text-sm font-semibold text-gray-500 mb-2">Berdasarkan Status dari 7 Berkas:</div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <x-select label="Ijazah" wire:model="f_ver2" :options="$statusFileOptions" option-value="id" option-label="name" placeholder="Semua Status" />
                <x-select label="Transkrip" wire:model="f_ver3" :options="$statusFileOptions" option-value="id" option-label="name" placeholder="Semua Status" />
                <x-select label="KTP/KK" wire:model="f_ver11" :options="$statusFileOptions" option-value="id" option-label="name" placeholder="Semua Status" />
                <x-select label="SK PPPK" wire:model="f_ver17" :options="$statusFileOptions" option-value="id" option-label="name" placeholder="Semua Status" />
                <x-select label="Suket Sehat" wire:model="f_ver30" :options="$statusFileOptions" option-value="id" option-label="name" placeholder="Semua Status" />
                <x-select label="MOOC" wire:model="f_ver28" :options="$statusFileOptions" option-value="id" option-label="name" placeholder="Semua Status" />
                <x-select label="SKP" wire:model="f_ver29" :options="$statusFileOptions" option-value="id" option-label="name" placeholder="Semua Status" />
            </div>
        </div>

        <x-slot:actions>
            <div class="flex gap-2 justify-end w-full">
                <x-button label="Reset" wire:click="resetFilter" icon="o-arrow-path" class="btn-ghost text-red-500" />
                <x-button label="Terapkan" wire:click="applyFilter" icon="o-check" class="btn-primary" />
            </div>
        </x-slot:actions>
    </x-drawer>

    <x-modal wire:model="modalCatatan" :title="$judulCatatan" class="backdrop-blur-sm">
        {{ $isiCatatan }}
        <x-slot:actions>
            <x-button label="Tutup" wire:click="$set('modalCatatan', false)" class="btn-primary" />
        </x-slot:actions>
    </x-modal>
</div>
