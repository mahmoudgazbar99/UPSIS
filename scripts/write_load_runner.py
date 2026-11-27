from pathlib import Path
Path("scripts/run-neon-load.sh").write_bytes(
    b"#!/bin/sh\n"
    b"url=$(printf '%s' \"$DATABASE_URL_UNPOOLED\" | tr -d '\"')\n"
    b"psql \"$url\" -v ON_ERROR_STOP=1 -f /load.sql\n"
)
Path("scripts/load-neon.sql").write_bytes(Path("scripts/load-neon.sql").read_bytes().replace(b"\r\n", b"\n"))
