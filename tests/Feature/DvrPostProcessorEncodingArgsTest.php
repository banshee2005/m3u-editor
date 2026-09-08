<?php

/**
 * Tests for DvrPostProcessorService::resolveEncodingArgs().
 *
 * The DVR concat step re-encodes recordings when a transcoding stream profile
 * is assigned to the playlist's DvrSetting, and otherwise stream-copies. This
 * covers the extraction of FFmpeg encoding args from a profile's args template
 * and the stream-copy fallbacks.
 */

declare(strict_types=1);

use App\Models\DvrSetting;
use App\Models\StreamProfile;
use App\Services\DvrPostProcessorService;

function resolveArgs(?DvrSetting $setting): array
{
    $service = app(DvrPostProcessorService::class);
    $method = new ReflectionMethod($service, 'resolveEncodingArgs');
    $method->setAccessible(true);

    return $method->invoke($service, $setting);
}

it('falls back to stream copy when no profile is assigned', function (): void {
    $setting = DvrSetting::factory()->create(['stream_profile_id' => null]);

    expect(resolveArgs($setting))->toBe(['-c', 'copy']);
});

it('falls back to stream copy when the setting is null', function (): void {
    expect(resolveArgs(null))->toBe(['-c', 'copy']);
});

it('falls back to stream copy for a non-ffmpeg (streamlink) profile', function (): void {
    $profile = StreamProfile::factory()->streamlink()->create();
    $setting = DvrSetting::factory()->create(['stream_profile_id' => $profile->id]);

    expect(resolveArgs($setting))->toBe(['-c', 'copy']);
});

it('falls back to stream copy when the profile has empty args', function (): void {
    $profile = StreamProfile::factory()->create(['backend' => 'ffmpeg', 'args' => '']);
    $setting = DvrSetting::factory()->create(['stream_profile_id' => $profile->id]);

    expect(resolveArgs($setting))->toBe(['-c', 'copy']);
});

it('extracts encoding args from the OTA DVR profile template', function (): void {
    $profile = StreamProfile::factory()->create([
        'backend' => 'ffmpeg',
        'format' => 'mp4',
        'args' => '-vf yadif=1:-1:0 -c:v libx264 -preset {preset|veryfast} -crf {crf|23} -pix_fmt yuv420p -c:a aac -b:a {audio_bitrate|128k}',
    ]);
    $setting = DvrSetting::factory()->create(['stream_profile_id' => $profile->id]);

    expect(resolveArgs($setting))->toBe([
        '-vf', 'yadif=1:-1:0',
        '-c:v', 'libx264',
        '-preset', 'veryfast',
        '-crf', '23',
        '-pix_fmt', 'yuv420p',
        '-c:a', 'aac',
        '-b:a', '128k',
    ]);
});

it('strips proxy input/output/format args from a live streaming profile template', function (): void {
    $profile = StreamProfile::factory()->create([
        'backend' => 'ffmpeg',
        'format' => 'ts',
        'args' => '-fflags +genpts -i {input_url} -max_muxing_queue_size 1024 -vf yadif=1:-1:0 -c:v libx264 -preset superfast -b:v {bitrate|2000k} -maxrate {maxrate|2500k} -bufsize {bufsize|5000k} -fps_mode vfr -profile:v high -level 41 -c:a aac -b:a {audio_bitrate|128k} -ac 2 -f mpegts {output_args|pipe:1}',
    ]);
    $setting = DvrSetting::factory()->create(['stream_profile_id' => $profile->id]);

    $args = resolveArgs($setting);

    // Input/output/format flags and their values are gone.
    expect($args)->not->toContain('-i')
        ->and($args)->not->toContain('input_url')
        ->and($args)->not->toContain('-f')
        ->and($args)->not->toContain('mpegts')
        ->and($args)->not->toContain('pipe:1')
        ->and($args)->not->toContain('-fflags')
        ->and($args)->not->toContain('+genpts')
        ->and($args)->not->toContain('-max_muxing_queue_size')
        ->and($args)->not->toContain('-fps_mode')
        ->and($args)->not->toContain('vfr');

    // Encoding flags are preserved.
    expect($args)->toBe([
        '-vf', 'yadif=1:-1:0',
        '-c:v', 'libx264',
        '-preset', 'superfast',
        '-b:v', '2000k',
        '-maxrate', '2500k',
        '-bufsize', '5000k',
        '-profile:v', 'high',
        '-level', '41',
        '-c:a', 'aac',
        '-b:a', '128k',
        '-ac', '2',
    ]);
});
