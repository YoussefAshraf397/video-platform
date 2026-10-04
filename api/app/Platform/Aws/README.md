# AWS clients

`AwsServiceProvider` registers one configured SDK client per service (`S3Client`, `SnsClient`, `SqsClient`). Inject them; never `new` a client in a module. Settings are in `config/aws.php`:

- **Locally:** explicit test keys, `AWS_ENDPOINT_URL=http://localhost:4566` (the emulator) and `AWS_USE_PATH_STYLE_ENDPOINT=true`.
- **In AWS:** leave keys, endpoint and path style unset. The SDK uses the ECS task role.
