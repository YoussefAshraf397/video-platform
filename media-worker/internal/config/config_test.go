package config

import (
	"log/slog"
	"os"
	"strings"
	"testing"
	"time"
)

func env(values map[string]string) func(string) string {
	return func(key string) string { return values[key] }
}

func TestDefaults(t *testing.T) {
	cfg, err := Load(env(nil))
	if err != nil {
		t.Fatal(err)
	}
	want := Config{
		ProcessQueue:      "media-process",
		ResultsQueue:      "media-results",
		ScratchDir:        os.TempDir(),
		MaxAttempts:       3,
		X264Preset:        "veryfast",
		Concurrency:       1,
		VisibilityTimeout: 5 * time.Minute,
		HeartbeatInterval: time.Minute,
		ShutdownGrace:     90 * time.Second,
		LogLevel:          slog.LevelInfo,
	}
	if cfg != want {
		t.Errorf("got %+v, want %+v", cfg, want)
	}
}

func TestOverrides(t *testing.T) {
	cfg, err := Load(env(map[string]string{
		"MEDIA_PROCESS_QUEUE": "other",
		"WORKER_CONCURRENCY":  "2",
		"VISIBILITY_TIMEOUT":  "10m",
		"HEARTBEAT_INTERVAL":  "2m",
		"SHUTDOWN_GRACE":      "30s",
		"LOG_LEVEL":           "debug",
		"MEDIA_RESULTS_QUEUE": "results",
		"SCRATCH_DIR":         "/scratch",
		"MAX_ATTEMPTS":        "2",
		"X264_PRESET":         "medium",
	}))
	if err != nil {
		t.Fatal(err)
	}
	if cfg.ProcessQueue != "other" || cfg.Concurrency != 2 || cfg.VisibilityTimeout != 10*time.Minute ||
		cfg.HeartbeatInterval != 2*time.Minute || cfg.ShutdownGrace != 30*time.Second || cfg.LogLevel != slog.LevelDebug ||
		cfg.ResultsQueue != "results" || cfg.ScratchDir != "/scratch" || cfg.MaxAttempts != 2 || cfg.X264Preset != "medium" {
		t.Errorf("overrides not applied: %+v", cfg)
	}
}

func TestInvalidValuesAreAllReported(t *testing.T) {
	_, err := Load(env(map[string]string{
		"WORKER_CONCURRENCY": "0",
		"SHUTDOWN_GRACE":     "soon",
		"LOG_LEVEL":          "loud",
		"VISIBILITY_TIMEOUT": "1m",
		"HEARTBEAT_INTERVAL": "45s",
		"MAX_ATTEMPTS":       "5",
	}))
	if err == nil {
		t.Fatal("expected an error")
	}
	for _, want := range []string{"WORKER_CONCURRENCY", "SHUTDOWN_GRACE", "LOG_LEVEL", "HEARTBEAT_INTERVAL", "MAX_ATTEMPTS"} {
		if !strings.Contains(err.Error(), want) {
			t.Errorf("error does not mention %s: %v", want, err)
		}
	}
}
