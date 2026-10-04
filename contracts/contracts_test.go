package contracts

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// Schemas that are building blocks rather than messages, so they have no example.
var nonMessageSchemas = map[string]bool{"common.v1": true, "envelope.v1": true}

func newValidator(t *testing.T) *Validator {
	t.Helper()
	v, err := NewValidator()
	if err != nil {
		t.Fatalf("compile schemas: %v", err)
	}
	return v
}

func readExample(t *testing.T, name string) map[string]any {
	t.Helper()
	data, err := os.ReadFile(filepath.Join("examples", name+".json"))
	if err != nil {
		t.Fatal(err)
	}
	var msg map[string]any
	if err := json.Unmarshal(data, &msg); err != nil {
		t.Fatal(err)
	}
	return msg
}

func messageSchemas(t *testing.T) []string {
	t.Helper()
	files, err := filepath.Glob("schemas/*.json")
	if err != nil {
		t.Fatal(err)
	}
	var names []string
	for _, f := range files {
		name := strings.TrimSuffix(filepath.Base(f), ".json")
		if !nonMessageSchemas[name] {
			names = append(names, name)
		}
	}
	return names
}

func TestEveryMessageSchemaHasAValidExample(t *testing.T) {
	v := newValidator(t)
	for _, name := range messageSchemas(t) {
		t.Run(name, func(t *testing.T) {
			data, err := json.Marshal(readExample(t, name))
			if err != nil {
				t.Fatal(err)
			}
			if err := v.Validate(name, data); err != nil {
				t.Errorf("example does not match its schema:\n%v", err)
			}
			if err := v.Validate("envelope.v1", data); err != nil {
				t.Errorf("example does not match the envelope:\n%v", err)
			}
		})
	}
}

func TestEveryExampleHasASchema(t *testing.T) {
	examples, err := filepath.Glob("examples/*.json")
	if err != nil {
		t.Fatal(err)
	}
	for _, f := range examples {
		if _, err := os.Stat(filepath.Join("schemas", filepath.Base(f))); err != nil {
			t.Errorf("%s has no schema with the same name", f)
		}
	}
}

// Each mutation breaks the contract in a way a producer could plausibly do by accident.
// Kept identical to the PHP test (api/tests/Unit/ContractsTest.php).
var mutations = map[string]func(msg map[string]any){
	"missing event_id":         func(m map[string]any) { delete(m, "event_id") },
	"unknown envelope field":   func(m map[string]any) { m["unexpected"] = true },
	"wrong event_type":         func(m map[string]any) { m["event_type"] = "SomethingElse" },
	"string aggregate_version": func(m map[string]any) { m["aggregate_version"] = "5" },
	"missing payload video_id": func(m map[string]any) { delete(m["payload"].(map[string]any), "video_id") },
	"unknown payload field":    func(m map[string]any) { m["payload"].(map[string]any)["unexpected"] = 1 },
}

func TestSchemasRejectIncompatibleMessages(t *testing.T) {
	v := newValidator(t)
	for _, name := range messageSchemas(t) {
		for mutation, mutate := range mutations {
			t.Run(name+"/"+mutation, func(t *testing.T) {
				msg := readExample(t, name)
				mutate(msg)
				data, err := json.Marshal(msg)
				if err != nil {
					t.Fatal(err)
				}
				if v.Validate(name, data) == nil {
					t.Errorf("schema accepted a message with %s", mutation)
				}
			})
		}
	}
}

func TestUnknownSchemaIsAnError(t *testing.T) {
	if newValidator(t).Validate("does-not-exist.v1", []byte(`{}`)) == nil {
		t.Error("expected an error for an unknown schema")
	}
}
