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
 * With $resume, restarts the proxy capture of an in-progress recording the proxy
 * lost instead (see DvrSchedulerService::resumeLostRecordings()).
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

    public function __construct(
        public readonly int $recordingId,
        public readonly bool $resume = false,
        public readonly bool $forceFresh = false,
        public readonly int $attempt = 1,
    ) {
        $this->onQueue('dvr');
    }

    /**
     * Attempt-scoped key: the in-handle retry dispatches the NEXT attempt
     * number, so it gets a different key and is not rejected by the
     * ShouldBeUnique lock this job still holds until handle() returns.
     * (A bare recordingId made every retry silently undispatchable.)
     */
    public function uniqueId(): string
    {
        return "{$this->recordingId}:{$this->attempt}";
    }

    public function handle(DvrRecorderService $recorder): void
    {
        $recording = DvrRecording::with(['dvrSetting.playlist', 'user', 'channel'])->find($this->recordingId);

        if (! $recording) {
            Log::warning("StartDvrRecording: recording {$this->recordingId} not found");
            $this->fail(new \Exception("StartDvrRecording: recording {$this->recordingId} not found"));

            return;
        }

        if ($this->resume) {
            $this->resumeRecording($recorder, $recording);

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
            // forceFresh on retries (attempt > 1): a failed attempt may have
            // piggybacked onto a pooled stream whose upstream went stale.
            $recorder->start($recording, forceFresh: $this->forceFresh || $this->attempt > 1);
        } catch (Throwable $e) {
            // Transient provider failures (e.g. an HDHomeRun tuner momentarily
            // busy with a lingering session or another client) can clear
            // within seconds. Retry a bounded number of times while the
            // programme is still on air instead of failing the recording
            // outright.
            //
            // $this->attempt is this job's own attempt number (1-based), so
            // MAX_START_ATTEMPTS is a true total: attempts 1..3, then fail.
            // (Reading attempt_count off the row and adding 1 double-counted
            // against a column that defaults to 1 -- only two runs happened.)
            $nextAttempt = $this->attempt + 1;
            $stillOnAir = $recording->scheduled_end && $recording->scheduled_end->isAfter(now());

            if ($nextAttempt > self::MAX_START_ATTEMPTS || ! $stillOnAir) {
                Log::error("StartDvrRecording: recording {$this->recordingId} failed to start — {$e->getMessage()}");

                $recording->update([
                    'status' => DvrRecordingStatus::Failed->value,
                    'error_message' => $e->getMessage(),
                    'attempt_count' => $this->attempt,
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

                $this->fail($e);

                return;
            }

            $recording->update([
                'attempt_count' => $nextAttempt,
            ]);

            Log::warning('StartDvrRecording: attempt '.$this->attempt.' failed — retrying as attempt '.$nextAttempt.' in '.self::RETRY_DELAY_SECONDS.'s', [
                'recording_id' => $this->recordingId,
                'error' => $e->getMessage(),
            ]);

            self::dispatch($this->recordingId, forceFresh: true, attempt: $nextAttempt)
                ->onQueue('dvr')
                ->delay(now()->addSeconds(self::RETRY_DELAY_SECONDS));

            return;
        }
    }

    /**
     * A failed resume leaves the recording as-is (it already has footage); the
     * next scheduler tick tries again until the airing window ends.
     */
    private function resumeRecording(DvrRecorderService $recorder, DvrRecording $recording): void
    {
        if ($recording->status !== DvrRecordingStatus::Recording) {
            return;
        }

        try {
            $recorder->resume($recording);
        } catch (Throwable $e) {
            Log::error("StartDvrRecording: recording {$this->recordingId} failed to resume - {$e->getMessage()}");
        }
    }
}
