package results

import "testing"

func TestEventIDIsStablePerJobAndMessage(t *testing.T) {
	job := Job{VideoID: "0199b1f0-1111-7aaa-8bbb-0c0c0c0c0c01", JobID: "0199b1f2-2222-7ccc-8ddd-0e0e0e0e0e01", ProcessingVersion: 1}

	// Pinned: if this changes, a job retried by a newer worker would re-send results under new
	// IDs, and consumers would process them twice.
	if got := EventID(job, "completed"); got != "5750a445-04e7-5635-9f61-4ba82ccb6221" {
		t.Errorf("EventID changed: %s", got)
	}
	other := job
	other.ProcessingVersion = 2
	seen := map[string]bool{}
	for _, id := range []string{
		EventID(job, "completed"), EventID(job, "failed"), EventID(job, "rendition:a"), EventID(job, "rendition:b"),
		EventID(other, "completed"), EventID(Job{JobID: "0199b1f2-2222-7ccc-8ddd-0e0e0e0e0e02", ProcessingVersion: 1}, "completed"),
	} {
		if seen[id] {
			t.Errorf("collision: %s", id)
		}
		seen[id] = true
	}
}
