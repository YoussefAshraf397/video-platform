// Package contracts embeds the JSON Schemas for messages exchanged between the Laravel API
// and Go services (ADR-005) and validates messages against them.
package contracts

import (
	"bytes"
	"embed"
	"fmt"
	"io/fs"
	"strings"

	"github.com/santhosh-tekuri/jsonschema/v6"
)

// BaseURI is the $id prefix of every schema. It is an identifier, not a URL that is fetched.
const BaseURI = "https://schemas.video-platform.internal/"

//go:embed schemas/*.json
var schemaFiles embed.FS

// Validator checks messages against the embedded schemas. It is safe for concurrent use.
type Validator struct {
	schemas map[string]*jsonschema.Schema
}

// NewValidator compiles every embedded schema.
func NewValidator() (*Validator, error) {
	files, err := fs.ReadDir(schemaFiles, "schemas")
	if err != nil {
		return nil, err
	}

	compiler := jsonschema.NewCompiler()
	for _, f := range files {
		data, err := schemaFiles.ReadFile("schemas/" + f.Name())
		if err != nil {
			return nil, err
		}
		doc, err := jsonschema.UnmarshalJSON(bytes.NewReader(data))
		if err != nil {
			return nil, fmt.Errorf("parse %s: %w", f.Name(), err)
		}
		if err := compiler.AddResource(BaseURI+f.Name(), doc); err != nil {
			return nil, fmt.Errorf("add %s: %w", f.Name(), err)
		}
	}

	v := &Validator{schemas: make(map[string]*jsonschema.Schema, len(files))}
	for _, f := range files {
		schema, err := compiler.Compile(BaseURI + f.Name())
		if err != nil {
			return nil, fmt.Errorf("compile %s: %w", f.Name(), err)
		}
		v.schemas[strings.TrimSuffix(f.Name(), ".json")] = schema
	}
	return v, nil
}

// Validate checks a raw JSON message against a schema, named by its file name without
// ".json", e.g. "media-process-requested.v1".
func (v *Validator) Validate(schema string, message []byte) error {
	s, ok := v.schemas[schema]
	if !ok {
		return fmt.Errorf("unknown schema %q", schema)
	}
	instance, err := jsonschema.UnmarshalJSON(bytes.NewReader(message))
	if err != nil {
		return fmt.Errorf("message is not valid JSON: %w", err)
	}
	return s.Validate(instance)
}
