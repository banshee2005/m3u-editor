<?php

namespace App\Jobs;

use App\Enums\DvrRecordingStatus;
use App\Models\DvrRecording;
use App\Services\DvrRecorderService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * StartDvrRecording — Spawn an ffmpeg process for a scheduled recording.
 *
 * ShouldBeUnique prevents the scheduler from queuing a second start job for
 * the same recording while one is already pending or processing, removing the
 * need to pre-transition the recording's status before the job runs.
 */
class StartDvrRecording implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * How many total start attempts are allowed before the recording fails.
     * Transient provider failures (an HDHomeRun tuner momentarily busy, a
     * pooled provider connection settling) can clear within seconds — a
     * recording that succeeds on attempt 2 or 3 is still a win.
     */
    public const int MAX_START_ATTEMPTS = 3;

    /**
     * Delay between retry attempts.
     */
    public const int RETRY_DELAY_SECONDS = 15;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly int $recordingId)
    {
        $this->onQueue('dvr');
    }

    public function uniqueId(): string
    {
        return (string) $this->recordingId;
    }

    public function handle(DvrRecorderService $recorder): void
    {
        $recording = DvrRecording::with(['dvrSetting.playlist', 'user', 'channel'])->find($this->recordingId);

        if (! $recording) {
            Log::warning("StartDvrRecording: recording {$this->recordingId} not found");
            $this->fail(new \Exception("StartDvrRecording: recording {$this->recordingId} not found"));

            return;
        }

        // Guard: If the recording was cancelled (or otherwise moved out of Scheduled)
        // between when this job was dispatched and now, do not start it.
        if ($recording->status !== DvrRecordingStatus::Scheduled) {
            Log::info("StartDvrRecording: recording {$this->recordingId} is no longer Scheduled (status={$recording->status->value}) — skipping start", [
                'recording_id' => $this->recordingId,
            ]);

            return;
        }

        try {
            $recorder->start($recording);
        } catch (Throwable $e) {
            // Transient provider failures (e.g. an HDHomeRun tuner momentarily
            // busy with a lingering session or another client) can clear
            // within seconds. Retry a bounded number of times while the
            // programme is still on air instead of failing the recording
            // outright.
            $attempt = ($recording->attempt_count ?? 0) + 1;
            $stillOnAir = $recording->scheduled_end && $recording->scheduled_end->isAfter(now());

            if ($attempt < self::MAX_START_ATTEMPTS && $stillOnAir) {
                $recording->update([
                    'attempt_count' => $attempt,
                ]);

                Log::warning('StartDvrRecording: attempt '.$attempt.' failed — retrying in '.self::RETRY_DELAY_SECONDS.'s', [
                    'recording_id' => $this->recordingId,
                    'error' => $e->getMessage(),
                ]);

                self::dispatch($recording->id)
                    ->onQueue('dvr')
                    ->delay(now()->addSeconds(self::RETRY_DELAY_SECONDS));

                return;
            }

            Log::error("StartDvrRecording: recording {$this->recordingId} failed to start — {$e->getMessage()}");

            $recording->update([
                'status' => DvrRecordingStatus::Failed->value,
                'error_message' => $e->getMessage(),
                'attempt_count' => $attempt,
            ]);

            $recording->notifyTv(__('Recording Failed'), 'danger');

            if ($user = $recording->user) {
                Notification::make()
                    ->danger()
                    ->title('Recording Failed to Start')
                    ->body($recording->title)
                    ->broadcast($user)
                    ->sendToDatabase($user);
            }
        }
    }
}
