<?php

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new class extends Component {
    use WithPagination;

    public string $search = '';

    // Properti untuk menyimpan status filter aktif
    public string $filter = 'all';

    public function updatedSearch()
    {
        $this->resetPage();
    }

    // Fungsi untuk mengubah filter saat stat diklik
    public function setFilter(string $type)
    {
        // Jika stat yang sama diklik lagi, kembalikan ke 'all' (reset)
        $this->filter = ($this->filter === $type) ? 'all' : $type;
        $this->resetPage();
    }

    public function with(): array
    {
        $targetNips = DB::table('target_nips')->pluck('nip')->toArray();

        if (empty($targetNips)) {
            $targetNips = ['KOSONG'];
        }

        $connection = 'kantor';

        // 1. QUERY STATISTIK (Tetap sama, sangat cepat)
        $stats = DB::connection($connection)->table('tbpppk')
            ->whereIn('nip', $targetNips)
            ->selectRaw("
                SUM(CASE WHEN tgl_submit IS NOT NULL THEN 1 ELSE 0 END) as total_submit,
                SUM(CASE WHEN tgl_submit IS NULL THEN 1 ELSE 0 END) as total_belum_submit,
                SUM(CASE WHEN ver2=1 AND ver3=1 AND ver11=1 AND ver17=1 AND ver28=1 AND ver29=1 AND ver30=1 THEN 1 ELSE 0 END) as total_verif_lengkap,
                SUM(CASE WHEN ver2!=1 OR ver3!=1 OR ver11!=1 OR ver17!=1 OR ver28!=1 OR ver29!=1 OR ver30!=1 THEN 1 ELSE 0 END) as total_verif_belum_lengkap,
                SUM(
                    (CASE WHEN ver2=1 THEN 1 ELSE 0 END) +
                    (CASE WHEN ver3=1 THEN 1 ELSE 0 END) +
                    (CASE WHEN ver11=1 THEN 1 ELSE 0 END) +
                    (CASE WHEN ver17=1 THEN 1 ELSE 0 END) +
                    (CASE WHEN ver28=1 THEN 1 ELSE 0 END) +
                    (CASE WHEN ver29=1 THEN 1 ELSE 0 END) +
                    (CASE WHEN ver30=1 THEN 1 ELSE 0 END)
                ) as total_berkas_diverif,
                SUM(
                    (CASE WHEN ver2!=1 THEN 1 ELSE 0 END) +
                    (CASE WHEN ver3!=1 THEN 1 ELSE 0 END) +
                    (CASE WHEN ver11!=1 THEN 1 ELSE 0 END) +
                    (CASE WHEN ver17!=1 THEN 1 ELSE 0 END) +
                    (CASE WHEN ver28!=1 THEN 1 ELSE 0 END) +
                    (CASE WHEN ver29!=1 THEN 1 ELSE 0 END) +
                    (CASE WHEN ver30!=1 THEN 1 ELSE 0 END)
                ) as total_berkas_belum_diverif,
                SUM(
                    (CASE WHEN cat2 IS NOT NULL AND cat2 != '' THEN 1 ELSE 0 END) +
                    (CASE WHEN cat3 IS NOT NULL AND cat3 != '' THEN 1 ELSE 0 END) +
                    (CASE WHEN cat11 IS NOT NULL AND cat11 != '' THEN 1 ELSE 0 END) +
                    (CASE WHEN cat17 IS NOT NULL AND cat17 != '' THEN 1 ELSE 0 END) +
                    (CASE WHEN cat28 IS NOT NULL AND cat28 != '' THEN 1 ELSE 0 END) +
                    (CASE WHEN cat29 IS NOT NULL AND cat29 != '' THEN 1 ELSE 0 END) +
                    (CASE WHEN cat30 IS NOT NULL AND cat30 != '' THEN 1 ELSE 0 END)
                ) as total_berkas_revisi
            ")
            ->first();

        // 2. QUERY TABEL DATA DENGAN LOGIKA FILTER DINAMIS
        $data = DB::connection($connection)->table('tbpppk')
            ->whereIn('tbpppk.nip', $targetNips)
            ->join('v_pegawai_lengkap', 'tbpppk.nip', '=', 'v_pegawai_lengkap.nip')
            ->join('satuan_kerja as sk', 'v_pegawai_lengkap.kodesatker', '=', 'sk.kode_satuan_kerja')
            ->leftJoin('satuan_kerja as parent_sk', 'sk.parent_kode', '=', 'parent_sk.kode_satuan_kerja')
            ->select(
                'tbpppk.nip',
                'tbpppk.nama',
                'tbpppk.ver2', 'tbpppk.cat2',
                'tbpppk.ver3', 'tbpppk.cat3',
                'tbpppk.ver11', 'tbpppk.cat11',
                'tbpppk.ver17', 'tbpppk.cat17',
                'tbpppk.ver28', 'tbpppk.cat28',
                'tbpppk.ver29', 'tbpppk.cat29',
                'tbpppk.ver30', 'tbpppk.cat30',
                'sk.satuan_kerja as nama_satker',
                'parent_sk.satuan_kerja as nama_parent_satker'
            )
            // Filter Pencarian Text
            ->when($this->search, function ($query) {
                $query->where(function($q) {
                    $q->where('tbpppk.nip', 'like', '%' . $this->search . '%')
                        ->orWhere('tbpppk.nama', 'like', '%' . $this->search . '%');
                });
            })
            // Filter Berdasarkan Klik Kotak Statistik
            ->when($this->filter !== 'all', function ($query) {
                if ($this->filter === 'sudah_submit') {
                    $query->whereNotNull('tbpppk.tgl_submit');
                } elseif ($this->filter === 'belum_submit') {
                    $query->whereNull('tbpppk.tgl_submit');
                } elseif ($this->filter === 'tuntas') {
                    $query->where('tbpppk.ver2', 1)->where('tbpppk.ver3', 1)->where('tbpppk.ver11', 1)
                        ->where('tbpppk.ver17', 1)->where('tbpppk.ver28', 1)->where('tbpppk.ver29', 1)->where('tbpppk.ver30', 1);
                } elseif ($this->filter === 'belum_tuntas' || $this->filter === 'berkas_belum_valid') {
                    // Orang yang memiliki MINIMAL 1 berkas yang belum divalidasi
                    $query->where(function($q) {
                        $q->where('tbpppk.ver2', '!=', 1)->orWhere('tbpppk.ver3', '!=', 1)->orWhere('tbpppk.ver11', '!=', 1)
                            ->orWhere('tbpppk.ver17', '!=', 1)->orWhere('tbpppk.ver28', '!=', 1)->orWhere('tbpppk.ver29', '!=', 1)->orWhere('tbpppk.ver30', '!=', 1);
                    });
                } elseif ($this->filter === 'berkas_valid') {
                    // Orang yang memiliki MINIMAL 1 berkas valid
                    $query->where(function($q) {
                        $q->where('tbpppk.ver2', 1)->orWhere('tbpppk.ver3', 1)->orWhere('tbpppk.ver11', 1)
                            ->orWhere('tbpppk.ver17', 1)->orWhere('tbpppk.ver28', 1)->orWhere('tbpppk.ver29', 1)->orWhere('tbpppk.ver30', 1);
                    });
                } elseif ($this->filter === 'ada_catatan') {
                    // Orang yang memiliki MINIMAL 1 catatan (revisi)
                    $query->where(function($q) {
                        $q->where(fn($sub) => $sub->whereNotNull('tbpppk.cat2')->where('tbpppk.cat2', '!=', ''))
                            ->orWhere(fn($sub) => $sub->whereNotNull('tbpppk.cat3')->where('tbpppk.cat3', '!=', ''))
                            ->orWhere(fn($sub) => $sub->whereNotNull('tbpppk.cat11')->where('tbpppk.cat11', '!=', ''))
                            ->orWhere(fn($sub) => $sub->whereNotNull('tbpppk.cat17')->where('tbpppk.cat17', '!=', ''))
                            ->orWhere(fn($sub) => $sub->whereNotNull('tbpppk.cat28')->where('tbpppk.cat28', '!=', ''))
                            ->orWhere(fn($sub) => $sub->whereNotNull('tbpppk.cat29')->where('tbpppk.cat29', '!=', ''))
                            ->orWhere(fn($sub) => $sub->whereNotNull('tbpppk.cat30')->where('tbpppk.cat30', '!=', ''));
                    });
                }
            })
            ->paginate(10);

        $headers = [
            ['key' => 'pegawai', 'label' => 'NIP & Nama', 'class' => 'w-64'],
            ['key' => 'satker', 'label' => 'Satuan Kerja'],
            ['key' => 'berkas_ijazah', 'label' => 'Ijazah', 'class' => 'text-center'],
            ['key' => 'berkas_transkrip', 'label' => 'Transkrip', 'class' => 'text-center'],
            ['key' => 'berkas_kk', 'label' => 'KK', 'class' => 'text-center'],
            ['key' => 'berkas_skpppk', 'label' => 'SK PPPK', 'class' => 'text-center'],
            ['key' => 'berkas_mooc', 'label' => 'MOOC', 'class' => 'text-center'],
            ['key' => 'berkas_skp', 'label' => 'SKP', 'class' => 'text-center'],
            ['key' => 'berkas_suket', 'label' => 'Suket Sehat', 'class' => 'text-center'],
        ];

        return [
            'stats' => $stats,
            'data' => $data,
            'headers' => $headers
        ];
    }

    public function getBadge($ver, $cat): array
    {
        if (!empty($cat)) {
            return ['icon' => 'o-pencil-square', 'class' => 'badge-warning', 'text' => 'Revisi'];
        }
        if ($ver == 1) {
            return ['icon' => 'o-check', 'class' => 'badge-success', 'text' => ''];
        }
        return ['icon' => 'o-x-mark', 'class' => 'badge-error', 'text' => ''];
    }
};
?>

<div>
    <x-header title="Rekapitulasi Target Verifikasi (899 Pegawai)" separator progress-indicator>
        <x-slot:actions>
            <x-button icon="o-arrow-path" label="Refresh" class="btn-primary" wire:click="$refresh" spinner />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

        <x-stat
                title="Target Submit"
                value="{{ number_format($stats->total_submit ?? 0) }}"
                icon="o-paper-airplane"
                class="cursor-pointer hover:shadow-md transition-all {{ $filter === 'sudah_submit' ? 'ring-2 ring-blue-500 shadow-md' : '' }}"
                wire:click="setFilter('sudah_submit')">
            <x-slot:description>
                <div class="mt-1 pt-1 border-t border-blue-200" wire:click.stop="setFilter('belum_submit')">
                    <span class="text-xs font-semibold hover:text-blue-700 hover:underline {{ $filter === 'belum_submit' ? 'text-blue-700 underline' : 'text-gray-500' }}">
                        Lihat Belum Submit: {{ number_format($stats->total_belum_submit ?? 0) }}
                    </span>
                </div>
            </x-slot:description>
        </x-stat>

        <x-stat
                title="Target Tuntas (7 Berkas)"
                value="{{ number_format($stats->total_verif_lengkap ?? 0) }}"
                icon="o-check-badge"
                class="cursor-pointer hover:shadow-md transition-all {{ $filter === 'tuntas' ? 'ring-2 ring-green-500 shadow-md' : '' }}"
                wire:click="setFilter('tuntas')">
            <x-slot:description>
                <div class="mt-1 pt-1 border-t border-green-200" wire:click.stop="setFilter('belum_tuntas')">
                    <span class="text-xs font-semibold hover:text-green-700 hover:underline {{ $filter === 'belum_tuntas' ? 'text-green-700 underline' : 'text-gray-500' }}">
                        Lihat Belum Tuntas: {{ number_format($stats->total_verif_belum_lengkap ?? 0) }}
                    </span>
                </div>
            </x-slot:description>
        </x-stat>

        <x-stat
                title="Total Berkas Valid"
                value="{{ number_format($stats->total_berkas_diverif ?? 0) }}"
                icon="o-document-check"
                class="cursor-pointer hover:shadow-md transition-all {{ $filter === 'berkas_valid' ? 'ring-2 ring-teal-500 shadow-md' : '' }}"
                wire:click="setFilter('berkas_valid')">
            <x-slot:description>
                <div class="mt-1 pt-1 border-t border-teal-200" wire:click.stop="setFilter('berkas_belum_valid')">
                    <span class="text-xs font-semibold hover:text-teal-700 hover:underline {{ $filter === 'berkas_belum_valid' ? 'text-teal-700 underline' : 'text-gray-500' }}">
                        Lihat Belum Valid: {{ number_format($stats->total_berkas_belum_diverif ?? 0) }}
                    </span>
                </div>
            </x-slot:description>
        </x-stat>

        <x-stat
                title="Total Berkas Revisi"
                value="{{ number_format($stats->total_berkas_revisi ?? 0) }}"
                icon="o-pencil-square"
                class="cursor-pointer hover:shadow-md transition-all {{ $filter === 'ada_catatan' ? 'ring-2 ring-orange-500 shadow-md' : '' }}"
                wire:click="setFilter('ada_catatan')">
            <x-slot:description>
                <div class="mt-1 text-xs text-gray-500 pt-1 border-t border-orange-200">
                    Menampilkan yang memiliki catatan
                </div>
            </x-slot:description>
        </x-stat>
    </div>

    <div class="mb-4 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <x-input
                wire:model.live.debounce.300ms="search"
                icon="o-magnifying-glass"
                placeholder="Cari NIP atau Nama pada target..."
                class="max-w-md w-full"
                clearable />

        @if($filter !== 'all')
            <div class="flex items-center gap-2 p-2 rounded-lg">
                <span class="text-sm font-medium">Filter Aktif:</span>
                <x-badge value="{{ ucwords(str_replace('_', ' ', $filter)) }}" class="badge-primary" />
                <x-button icon="o-x-mark" label="Reset" size="sm" class="btn-ghost text-red-500" wire:click="setFilter('all')" />
            </div>
        @endif
    </div>

    <x-card>
        <x-table :headers="$headers" :rows="$data" with-pagination>

            @scope('cell_pegawai', $row)
            <div class="font-bold">{{ $row->nama }}</div>
            <div class="text-sm text-gray-500">{{ $row->nip }}</div>
            @endscope

            @scope('cell_satker', $row)
            <div class="font-semibold">{{ $row->nama_satker ?? '-' }}</div>
            <div class="text-xs text-gray-500">Parent: {{ $row->nama_parent_satker ?? '-' }}</div>
            @endscope

            @scope('cell_berkas_ijazah', $row)
            @php $badge = $this->getBadge($row->ver2, $row->cat2); @endphp
            <div class="flex justify-center">
                <x-badge :value="$badge['text']" :class="$badge['class']" :icon="$badge['icon']" />
            </div>
            @endscope

            @scope('cell_berkas_transkrip', $row)
            @php $badge = $this->getBadge($row->ver3, $row->cat3); @endphp
            <div class="flex justify-center">
                <x-badge :value="$badge['text']" :class="$badge['class']" :icon="$badge['icon']" />
            </div>
            @endscope

            @scope('cell_berkas_kk', $row)
            @php $badge = $this->getBadge($row->ver11, $row->cat11); @endphp
            <div class="flex justify-center">
                <x-badge :value="$badge['text']" :class="$badge['class']" :icon="$badge['icon']" />
            </div>
            @endscope

            @scope('cell_berkas_skpppk', $row)
            @php $badge = $this->getBadge($row->ver17, $row->cat17); @endphp
            <div class="flex justify-center">
                <x-badge :value="$badge['text']" :class="$badge['class']" :icon="$badge['icon']" />
            </div>
            @endscope

            @scope('cell_berkas_mooc', $row)
            @php $badge = $this->getBadge($row->ver28, $row->cat28); @endphp
            <div class="flex justify-center">
                <x-badge :value="$badge['text']" :class="$badge['class']" :icon="$badge['icon']" />
            </div>
            @endscope

            @scope('cell_berkas_skp', $row)
            @php $badge = $this->getBadge($row->ver29, $row->cat29); @endphp
            <div class="flex justify-center">
                <x-badge :value="$badge['text']" :class="$badge['class']" :icon="$badge['icon']" />
            </div>
            @endscope

            @scope('cell_berkas_suket', $row)
            @php $badge = $this->getBadge($row->ver30, $row->cat30); @endphp
            <div class="flex justify-center">
                <x-badge :value="$badge['text']" :class="$badge['class']" :icon="$badge['icon']" />
            </div>
            @endscope

        </x-table>
    </x-card>
</div>
