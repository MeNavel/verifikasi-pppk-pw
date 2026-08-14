<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use Spatie\SimpleExcel\SimpleExcelReader;
use Mary\Traits\Toast;

new class extends Component {
    use WithFileUploads, Toast;

    public $file;

    public function import()
    {
        // 1. Validasi File Excel
        $this->validate([
            'file' => 'required|file|mimes:xlsx,csv|max:10240', // Maksimal 10MB
        ]);

        try {
            // 2. Ambil ekstensi asli (wajib di Livewire agar file temporary .tmp terbaca sebagai Excel)
            $extension = $this->file->getClientOriginalExtension();
            $path = $this->file->getRealPath();

            // 3. Baca baris file Excel
            $rows = SimpleExcelReader::create($path, $extension)->getRows();

            $updatedCount = 0;
            $skippedCount = 0;

            foreach ($rows as $row) {
                // Ambil nilai dari kolom header Excel
                $nip  = trim($row['NIP Baru'] ?? '');
                $noHp = trim($row['Nomor HP'] ?? '');

                if (!empty($nip) && !empty($noHp)) {
                    // Update kolom no_hp HANYA jika NIP tersebut sudah ada di tabel target_nips
                    $affected = DB::table('target_nips')
                        ->where('nip', $nip)
                        ->update([
                            'no_hp'      => $noHp,
                            'updated_at' => now(),
                        ]);

                    if ($affected > 0) {
                        $updatedCount++;
                    } else {
                        // Jika NIP di Excel tidak ditemukan di DB
                        $skippedCount++;
                    }
                }
            }

            // 4. Tampilkan pesan feedbackToast
            $message = "Berhasil memperbarui $updatedCount nomor HP pada NIP terdaftar.";
            if ($skippedCount > 0) {
                $message .= " ($skippedCount NIP di Excel tidak ditemukan di database).";
            }

            $this->success($message, timeout: 6000);
            $this->reset('file');

        } catch (\Exception $e) {
            $this->error('Gagal mengimport data: Pastikan format file dan header Excel sesuai. ' . $e->getMessage());
        }
    }
}; ?>

<div>
    <x-header title="Import Nomor HP" subtitle="Update Nomor HP untuk NIP yang sudah terdaftar di database" separator />

    <x-card class="max-w-2xl mx-auto shadow-xl mt-6">

        <x-alert icon="o-information-circle" class="alert-info mb-6">
            Pastikan header kolom pada file Excel Anda menggunakan nama:
            <ul class="list-disc list-inside mt-1 font-semibold">
                <li>NIP Baru</li>
                <li>Nomor HP</li>
            </ul>
        </x-alert>

        <x-form wire:submit="import">

            <x-file
                    wire:model="file"
                    label="Pilih File Excel (.xlsx / .csv)"
                    hint="Mendukung format .xlsx dan .csv hingga 10MB"
                    accept=".xlsx, .csv"
            />

            <x-slot:actions>
                <x-button label="Batal" wire:click="reset('file')" class="btn-ghost" />
                <x-button
                        label="Update Nomor HP"
                        type="submit"
                        class="btn-primary text-white"
                        icon="o-arrow-up-tray"
                        spinner="import"
                />
            </x-slot:actions>
        </x-form>

    </x-card>
</div>
