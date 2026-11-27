<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ProfilePhotoService
{
    /**
     * Validasi binary gambar, auto-rotate EXIF kamera, crop 1:1 maks 512x512 px,
     * konversi ke format WebP ringan, lalu hapus foto profil lama dari disk.
     */
    public function storeAndOptimize(UploadedFile $file, User $user): string
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('Ekstensi PHP GD belum aktif di server.');
        }

        $realPath = $file->getRealPath();
        if (! $realPath || ! is_readable($realPath)) {
            throw new RuntimeException('File sementara unggahan tidak dapat dibaca.');
        }

        $rawBinary = file_get_contents($realPath);
        if ($rawBinary === false || $rawBinary === '') {
            throw new RuntimeException('Gagal membaca file gambar yang diunggah.');
        }

        $sourceImage = @imagecreatefromstring($rawBinary);
        if (! ($sourceImage instanceof \GdImage)) {
            throw new RuntimeException('Isi file bukan gambar yang valid atau file rusak.');
        }

        // 1. Perbaiki rotasi otomatis foto portrait dari kamera HP (EXIF)
        $sourceImage = $this->fixExifOrientation($sourceImage, $file, $realPath);

        $origWidth  = imagesx($sourceImage);
        $origHeight = imagesy($sourceImage);

        if ($origWidth <= 0 || $origHeight <= 0) {
            imagedestroy($sourceImage);
            throw new RuntimeException('Dimensi gambar tidak valid.');
        }

        // 2. Center-crop persegi (1:1) & perkecil maksimal 512x512 px
        $cropSize   = min($origWidth, $origHeight);
        $srcX       = (int) floor(($origWidth - $cropSize) / 2);
        $srcY       = (int) floor(($origHeight - $cropSize) / 2);
        $targetSize = min($cropSize, 512);

        $canvas = imagecreatetruecolor($targetSize, $targetSize);
        if (! ($canvas instanceof \GdImage)) {
            imagedestroy($sourceImage);
            throw new RuntimeException('Gagal membuat canvas gambar di server.');
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $targetSize, $targetSize, $transparent);

        $resized = imagecopyresampled(
            $canvas,
            $sourceImage,
            0,
            0,
            $srcX,
            $srcY,
            $targetSize,
            $targetSize,
            $cropSize,
            $cropSize
        );

        imagedestroy($sourceImage);

        if (! $resized) {
            imagedestroy($canvas);
            throw new RuntimeException('Gagal melakukan crop/resize gambar.');
        }

        // 3. Konversi & kompres ke format WebP (fallback ke JPEG jika WebP tidak tersedia)
        ob_start();
        if (function_exists('imagewebp')) {
            $encoded = @imagewebp($canvas, null, 80);
            $extension = 'webp';
        } else {
            $encoded = @imagejpeg($canvas, null, 82);
            $extension = 'jpg';
        }
        $optimizedBinary = ob_get_clean();

        imagedestroy($canvas);

        if (! $encoded || $optimizedBinary === false || $optimizedBinary === '') {
            throw new RuntimeException('Gagal mengonversi foto profil di server.');
        }

        // 4. Simpan ke storage/app/public/profiles/
        $relativePath = sprintf('profiles/user_%d_%d.%s', $user->id, time(), $extension);
        $saved = Storage::disk('public')->put($relativePath, $optimizedBinary);

        if (! $saved) {
            throw new RuntimeException('Gagal menyimpan file foto profil ke penyimpanan server.');
        }

        // 5. Hapus foto profil lama jika ada agar ruang server tetap bersih
        if (! empty($user->foto) && $user->foto !== $relativePath && Storage::disk('public')->exists($user->foto)) {
            Storage::disk('public')->delete($user->foto);
        }

        return $relativePath;
    }

    private function fixExifOrientation(\GdImage $image, UploadedFile $file, string $realPath): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $mime = strtolower((string) $file->getMimeType());
        if (! in_array($mime, ['image/jpeg', 'image/jpg', 'image/pjpeg'], true)) {
            return $image;
        }

        $exif = @exif_read_data($realPath);
        if (! is_array($exif)) {
            return $image;
        }

        $orientation = (int) ($exif['Orientation'] ?? 1);
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $bgColor = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $rotated = @imagerotate($image, $angle, $bgColor ?: 0);

        if ($rotated instanceof \GdImage) {
            imagesavealpha($rotated, true);
            imagedestroy($image);
            return $rotated;
        }

        return $image;
    }
}