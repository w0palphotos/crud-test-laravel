<?php

namespace App\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Puts an uploaded file on the image disk and says where it landed.
 *
 * Shared by the browser's upload endpoint and the API's, because the two differ in
 * exactly two things — how the caller proves who they are, and what the reply is
 * wrapped in — and not in how a file is named, where it goes, or what the caller is
 * told afterwards. Two copies would be two places for a naming rule to change in
 * one of them only, and the files left behind by the other would be the evidence.
 *
 * The endpoint is not the trust boundary and neither is this: what the file is, is
 * checked by StoreUploadRequest before it arrives here.
 */
class StoreUploadedImage
{
    /**
     * What each storage error means, said in a sentence the person reading it can
     * act on.
     *
     * These are the failures an administrator causes by configuring a bucket, and
     * the words in the API's own error are not those words: the raw message says
     * "Error executing "PutObject" with AWS error code NoSuchBucket", which names
     * the code and leaves the reader to work out what to do about it.
     *
     * @var array<string, string>
     */
    private const EXPLANATIONS = [
        'NoSuchBucket' => 'Bucket tidak ada di project ini. Periksa AWS_BUCKET dan ref project di AWS_ENDPOINT.',
        'SignatureDoesNotMatch' => 'Kredensial ditolak. Region atau kunci S3 tidak cocok dengan project ini.',
        'InvalidAccessKeyId' => 'Access key tidak dikenal. Salin ulang kunci dari Storage → S3 Configuration.',
        'AccessDenied' => 'Akses ke bucket ditolak. Periksa kunci S3 dan kebijakan bucket.',
        'EntityTooLarge' => 'Gambar melebihi batas yang diizinkan storage.',
        'SlowDown' => 'Storage sedang sibuk. Coba lagi sebentar.',
        'ServiceUnavailable' => 'Storage sedang tidak tersedia. Coba lagi sebentar.',
    ];

    /**
     * @return array{path: string, url: string, name: string}
     *
     * @throws RuntimeException when the file did not reach the disk
     */
    public function handle(UploadedFile $file): array
    {
        // The stored name is generated, never taken from the client.
        //
        // A client-supplied filename is an attack surface with three separate
        // edges: `../../config/app.php` writes outside the directory, `image.php`
        // becomes executable wherever the server serves PHP, and a predictable
        // name lets one user's upload overwrite another's by guessing it. A UUID
        // has none of those, and it removes the "is this filename already taken"
        // question entirely.
        $extension = $file->guessExtension();

        $name = Str::uuid().($extension === null ? '' : ".{$extension}");

        $path = $this->write($file, $name);

        return [
            'path' => $path,
            'url' => Storage::disk(config('images.disk'))->url($path),
            'name' => $name,
        ];
    }

    /**
     * The write itself.
     *
     * The s3 disk is configured to raise, and that is the point: a rejected
     * credential, a bucket that does not exist and a region that does not match
     * all arrive as the storage client's own error, and that error is the only
     * thing that knows which of them it was.
     *
     * It used to be configured not to raise, which is right for a read — a missing
     * file should read as "no" and not as an exception — and wrong for a write,
     * where the adapter returned false and the caller went on to build a url from
     * that false and report success. The upload looked like it had worked, the url
     * went into the note, and the only symptom was a broken image nobody could
     * explain.
     *
     * Reads are unaffected. A key that is not there still reads as absent, because
     * the client's own existence check answers false for a 404 and raises for
     * anything else.
     */
    private function write(UploadedFile $file, string $name): string
    {
        try {
            return Storage::disk(config('images.disk'))->putFileAs(
                config('images.directory'),
                $file,
                $name,
            );
        } catch (Throwable $e) {
            throw new RuntimeException($this->explain($e), previous: $e);
        }
    }

    /**
     * The reason, in a sentence.
     *
     * The storage client's own error names the code and nothing else, so the code
     * is looked for in the chain of causes. Only the code is used, never the
     * message: the message can carry the endpoint and the bucket, and this goes
     * back to a browser.
     */
    private function explain(Throwable $e): string
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            $code = $this->errorCode($cause);

            if ($code !== null && isset(self::EXPLANATIONS[$code])) {
                return self::EXPLANATIONS[$code];
            }
        }

        return 'Gambar gagal disimpan. Periksa kredensial Storage, nama bucket, dan apakah bucket tersebut publik.';
    }

    /**
     * The storage error's own code, if this cause is one.
     *
     * The client raises its errors with the code on a method of its own; the
     * filesystem wrapper keeps the original as the previous exception, so the code
     * is one level down from what was thrown here.
     */
    private function errorCode(Throwable $e): ?string
    {
        if (! method_exists($e, 'getAwsErrorCode')) {
            return null;
        }

        $code = $e->getAwsErrorCode();

        return is_string($code) && $code !== '' ? $code : null;
    }
}
