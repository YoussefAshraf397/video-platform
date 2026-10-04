#!/bin/sh
# Creates the local AWS resources used during weeks 1-8 (see docs/SPRINT_PLAN_W1-8.md).
# Idempotent: safe to run on every `make up`. Names must match what infra/ creates in AWS.
set -eu

# --- S3 buckets --------------------------------------------------------------
for bucket in uploads media images; do
  aws s3api head-bucket --bucket "$bucket" 2>/dev/null || aws s3api create-bucket --bucket "$bucket" >/dev/null
done

# Browsers upload parts directly to the uploads bucket and must be able to read ETag (ADR-003).
aws s3api put-bucket-cors --bucket uploads --cors-configuration '{
  "CORSRules": [{
    "AllowedOrigins": ["*"],
    "AllowedMethods": ["PUT", "GET", "HEAD"],
    "AllowedHeaders": ["*"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
  }]
}'

# --- SQS queues, each with a DLQ (maxReceiveCount 5, ADR-005) ---------------
queue_arn() {
  aws sqs get-queue-attributes --queue-url "$1" --attribute-names QueueArn --query Attributes.QueueArn --output text
}

create_queue() {
  name="$1"
  visibility="$2"
  dlq_url=$(aws sqs create-queue --queue-name "$name-dlq" --query QueueUrl --output text)
  dlq_arn=$(queue_arn "$dlq_url")
  aws sqs create-queue --queue-name "$name" --attributes "{
    \"VisibilityTimeout\": \"$visibility\",
    \"RedrivePolicy\": \"{\\\"deadLetterTargetArn\\\":\\\"$dlq_arn\\\",\\\"maxReceiveCount\\\":\\\"5\\\"}\"
  }" --query QueueUrl --output text
}

create_queue media-process 300 >/dev/null     # Laravel -> Go worker (worker extends visibility while working)
create_queue media-results 60 >/dev/null      # Go worker -> Laravel
s3_events_url=$(create_queue s3-upload-events 60)   # S3 ObjectCreated -> upload reconciliation
dispatcher_url=$(create_queue media-dispatcher 60)  # video-events subscriber that starts processing

# --- S3 -> SQS notification for upload reconciliation ------------------------
s3_events_arn=$(queue_arn "$s3_events_url")
aws s3api put-bucket-notification-configuration --bucket uploads --notification-configuration "{
  \"QueueConfigurations\": [{\"QueueArn\": \"$s3_events_arn\", \"Events\": [\"s3:ObjectCreated:*\"]}]
}"

# --- SNS topic for domain events, fanned out to subscriber queues ------------
topic_arn=$(aws sns create-topic --name video-events --query TopicArn --output text)
aws sns subscribe --topic-arn "$topic_arn" --protocol sqs \
  --notification-endpoint "$(queue_arn "$dispatcher_url")" \
  --attributes RawMessageDelivery=true >/dev/null

echo "AWS emulator ready: buckets, queues (+DLQs), and topics created."
