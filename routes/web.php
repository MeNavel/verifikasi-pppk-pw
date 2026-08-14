<?php

//Route::livewire('/', 'pages::users.index');
Route::livewire('/', 'pages::verifikasi-berkas');
Route::livewire('/rekap', 'pages::rekap-verifikasi');
Route::livewire('/mooc', 'pages::mooc');
Route::livewire('/skp', 'pages::skp');
Route::livewire('/import', 'pages::import-no_hp');

Route::get('/preview-pdf/{username}/{filename}', function ($username, $filename) {
    $disk = Storage::disk('sftp_kantor');
    $path = "$username/$filename";
    if (!$disk->exists($path)) {
        abort(404, "File PDF tidak ditemukan: " . $path);
    }
    return response()->stream(function () use ($disk, $path) {
        $stream = $disk->readStream($path);
        fpassthru($stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
    }, 200, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'inline; filename="' . $filename . '"',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
    ]);
})->name('pdf.sftp.preview');
