#!/bin/sh
# Creates the local AWS resources used during weeks 1-8 (see docs/SPRINT_PLAN_W1-8.md).
# Idempotent: safe to run on every `make up`. Names must match what infra/ creates in AWS.
set -eu

# --- S3 buckets --------------------------------------------------------------
for bucket in uploads media images; do
  aws s3api head-bucket --bucket "$bucket" 2>/dev/null || aws s3api create-bucket --bucket "$bucket" >/dev/null
done

# Browsers upload directly to the uploads bucket: video parts with PUT, reading ETag (ADR-003), and
# subtitle files with a POST form upload (S4-06).
aws s3api put-bucket-cors --bucket uploads --cors-configuration '{
  "CORSRules": [{
    "AllowedOrigins": ["*"],
    "AllowedMethods": ["PUT", "POST", "GET", "HEAD"],
    "AllowedHeaders": ["*"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
  }]
}'

# Players fetch media cross-origin with the signed cookies (design doc §13.3), so the origins are listed
# (credentials can't go with "*"): the test player on the api (S4-07) and the web app. In AWS the
# CloudFront response headers policy does this (S4-03).
aws s3api put-bucket-cors --bucket media --cors-configuration '{
  "CORSRules": [{
    "AllowedOrigins": ["http://localhost:8000", "http://localhost:3000"],
    "AllowedMethods": ["GET", "HEAD"],
    "AllowedHeaders": ["*"],
    "MaxAgeSeconds": 3600
  }]
}'

# --- SQS queues, each with a DLQ (maxReceiveCount 5, ADR-005) ---------------
queue_arn() {
  aws sqs get-queue-attributes --queue-url "$1" --attribute-names QueueArn --query Attributes.QueueArn --output text
}

# Create-then-update, so re-running after an attribute change updates the existing queue.
create_queue() {
  name="$1"
  visibility="$2"
  delay="${3:-0}"
  dlq_url=$(aws sqs create-queue --queue-name "$name-dlq" --query QueueUrl --output text)
  dlq_arn=$(queue_arn "$dlq_url")
  url=$(aws sqs create-queue --queue-name "$name" --query QueueUrl --output text)
  aws sqs set-queue-attributes --queue-url "$url" --attributes "{
    \"VisibilityTimeout\": \"$visibility\",
    \"DelaySeconds\": \"$delay\",
    \"RedrivePolicy\": \"{\\\"deadLetterTargetArn\\\":\\\"$dlq_arn\\\",\\\"maxReceiveCount\\\":\\\"5\\\"}\"
  }"
  echo "$url"
}

process_url=$(create_queue media-process 300)   # Laravel -> Go worker (worker extends visibility while working)
create_queue media-results 60 >/dev/null      # Go worker -> Laravel
# S3 ObjectCreated -> upload reconciliation. Delayed 90 s so the :complete call that created the
# object has normally committed before the reconciler looks (api Uploads module README).
s3_events_url=$(create_queue s3-upload-events 60 90)
dispatcher_url=$(create_queue media-dispatcher 60)  # video-events subscriber that starts processing

# --- S3 -> SQS notification for upload reconciliation ------------------------
s3_events_arn=$(queue_arn "$s3_events_url")
aws s3api put-bucket-notification-configuration --bucket uploads --notification-configuration "{
  \"QueueConfigurations\": [{
    \"QueueArn\": \"$s3_events_arn\",
    \"Events\": [\"s3:ObjectCreated:*\"],
    \"Filter\": {\"Key\": {\"FilterRules\": [{\"Name\": \"prefix\", \"Value\": \"uploads/\"}, {\"Name\": \"suffix\", \"Value\": \"/source\"}]}}
  }]
}"

# --- SNS topics, fanned out to subscriber queues (raw delivery: the body is the envelope) -----
# subscribe QUEUE_URL TOPIC_ARN [FILTER_POLICY]. Re-subscribing returns the same subscription,
# and the filter policy is (re)applied, so this is safe to re-run.
subscribe() {
  sub_arn=$(aws sns subscribe --topic-arn "$2" --protocol sqs --notification-endpoint "$(queue_arn "$1")" \
    --attributes RawMessageDelivery=true --return-subscription-arn --query SubscriptionArn --output text)
  if [ -n "${3:-}" ]; then
    aws sns set-subscription-attributes --subscription-arn "$sub_arn" --attribute-name FilterPolicy --attribute-value "$3"
  fi
}

# Domain events from the api. The media dispatcher only wants VideoUploaded (S3-06).
video_events_arn=$(aws sns create-topic --name video-events --query TopicArn --output text)
subscribe "$dispatcher_url" "$video_events_arn" '{"event_type":["VideoUploaded"]}'

# Commands to the media worker, published through the api's outbox (S3-06).
media_commands_arn=$(aws sns create-topic --name media-commands --query TopicArn --output text)
subscribe "$process_url" "$media_commands_arn"

echo "AWS emulator ready: buckets, queues (+DLQs), and topics created."
