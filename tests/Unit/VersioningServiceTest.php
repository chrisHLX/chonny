<?php

namespace Tests\Unit;

use App\Http\Services\VersioningService;
use Tests\TestCase;

class VersioningServiceTest extends TestCase
{
    /** @test */
    public function it_increments_simple_versions()
    {
        $this->assertEquals('V2', VersioningService::next('V1', null));
        $this->assertEquals('V3', VersioningService::next('V2', null));
    }

    /** @test */
    public function it_adds_letter_suffix_if_no_digit()
    {
        $this->assertEquals('V1A', VersioningService::next('V1', 'V1'));
        $this->assertEquals('V3A', VersioningService::next('V3', 'V3'));
    }

    /** @test */
    public function it_adds_digit_to_letter_suffix()
    {
        $this->assertEquals('V1A1', VersioningService::next('V1', 'V1A'));
        $this->assertEquals('V1A2', VersioningService::next('V1', 'V1A1'));
    }

    /** @test */
    public function it_handles_multiple_letters()
    {
        $this->assertEquals('V2B', VersioningService::next('V2', 'V2A'));
        $this->assertEquals('V2B1', VersioningService::next('V2', 'V2B'));
    }

    /** @test */
    public function it_falls_back_safely()
    {
        $this->assertEquals('V5A', VersioningService::next('V5', 'weird_value'));
    }
}
