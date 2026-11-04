#!/bin/sh
# Generates the SeaweedFS S3 identity file from environment variables (no secrets in the repo).
set -eu
cat > /tmp/s3.json <<JSON
{"identities":[{"name":"platform","credentials":[{"accessKey":"${S3_KEY}","secretKey":"${S3_SECRET}"}],"actions":["Admin","Read","Write","List","Tagging"]}]}
JSON
exec weed server -dir=/data -s3 -s3.port=8333 -s3.config=/tmp/s3.json -master.volumeSizeLimitMB=1024 -volume.max=0 -ip.bind=0.0.0.0
