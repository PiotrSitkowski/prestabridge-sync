#!/bin/bash
# e2e-test.sh — uruchamiać po deploy

WORKER_URL="https://prestabridge-router.meriscrap.workers.dev/import"
SECRET="test-secret"
BODY='{"products":[{"sku":"E2E-001","name":"E2E Test","price":9.99,"images":["https://via.placeholder.com/150"]}],"batchSize":5}'

TIMESTAMP=$(date +%s)
SIGNATURE=$(echo -n "${TIMESTAMP}.${BODY}" | openssl dgst -sha256 -hmac "${SECRET}" | awk '{print $2}')

RESPONSE=$(curl -s -w "\n%{http_code}" \
  -X POST "${WORKER_URL}" \
  -H "Content-Type: application/json" \
  -H "X-PrestaBridge-Auth: ${TIMESTAMP}.${SIGNATURE}" \
  -d "${BODY}")

HTTP_CODE=$(echo "$RESPONSE" | tail -1)
BODY_RESP=$(echo "$RESPONSE" | head -1)

echo "Status: ${HTTP_CODE}"
echo "Body: ${BODY_RESP}"

if [ "$HTTP_CODE" = "200" ]; then
  echo "✅ E2E Router test PASSED"
else
  echo "❌ E2E Router test FAILED"
  exit 1
fi
