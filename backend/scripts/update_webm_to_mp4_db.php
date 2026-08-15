<?php

// Quick script to update webm -> mp4 in media_files table
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\MediaFile;
use Illuminate\Contracts\Console\Kernel;

$records = MediaFile::where('filename', 'LIKE', '%.webm')
    ->orWhere('path', 'LIKE', '%.webm')
    ->get();

echo "Found {$records->count()} webm records\n";

foreach ($records as $r) {
    $oldFilename = $r->filename;
    $oldPath = $r->path;
    $oldUrl = $r->url;

    $r->filename = str_replace('.webm', '.mp4', $r->filename);
    $r->path = str_replace('.webm', '.mp4', $r->path);
    $r->url = str_replace('.webm', '.mp4', $r->url);
    $r->mime_type = 'video/mp4';
    $r->save();

    echo "  {$oldFilename} -> {$r->filename}\n";
}

echo "\nDone. Updated {$records->count()} records.\n";
