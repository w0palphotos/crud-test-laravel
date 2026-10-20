<?php

namespace App\Actions;

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

        $variables = [];

        foreach (StoreUploadedImage::REQUIRED_OPTIONS as $option => $variable) {
            $inEnvironment = getenv($variable) !== false;

            $variables[$variable] = [
                // Whether the process environment holds the variable at all, which
                // is what the storage client looks for: it reads getenv() and
                // nothing else. A value the dashboard has and the process does
                // not is a value no client will ever find.
                'inEnvironment' => $inEnvironment,

                // Whether the application resolved a usable value from it. The two
                // can disagree, and when they do the application is blind to a
                // credential the client can still use.
                //
                // Not empty(): PHP treats the string "0" as empty, and a bucket
                // or a key may legitimately be named that. The check is the same
                // one the write path uses, so the endpoint and the refusal cannot
                // disagree about what "present" means.
                'configured' => is_string($disk[$option] ?? null) && $disk[$option] !== '',

                // Which is what the client will do: it takes the value the
                // application hands it, and falls back to the process environment
                // when the application has none. So either place is enough.
                'usable' => (is_string($disk[$option] ?? null) && $disk[$option] !== '') || $inEnvironment,
            ];
        }

        $usable = true;

        foreach ($variables as $state) {
            $usable = $usable && $state['usable'];
        }

        return [
            'disk' => $name,
            'driver' => $disk['driver'] ?? null,
            'usable' => $usable,

            'variables' => $variables,

            // Resolved as the application resolved them, because a name that
            // looks right in the dashboard can still be a different string here.
            'bucket' => $disk['bucket'] ?? null,
            'endpoint' => $disk['endpoint'] ?? null,
            'publicUrl' => $disk['url'] ?? null,
            'region' => $disk['region'] ?? null,
            'pathStyle' => $disk['use_path_style_endpoint'] ?? null,

            'directory' => config('images.directory'),
            'acl' => 'bucket-owner-full-control',
        ];
    }
}
