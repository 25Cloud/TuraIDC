#!/usr/bin/env python3
"""重置本地 idc 数据库、初始化当前结构，并从 MySQL dump 迁移数据。

该脚本只编排现有脚本：
- install_db.py --reset 负责清空并初始化当前项目结构。
- migrate_legacy_dump.py 负责按当前表结构迁移 dump 数据。

目标库不会从 dump 创建旧表；旧 dump 只用于临时库/临时前缀表的数据来源，
迁移成功后临时对象默认会被清理。
"""

from __future__ import annotations

import argparse
import os
import subprocess
import sys
import time
from pathlib import Path


SCRIPT_PATH = Path(__file__).resolve()
SCRIPT_DIR = SCRIPT_PATH.parent
BACKEND_DIR = SCRIPT_DIR.parent
REPO_ROOT = BACKEND_DIR.parent
DEFAULT_ENV = BACKEND_DIR / ".env"
DEFAULT_LOG_DIR = REPO_ROOT / "migration-output" / "migration-records"


class WorkflowError(RuntimeError):
    """迁移总控流程中的可预期错误。"""


class TeeLogger:
    def __init__(self, log_file: Path) -> None:
        log_file.parent.mkdir(parents=True, exist_ok=True)
        self.log_file = log_file
        self.handle = log_file.open("w", encoding="utf-8", newline="")

    def close(self) -> None:
        self.handle.close()

    def write(self, message: str = "") -> None:
        console_encoding = sys.stdout.encoding or "utf-8"
        console_message = message.encode(console_encoding, errors="replace").decode(
            console_encoding,
            errors="replace",
        )
        print(console_message, flush=True)
        self.handle.write(message + "\n")
        self.handle.flush()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="重置本地数据库，初始化当前结构，并迁移指定 MySQL dump 的数据。",
    )
    parser.add_argument(
        "--dump",
        required=True,
        help="要迁移的 MySQL dump 文件。",
    )
    parser.add_argument(
        "--env",
        default=str(DEFAULT_ENV),
        help=f"后端 .env 文件，默认：{DEFAULT_ENV}",
    )
    parser.add_argument(
        "--target-db",
        default="",
        help="目标数据库名，默认读取 .env 的 DB_DATABASE。",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="只检查流程，不清空数据库、不写入数据。",
    )
    parser.add_argument(
        "--log-file",
        default="",
        help="迁移记录文件路径，默认写入 migration-output/migration-records/。",
    )

    return parser.parse_args()


def timestamp() -> str:
    return time.strftime("%Y%m%d%H%M%S")


def resolve_log_file(raw_path: str) -> Path:
    if raw_path:
        return Path(raw_path).expanduser().resolve()

    return DEFAULT_LOG_DIR / f"idc-local-migration-{timestamp()}.log"


def ensure_file(path: Path, message: str) -> None:
    if not path.is_file():
        raise WorkflowError(message)


def render_command(args: list[str]) -> str:
    return " ".join(args)


def run_step(name: str, args: list[str], logger: TeeLogger) -> None:
    logger.write("")
    logger.write(f"== {name} ==")
    logger.write(render_command(args))

    child_env = os.environ.copy()
    child_env["PYTHONIOENCODING"] = "utf-8"

    process = subprocess.Popen(
        args,
        cwd=str(REPO_ROOT),
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        encoding="utf-8",
        errors="replace",
        env=child_env,
    )

    assert process.stdout is not None
    for line in process.stdout:
        logger.write(line.rstrip("\n"))

    returncode = process.wait()
    if returncode != 0:
        raise WorkflowError(f"{name} 失败，退出码 {returncode}")


def build_install_command(dry_run: bool) -> list[str]:
    args = [sys.executable, str(SCRIPT_DIR / "install_db.py"), "--reset"]
    if dry_run:
        args.append("--dry-run")

    return args


def build_migration_command(args: argparse.Namespace, dump_path: Path, env_file: Path) -> list[str]:
    command = [
        sys.executable,
        str(SCRIPT_DIR / "migrate_legacy_dump.py"),
        "--dump",
        str(dump_path),
        "--env",
        str(env_file),
    ]

    if args.target_db.strip():
        command.extend(["--target-db", args.target_db.strip()])
    if args.dry_run:
        command.append("--dry-run")

    return command


def main() -> int:
    args = parse_args()
    dump_path = Path(args.dump).expanduser().resolve()
    env_file = Path(args.env).expanduser().resolve()
    log_file = resolve_log_file(args.log_file)
    logger = TeeLogger(log_file)

    try:
        ensure_file(dump_path, f"未找到 dump 文件：{dump_path}")
        ensure_file(env_file, f"未找到 .env 文件：{env_file}")

        logger.write("本地 idc 数据迁移流程")
        logger.write(f"时间：{time.strftime('%Y-%m-%d %H:%M:%S')}")
        logger.write(f"后端目录：{BACKEND_DIR}")
        logger.write(f"dump 文件：{dump_path}")
        logger.write(f"env 文件：{env_file}")
        logger.write(f"dry-run：{'是' if args.dry_run else '否'}")

        run_step("1. 清空并初始化本地数据库", build_install_command(args.dry_run), logger)
        run_step("2. 迁移 dump 数据到当前结构", build_migration_command(args, dump_path, env_file), logger)

        logger.write("")
        logger.write("迁移总控流程完成")
        logger.write(f"迁移记录：{log_file}")
        return 0
    except WorkflowError as exc:
        logger.write("")
        logger.write(f"错误：{exc}")
        logger.write(f"迁移记录：{log_file}")
        return 1
    finally:
        logger.close()


if __name__ == "__main__":
    raise SystemExit(main())
