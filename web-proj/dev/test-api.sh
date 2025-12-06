#!/usr/bin/env bash
# Simple POSIX shell test script using curl to exercise API endpoints.
# Usage: ./dev/test-api.sh

BASE=${BASE:-http://localhost:8000}
COOKIE_JAR=$(mktemp)
ADMIN_SECRET=${EVENTS_ADMIN_SECRET:-change-me-to-a-secure-value}

echo "Base URL: $BASE"

echo "1) Register user"
curl -s -X POST "$BASE/api/register.php" -H "Content-Type: application/json" -d '{"name":"sh-test","email":"sh+test@example.com","password":"secret123","age":30}'

echo "\n2) Login (save cookies)"
curl -s -c $COOKIE_JAR -X POST "$BASE/api/login.php" -H "Content-Type: application/json" -d '{"email":"sh+test@example.com","password":"secret123"}'

echo "\n3) Get profile"
curl -s -b $COOKIE_JAR "$BASE/api/me.php"

echo "\n4) Create event (admin)"
curl -s -b $COOKIE_JAR -X POST "$BASE/api/events.php" -H "Content-Type: application/json" -H "X-Admin-Secret: $ADMIN_SECRET" -d '{"title":"Shell Test Event","description":"Created by test script","location":"Shell Park","lat":51.5074,"lng":-0.1278,"date":"2025-12-01","time":"19:00","price":0}'

echo "\n5) List events near coordinates"
curl -s "$BASE/api/events.php?lat=51.5074&lng=-0.1278&radius=50"

# cleanup
rm -f $COOKIE_JAR

echo "\nTest script finished."
