<?php

namespace App\Actions;

use Illuminate\Support\Facades\Storage;

/**
 * What the application can see of its own image storage.
 *
 * A deployment that cannot write images is a deployment whose configuration is
 * somewhere else — the dashboard, the environment, the container — and the reader
 * has no way to tell which of them is wrong. This is that way: one place that
 * says what the application resolved, so a difference between the dashboard and
 * the running container is visible rather than guessed at.
 *
 * Nothing secret is reported. Whether a variable is set is a fact about the
 * configuration and not about the credential; the values themselves are never
 * read, and the endpoint and bucket are host names the reader already knows.
 */
class DescribeImageStorage
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $name = config('images.disk');
        $disk = config("filesystems.disks.{$name}");

        $disk = is_array($disk) ? $disk : [];

        $options = [];

        foreach (StoreUploadedImage::REQUIRED_OPTIONS as $option => $variable) {
            $options[$variable] = ! empty($disk[$option]);
        }

        return [
            'disk' => $name,
            'driver' => $disk['driver'] ?? null,

            // Whether each variable reached the application. The endpoint and the
            // bucket are reported as they were resolved, because a name that looks
            // right in the dashboard can still be a different string here.
            'variables' => $options,
            'bucket' => $disk['bucket'] ?? null,
            'endpoint' => $disk['endpoint'] ?? null,
            'publicUrl' => $disk['url'] ?? null,
            'region' => $disk['region'] ?? null,
            'pathStyle' => $disk['use_path_style_endpoint'] ?? null,

            'directory' => config('images.directory'),
            'acl' => 'bucket-owner-full-control',

            // Whether the disk answers at all. A local disk does; a remote one is
            // only asked, and a failure here is the same failure an upload would
            // meet, reported as a fact rather than as an exception.
            'reachable' => $this->reachable($name),
        ];
    }

    /**
     * Whether the disk answers a question about itself.
     */
    private function reachable(string $name): bool
    {
        try {
            Storage::disk($name)->files(config('images.directory'));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
