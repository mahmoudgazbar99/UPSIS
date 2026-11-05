#!/bin/sh
url=$(printf '%s' "$DATABASE_URL_UNPOOLED" | tr -d '"')
psql "$url" -v ON_ERROR_STOP=1
