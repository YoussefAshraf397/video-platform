package results

import "testing"

func TestEventIDIsStablePerJobAndMessage(t *testing.T) {
	job := Job{VideoID: "0199b1f0-1111-7aaa-8bbb-0c0c0c0c0c01", JobID: "0199b1f2-2222-7ccc-8ddd-0e0e0e0e0e01", ProcessingVersion: 1}

	if EventID(job, "completed") != EventID(job, "completed") {
		t.Error("the same job and message must get the same id on every run")
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
