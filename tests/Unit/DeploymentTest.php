<?php

namespace Tests\Unit;

use App\Support\Deployment;
use Tests\TestCase;

/**
 * What is running, and when it was built.
 *
 * A deployment's environment is fixed when it is built, so the question about a
 * missing variable is a question about time. The application can answer it about
 * itself, and that is what this covers.
 */
class DeploymentTest extends TestCase
{
    public function test_it_reports_when_the_image_was_built(): void
    {
        $builtAt = Deployment::builtAt();

        $this->assertNotNull($builtAt, 'The built manifest is the marker of when the image was built.');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $builtAt);
    }

    /**
     * The zone is spelled out because the container's clock is UTC and the
     * dashboard's is the reader's own.
     */
    public function test_the_build_time_names_its_zone(): void
    {
        $this->assertStringEndsWith('Z', (string) Deployment::builtAt());
    }

    /**
     * The age is the comparison that needs no timezone converted.
     */
    public function test_the_age_is_readable_and_never_negative(): void
    {
        $age = Deployment::builtAge();

        $this->assertNotNull($age);
        $this->assertMatchesRegularExpression('/^\d+ (menit|jam|hari)( \d+ menit)? lalu$/', $age);
    }

    /**
     * What the platform did not say is reported as nothing, not as a guess.
     */
    public function test_it_reports_nothing_where_the_platform_said_nothing(): void
    {
        $this->assertNull(Deployment::commit());
        $this->assertNull(Deployment::environment());
    }

    /**
     * And the summary carries what there is, and no more.
     */
    public function test_the_summary_names_the_build_and_the_age(): void
    {
        $summary = Deployment::summary();

        $this->assertStringContainsString('dibangun', $summary);
        $this->assertStringContainsString('lalu', $summary);
    }
}
