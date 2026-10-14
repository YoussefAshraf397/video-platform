#!/usr/bin/env bash
# S4-05: proves in a real AWS environment that a message which can't be processed ends up in the
# DLQ and that the DLQ alarm fires. Sends one deliberately malformed MediaProcessRequested to
# media-process; the worker rejects it (poison) on every delivery, SQS moves it to
# media-process-dlq after 5 receives, and the observability module's alarm goes to ALARM.
# Afterwards it empties the DLQ, so the alarm returns to OK.
#
# Usage (with an AWS profile for the dev account, and the worker running there):
#   AWS_PROFILE=dev infra/scripts/dlq-alarm-test.sh [name-prefix]      # default: videoplatform-dev
set -euo pipefail

NAME=${1:-videoplatform-dev}
QUEUE=media-process
ALARM="$NAME-$QUEUE-dlq-not-empty"
TIMEOUT=${TIMEOUT:-900}   # SQS metrics reach CloudWatch with a few minutes' delay

queue_url=$(aws sqs get-queue-url --queue-name "$QUEUE" --query QueueUrl --output text)
dlq_url=$(aws sqs get-queue-url --queue-name "$QUEUE-dlq" --query QueueUrl --output text)
state() { aws cloudwatch describe-alarms --alarm-names "$ALARM" --query 'MetricAlarms[0].StateValue' --output text; }

echo "alarm $ALARM is $(state) before the test"
aws sqs send-message --queue-url "$queue_url" \
  --message-body '{"event_id":"dlq-alarm-test","event_type":"MediaProcessRequested","payload":{"test":true}}' >/dev/null
echo "sent a malformed job; waiting for it to reach the DLQ and for the alarm (up to ${TIMEOUT}s)"

deadline=$((SECONDS + TIMEOUT))
until [ "$(state)" = "ALARM" ]; do
  if [ "$SECONDS" -ge "$deadline" ]; then
    echo "FAIL: $ALARM did not fire within ${TIMEOUT}s"
    aws sqs get-queue-attributes --queue-url "$dlq_url" --attribute-names ApproximateNumberOfMessages
    exit 1
  fi
  sleep 20
done
echo "PASS: $ALARM is ALARM (subscribers of the alarm topic were notified)"

aws sqs purge-queue --queue-url "$dlq_url"
echo "DLQ emptied; the alarm returns to OK within a few minutes"
