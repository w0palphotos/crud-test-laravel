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

        // The one that cost the most time to find, because it was a code with a
        // perfectly good name sitting in the response and nothing looked for it.
        //
        // A bucket with owner-enforced ownership — the default everywhere since
        // 2023 — accepts only the bucket-owner-full-control canned ACL, or none at
        // all. "private" and "public-read" are both refused, and the refusal says
        // so in a code nobody was reading.
        'AccessControlListNotSupported' => 'Bucket menolak header ACL. Supabase dan AWS dengan ownership bucket-owner-enforced hanya menerima bucket-owner-full-control.',

        // The region is part of the signature, so a wrong one fails here rather
        // than as a plain signature mismatch.
        'AuthorizationHeaderMalformed' => 'Region AWS tidak cocok dengan project ini. Salin ulang Region dari Storage → S3 Configuration.',
        'InvalidRequest' => 'Storage menolak permintaan ini. Baca detailnya di bawah.',
    ];

    /**
     * The canned ACL sent with every upload.
     *
     * Not a choice between "public" and "private" — a bucket with owner-enforced
     * ownership accepts neither, and it is the default everywhere since 2023. This
     * is the one value that is legal there, and it is right on older buckets too.
     *
     * It says who owns the object, not who may read it: the bucket owner already
     * has full control, and whether the file can be read without a login is still
     * the bucket's own setting. So this grants nothing that the public bucket was
     * not already granting.
     */
    private const ACL = 'bucket-owner-full-control';

    /**
     * The options a remote disk cannot work without, and the environment variable
     * each one comes from.
     *
     * @var array<string, string>
     */
    public const REQUIRED_OPTIONS = [
        'key' => 'AWS_ACCESS_KEY_ID',
        'secret' => 'AWS_SECRET_ACCESS_KEY',
        'bucket' => 'AWS_BUCKET',
        'endpoint' => 'AWS_ENDPOINT',
        'region' => 'AWS_DEFAULT_REGION',
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
        $this->assertConfigured();

        try {
            return Storage::disk(config('images.disk'))->putFileAs(
                config('images.directory'),
                $file,
                $name,
                ['params' => ['ACL' => self::ACL]],
            );
        } catch (Throwable $e) {
            throw new RuntimeException($this->explain($e), previous: $e);
        }
    }

    /**
     * The reason a write cannot even be attempted.
     *
     * The storage client does not say "your key is missing". It falls through its
     * credential chain — the environment, the shared file, the container service —
     * and asks the instance metadata service on the local network, which is not
     * there, and reports that instead. The message names a service the reader has
     * never heard of, says nothing about the variables that are actually missing,
     * and takes a second to time out first.
     *
     * So the check is made here, where the answer can name the variable. And it is
     * made against the configuration the application resolved, not against the
     * dashboard: a value can be present in the dashboard and still be absent here,
     * which is a distinction worth keeping.
     */
    private function assertConfigured(): void
    {
        $disk = config('filesystems.disks.'.config('images.disk'));

        // A local disk needs no credentials, and a test that swaps in a faked one
        // needs none either.
        if (! is_array($disk) || ($disk['driver'] ?? null) !== 's3') {
            return;
        }

        $missing = [];

        foreach (self::REQUIRED_OPTIONS as $option => $variable) {
            if (empty($disk[$option])) {
                $missing[] = $variable;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Storage belum dikonfigurasi di server: '.implode(', ', $missing).'. '
                .'Periksa Environment Variables di Vercel untuk environment Production, lalu deploy ulang — '
                .'container yang sudah jalan tidak menerima environment baru.',
            );
        }
    }

    /**
     * The reason, in a sentence.
     *
     * The storage client's own error names the code and nothing else, so the code
     * is looked for in the chain of causes.
     *
     * A code with no sentence of its own is still reported, with the client's own
     * message beside it. That message is the most useful thing in the response —
     * it says which region it expected, which bucket it wanted, what it was told
     * to do differently — and discarding it in favour of a general sentence is how
     * a storage error that named itself exactly ended up as a mystery.
     */
    private function explain(Throwable $e): string
    {
        $code = null;

        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            $code ??= $this->errorCode($cause);

            if ($code !== null) {
                break;
            }
        }

        if ($code !== null && isset(self::EXPLANATIONS[$code])) {
            return self::EXPLANATIONS[$code];
        }

        $raw = $this->redact($e->getMessage());

        // The credential chain, in the client's own words.
        //
        // It has run out of places to look for a key and is asking the instance
        // metadata service on the local network, which is not there. That is the
        // client's way of saying "there is no key", and the name of the service is
        // the least useful part of the sentence — the reader has never heard of
        // it, and the two variables that are actually missing are nowhere in it.
        if (str_contains($raw, 'instance profile metadata')) {
            return 'Kredensial AWS tidak terbaca di server. Periksa AWS_ACCESS_KEY_ID dan AWS_SECRET_ACCESS_KEY.';
        }

        $detail = $this->shorten($raw);

        if ($code !== null) {
            return 'Storage menolak: '.$code.($detail === '' ? '.' : ' '.$detail);
        }

        if ($detail !== '') {
            return $detail;
        }

        return 'Gambar gagal disimpan. Periksa kredensial Storage, nama bucket, dan apakah bucket tersebut publik.';
    }

    /**
     * The client's own message, made safe to send to a browser.
     *
     * The message can name the endpoint, the bucket and the region, all of which
     * are the reader's own configuration and none of which are secret. What must
     * never leave the server is a credential, and the client's message does not
     * carry one — but that is a property of a library rather than a promise this
     * code can make, so the configured key and secret are removed from the text
     * before it goes anywhere.
     */
    private function redact(string $message): string
    {
        foreach ($this->secrets() as $secret) {
            if ($secret !== '' && $secret !== null) {
                $message = str_replace($secret, '***', $message);
            }
        }

        return $message;
    }

    /**
     * The message, on one line and within a length a person will read.
     */
    private function shorten(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');

        if (strlen($message) > 300) {
            $message = substr($message, 0, 300).'…';
        }

        return $message;
    }

    /**
     * The credentials this disk is configured with, if any.
     *
     * @return list<string|null>
     */
    private function secrets(): array
    {
        $disk = config('filesystems.disks.'.config('images.disk'));

        if (! is_array($disk)) {
            return [];
        }

        return [$disk['key'] ?? null, $disk['secret'] ?? null];
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
