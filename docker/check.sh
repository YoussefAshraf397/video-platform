#!/bin/sh
# Verifies every local service is reachable and the AWS resources exist (`make check`).
set -eu

COMPOSE_FILE="$(dirname "$0")/compose.yaml"

compose() {
  docker compose -f "$COMPOSE_FILE" "$@"
}
fail=0

check() {
  label="$1"
  shift
  if "$@" >/dev/null 2>&1; then
    echo "  ok    $label"
  else
    echo "  FAIL  $label"
    fail=1
  fi
}

aws_cli() {
  compose run --rm --no-deps --entrypoint aws aws-init "$@"
}

queue_with_dlq_exists() {
  aws_cli sqs get-queue-url --queue-name "$1" && aws_cli sqs get-queue-url --queue-name "$1-dlq"
}

topic_exists() {
  aws_cli sns list-topics --output text | grep -q ":$1\$"
}

echo "Checking local stack:"
check "postgres: videoplatform"      compose exec -T postgres psql -U videoplatform -d videoplatform -c 'select 1'
check "postgres: videoplatform_test" compose exec -T postgres psql -U videoplatform -d videoplatform_test -c 'select 1'
check "redis"                        compose exec -T redis redis-cli ping
check "mailpit UI (port ${MAILPIT_UI_PORT:-8025})" curl -fsS "http://localhost:${MAILPIT_UI_PORT:-8025}/api/v1/info"
for bucket in uploads media images; do
  check "s3 bucket: $bucket"         aws_cli s3api head-bucket --bucket "$bucket"
done
for queue in media-process media-results s3-upload-events media-dispatcher; do
  check "sqs queue: $queue (+dlq)"   queue_with_dlq_exists "$queue"
done
check "sns topic: video-events"      topic_exists video-events

if [ "$fail" -ne 0 ]; then
  echo "Some checks failed. See \`make logs\`."
  exit 1
fi
echo "All checks passed."
