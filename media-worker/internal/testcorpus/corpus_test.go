package testcorpus

import (
	"os"
	"testing"
)

func TestCorpusDocIsUpToDate(t *testing.T) {
	want := Markdown()
	if os.Getenv("UPDATE_CORPUS_DOC") != "" {
		if err := os.WriteFile("CORPUS.md", []byte(want), 0o600); err != nil {
			t.Fatal(err)
		}
		return
	}
	got, err := os.ReadFile("CORPUS.md")
	if err != nil || string(got) != want {
		t.Fatal("CORPUS.md is out of date: run UPDATE_CORPUS_DOC=1 go test ./internal/testcorpus/")
	}
}

func TestSampleNamesAreUnique(t *testing.T) {
	seen := map[string]bool{}
	for _, s := range Samples {
		if seen[s.Name] {
			t.Errorf("duplicate sample %s", s.Name)
		}
		seen[s.Name] = true
	}
}

func TestEveryAcceptedSampleDeclaresItsLadder(t *testing.T) {
	for _, s := range Samples {
		if s.Expect.Rejection == "" && len(s.Expect.MVPLadder) == 0 {
			t.Errorf("%s: accepted samples must declare MVPLadder", s.Name)
		}
	}
}

func TestEverySampleIsGenerated(t *testing.T) {
	for _, s := range Samples {
		info, err := os.Stat(Path(t, s.Name))
		if err != nil || info.Size() == 0 {
			t.Errorf("%s was not generated: %v", s.Name, err)
		}
	}
}
