<?php

namespace App\Support;

use DateTimeImmutable;

/**
 * What is running, and when it was built.
 *
 * A deployment is an immutable artifact: the environment it was given is fixed
 * when it is built and does not shift afterwards. So the first question about a
 * missing variable is not "is it in the dashboard" but "was the dashboard
 * changed before or after this deployment was built" — and that is a question
 * about time, which the application can answer about itself.
 *
 * It can, because the built asset manifest is written by the build and copied
 * into the image with its timestamp intact, so its modification time is when the
 * image was built. The commit and the environment come from Vercel's own
 * variables.
 */
final class Deployment
{
    /**
     * When the image was built, in UTC.
     *
     * ISO 8601 with the zone spelled out, because the container's clock is UTC
     * and the dashboard's is the reader's own — and a time without its zone is a
     * time that will be compared wrongly.
     */
    public static function builtAt(): ?string
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            return null;
        }

        $built = @filemtime($manifest);

        if ($built === false) {
            return null;
        }

        return (new DateTimeImmutable('@'.$built))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * How long ago the image was built.
     *
     * The age rather than the time, because the age is the comparison that does
     * not need a timezone converted: "the deployment is three hours old and the
     * variable was added two minutes ago" settles it without either side being
     * turned into the other's clock.
     */
    public static function builtAge(): ?string
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            return null;
        }

        $built = @filemtime($manifest);

        if ($built === false) {
            return null;
        }

        $seconds = time() - $built;

        if ($seconds < 0) {
            // A manifest from the future means the clock moved; the age would be
            // a negative number, which reads as a fault that is not there.
            return '0 menit lalu';
        }

        return self::duration($seconds);
    }

    /**
     * The commit the deployment was built from, if the platform told us.
     */
    public static function commit(): ?string
    {
        $commit = getenv('VERCEL_GIT_COMMIT_SHA');

        return is_string($commit) && $commit !== '' ? $commit : null;
    }

    /**
     * Which environment is running, if the platform told us.
     */
    public static function environment(): ?string
    {
        $environment = getenv('VERCEL_ENV');

        return is_string($environment) && $environment !== '' ? $environment : null;
    }

    /**
     * One sentence, for a log line or an error message.
     */
    public static function summary(): string
    {
        $parts = [];

        if (($builtAt = self::builtAt()) !== null) {
            $parts[] = 'dibangun '.$builtAt;
        }

        if (($age = self::builtAge()) !== null) {
            $parts[] = $age;
        }

        if (($commit = self::commit()) !== null) {
            $parts[] = 'commit '.substr($commit, 0, 7);
        }

        if (($environment = self::environment()) !== null) {
            $parts[] = $environment;
        }

        return $parts === [] ? '' : 'Deployment ini '.implode(', ', $parts).'.';
    }

    /**
     * A duration, in the largest unit that still says something.
     */
    private static function duration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return $minutes.' menit lalu';
        }

        $hours = intdiv($minutes, 60);

        if ($hours < 24) {
            $remainder = $minutes % 60;

            return $hours.' jam'.($remainder === 0 ? '' : ' '.$remainder.' menit').' lalu';
        }

        $days = intdiv($hours, 24);

        return $days.' hari lalu';
    }
}
