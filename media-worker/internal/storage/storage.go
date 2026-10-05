// Package storage moves files between the worker's scratch disk and S3. Downloads stream to
// disk (sources can be 10 GB); uploads use multipart for large files and run several at once.
package storage

import (
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"sync"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/feature/s3/transfermanager"
	tmtypes "github.com/aws/aws-sdk-go-v2/feature/s3/transfermanager/types"
	"github.com/aws/aws-sdk-go-v2/service/s3"
	"github.com/aws/aws-sdk-go-v2/service/s3/types"
)

// ErrNotFound means the object doesn't exist.
var ErrNotFound = errors.New("object not found")

// Cache policies (ADR-004): paths are versioned and never change, except the master playlist,
// which is rewritten as renditions become ready.
const (
	CacheImmutable = "public, max-age=31536000, immutable"
	CacheMaster    = "public, max-age=60"
)

// Store reads sources and writes outputs.
type Store struct {
	client   *s3.Client
	uploader *transfermanager.Client
	// Parallel is how many files Upload sends at once.
	Parallel int
}

// New creates a Store.
func New(client *s3.Client) *Store {
	return &Store{client: client, uploader: transfermanager.New(client), Parallel: 8}
}

// Download streams an object to a local file.
func (s *Store) Download(ctx context.Context, bucket, key, dest string) (err error) {
	out, err := s.client.GetObject(ctx, &s3.GetObjectInput{Bucket: aws.String(bucket), Key: aws.String(key)})
	if err != nil {
		var noKey *types.NoSuchKey
		if errors.As(err, &noKey) {
			return fmt.Errorf("%w: s3://%s/%s", ErrNotFound, bucket, key)
		}
		return fmt.Errorf("get s3://%s/%s: %w", bucket, key, err)
	}
	defer func() { _ = out.Body.Close() }() // read to the end or failed; nothing to report

	f, err := os.Create(dest) // #nosec G304 -- a path inside the worker's own scratch directory
	if err != nil {
		return err
	}
	defer func() {
		if cerr := f.Close(); err == nil {
			err = cerr
		}
	}()
	if _, err := io.Copy(f, out.Body); err != nil {
		return fmt.Errorf("download s3://%s/%s: %w", bucket, key, err)
	}
	return nil
}

// File is one local file to upload.
type File struct {
	Path, Key, CacheControl string
}

// Upload sends files in parallel and returns once all are stored (or the first error).
func (s *Store) Upload(ctx context.Context, bucket string, files []File) error {
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()

	work := make(chan File)
	var (
		wg   sync.WaitGroup
		mu   sync.Mutex
		errs []error
	)
	for range max(1, s.Parallel) {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for f := range work {
				if err := s.put(ctx, bucket, f); err != nil {
					mu.Lock()
					errs = append(errs, err)
					mu.Unlock()
					cancel()
				}
			}
		}()
	}
	for _, f := range files {
		select {
		case work <- f:
		case <-ctx.Done():
		}
	}
	close(work)
	wg.Wait()
	return errors.Join(errs...)
}

func (s *Store) put(ctx context.Context, bucket string, f File) error {
	body, err := os.Open(f.Path)
	if err != nil {
		return err
	}
	defer func() { _ = body.Close() }() // opened read-only
	_, err = s.uploader.UploadObject(ctx, &transfermanager.UploadObjectInput{
		Bucket:       aws.String(bucket),
		Key:          aws.String(f.Key),
		Body:         body,
		ContentType:  aws.String(ContentType(f.Path)),
		CacheControl: aws.String(f.CacheControl),
		// Same encryption the uploads bucket gets (the bucket default enforces it in AWS too).
		ServerSideEncryption: tmtypes.ServerSideEncryptionAes256,
	})
	if err != nil {
		return fmt.Errorf("upload s3://%s/%s: %w", bucket, f.Key, err)
	}
	return nil
}

// ContentType is the HTTP type players and browsers expect for an output file.
func ContentType(path string) string {
	switch strings.ToLower(filepath.Ext(path)) {
	case ".m3u8":
		return "application/vnd.apple.mpegurl"
	case ".m4s":
		return "video/iso.segment"
	case ".mp4":
		return "video/mp4"
	case ".jpg":
		return "image/jpeg"
	case ".webp":
		return "image/webp"
	default:
		return "application/octet-stream"
	}
}
