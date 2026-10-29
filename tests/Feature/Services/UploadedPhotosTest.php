<?php

use App\Services\Images\UploadedPhotos;
use Illuminate\Http\UploadedFile;

/**
 * A JPEG with an EXIF block naming the camera, as a phone photo carries one.
 */
function photoWithExif(int $width, int $height): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 120, 160, 200));
    ob_start();
    imagejpeg($image, null, 95);
    $jpeg = (string) ob_get_clean();

    // TIFF header, then IFD0 with one entry: Make (0x010F), ASCII, "TestCam\0" stored at offset 26.
    $tiff = 'II*'."\0".pack('V', 8).pack('v', 1).pack('vvVV', 0x010F, 2, 8, 26).pack('V', 0).'TestCam'."\0";
    $exif = "Exif\0\0".$tiff;
    $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

    return UploadedFile::fake()->createWithContent('kitchen.jpeg', substr($jpeg, 0, 2).$app1.substr($jpeg, 2));
}

test('a large phone photo is turned upright, scaled down and stripped of its EXIF data', function () {
    $photo = photoWithExif(4000, 3000);
    expect(file_get_contents($photo->getRealPath()))->toContain('TestCam');

    $optimized = app(UploadedPhotos::class)->optimize($photo);
    $contents = file_get_contents($optimized->getRealPath());
    $size = getimagesizefromstring($contents);

    expect([$size[0], $size[1]])->toBe([2000, 1500])
        ->and($size['mime'])->toBe('image/jpeg')
        ->and($contents)->not->toContain('TestCam')
        ->and($optimized->getClientOriginalName())->toBe('kitchen.jpeg')
        ->and($optimized->getMimeType())->toBe('image/jpeg')
        ->and($optimized->getSize())->toBeLessThan($photo->getSize());
});

test('a picture in a format few viewers open is sent on as a JPEG', function () {
    $image = imagecreatetruecolor(300, 200);
    ob_start();
    imagebmp($image);

    $optimized = app(UploadedPhotos::class)->optimize(UploadedFile::fake()->createWithContent('scan.bmp', (string) ob_get_clean()));

    expect($optimized->getClientOriginalName())->toBe('scan.jpg')
        ->and(getimagesize($optimized->getRealPath())['mime'])->toBe('image/jpeg');
});

test('a small screenshot that would not get smaller is kept as it was', function () {
    $screenshot = UploadedFile::fake()->image('error.png', 400, 300);

    expect(app(UploadedPhotos::class)->optimize($screenshot))->toBe($screenshot);
});

test('documents, GIFs and pictures that cannot be read or are too large to decode are passed on untouched', function (UploadedFile $file) {
    expect(app(UploadedPhotos::class)->optimize($file))->toBe($file);
})->with([
    'a PDF' => fn () => UploadedFile::fake()->create('lease.pdf', 120, 'application/pdf'),
    'a GIF' => fn () => UploadedFile::fake()->image('spinner.gif', 3000, 3000),
    'a corrupt JPEG' => fn () => UploadedFile::fake()->createWithContent('broken.jpg', "\xFF\xD8\xFF\xE0".str_repeat('x', 64)),
    // Only the header: it claims 10,000 × 10,000 pixels, which would never be decoded.
    'a huge PNG' => fn () => UploadedFile::fake()->createWithContent('huge.png', "\x89PNG\r\n\x1A\n".pack('N', 13).'IHDR'.pack('NNCCCCC', 10000, 10000, 8, 2, 0, 0, 0).pack('N', 0)),
]);
