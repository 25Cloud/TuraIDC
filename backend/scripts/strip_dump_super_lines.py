"""只过滤 SUPER 权限行，不连接数据库。用法：

python backend/scripts/strip_dump_super_lines.py --dump 旧dump.sql --output 过滤后.sql
"""

import argparse
import sys
from pathlib import Path

STOP_LINE_PATTERNS = [
    "SET @MYSQLDUMP_TEMP_LOG_BIN = @@SESSION.SQL_LOG_BIN;",
    "SET @@SESSION.SQL_LOG_BIN= 0;",
    "SET @@SESSION.SQL_LOG_BIN = @MYSQLDUMP_TEMP_LOG_BIN;",
    "SET @@GLOBAL.GTID_PURGED=",
]


def should_skip_line(line: str) -> bool:
    stripped = line.strip()
    if not stripped:
        return False
    for prefix in STOP_LINE_PATTERNS:
        if stripped.startswith(prefix):
            return True
    return False


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--dump", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()

    src = Path(args.dump)
    dst = Path(args.output)
    total = 0
    skipped = 0

    with open(src, "r", encoding="utf-8", errors="replace") as f_in:
        with open(dst, "w", encoding="utf-8") as f_out:
            for line in f_in:
                total += 1
                if should_skip_line(line):
                    skipped += 1
                    f_out.write("-- [strip] stripped SUPER privilege line\n")
                    continue
                f_out.write(line)

    print(f"Total lines: {total}, Skipped: {skipped}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
