<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class TripPhotoDownloadController extends Controller
{
    public function downloadAll(Trip $trip): BinaryFileResponse|Response
    {
        $photos = $trip->photos()
            ->with(['tripCheckpoint'])
            ->orderBy('trip_checkpoints.occurred_at', 'asc')
            ->orderBy('trip_photos.id', 'asc')
            ->get();

        if ($photos->isEmpty()) {
            abort(404, 'Chuyến đi này chưa có ảnh nào.');
        }

        $zipFileName = 'chuyen_di_'.Str::slug($trip->trip_code, '_').'_photos.zip';
        $tempFile = tempnam(sys_get_temp_dir(), 'trip_zip_');

        if ($tempFile === false) {
            abort(500, 'Không thể tạo file tạm để nén ảnh.');
        }

        $zip = new ZipArchive;
        $openResult = $zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($openResult !== true) {
            abort(500, 'Không thể khởi tạo file zip.');
        }

        $disk = Storage::disk('public');
        $addedCount = 0;
        foreach ($photos as $index => $photo) {
            if (empty($photo->photo_path)) {
                continue;
            }

            if (! $disk->exists($photo->photo_path)) {
                continue;
            }

            $diskPath = $disk->path($photo->photo_path);
            if (! file_exists($diskPath) || filesize($diskPath) === 0) {
                continue;
            }

            $orderNumber = sprintf('%02d', $index + 1);
            $typeLabel = $photo->tripCheckpoint?->checkpoint_type?->getLabel() ?? 'moc';
            $typeSlug = Str::slug($typeLabel, '_');
            $timeString = $photo->tripCheckpoint?->occurred_at
                ? $photo->tripCheckpoint->occurred_at->format('Ymd_Hi')
                : ($photo->created_at ? $photo->created_at->format('Ymd_Hi') : 'notime');
            $extension = pathinfo($diskPath, PATHINFO_EXTENSION) ?: 'jpg';

            $entryName = "{$orderNumber}_{$typeSlug}_{$timeString}_id{$photo->id}.{$extension}";

            $zip->addFile($diskPath, $entryName);
            $addedCount++;
        }

        $zip->close();

        if ($addedCount === 0) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            abort(404, 'Không có file ảnh hợp lệ nào trên hệ thống để tải xuống.');
        }

        return response()->download($tempFile, $zipFileName, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }
}
