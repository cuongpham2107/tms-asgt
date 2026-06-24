<?php

namespace App\Services\Trip;

use App\Models\TripCheckpoint;
use App\Models\TripPhoto;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class TripPhotoAttacher
{
    /**
     * Lưu ảnh và gắn vào tất cả checkpoints được cung cấp.
     *
     * @param  Collection<int, TripCheckpoint>  $checkpoints
     * @param  UploadedFile[]  $files
     */
    public function attach(Collection $checkpoints, array $files): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store('trip_photos', 'public');
            $url = $disk->url($path);

            foreach ($checkpoints as $checkpoint) {
                TripPhoto::create([
                    'trip_checkpoint_id' => $checkpoint->id,
                    'photo_path' => $path,
                    'photo_url' => $url,
                ]);
            }
        }
    }
}
