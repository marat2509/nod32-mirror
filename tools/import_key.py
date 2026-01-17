#!/bin/env python3
"""
Script for importing keys from an existing config file.
"""

import argparse
import json
import os
import re
from typing import Any, Dict, List, Literal


KeyStatus = Literal["valid", "invalid"]


def _ensure_data_dir(path: str) -> None:
    parent = os.path.dirname(path)
    if parent:
        os.makedirs(parent, exist_ok=True)


def _load_keys(keys_file_path: str) -> Dict[str, List[Dict[str, Any]]]:
    """
    Load keys.json, returning an empty structure if the file does not exist.
    """

    if not os.path.exists(keys_file_path):
        return {"valid": [], "invalid": []}

    with open(keys_file_path, "r", encoding="utf-8") as fh:
        data = json.load(fh)

    # Ensure both buckets exist even if the file is partially populated.
    data.setdefault("valid", [])
    data.setdefault("invalid", [])
    return data


def _save_keys(keys_file_path: str, data: Dict[str, List[Dict[str, Any]]]) -> None:
    _ensure_data_dir(keys_file_path)
    with open(keys_file_path, "w", encoding="utf-8") as fh:
        json.dump(data, fh, ensure_ascii=False, indent=2)


def _upsert_key(
    bucket: List[Dict[str, Any]], login: str, password: str, versions: List[str]
) -> None:
    """
    Add or update a key entry within the provided bucket, merging versions.
    """

    for entry in bucket:
        if entry.get("login") == login and entry.get("password") == password:
            existing_versions = entry.get("versions", [])
            # Preserve existing order; append only missing versions.
            for version in versions:
                if version not in existing_versions:
                    existing_versions.append(version)
            entry["versions"] = existing_versions
            return

    bucket.append({"login": login, "password": password, "versions": versions})


def add_keys_to_file(
    keys_file_path: str, keys: List[str], versions: List[str], status: KeyStatus
) -> None:
    """
    Add keys and their associated versions to keys.json under the chosen bucket.
    """

    data = _load_keys(keys_file_path)
    bucket = data[status]

    for key in keys:
        login, password = key.split(":", 1)
        _upsert_key(bucket, login, password, versions)

    _save_keys(keys_file_path, data)


def main() -> None:
    """
    Add a key to ESET config.

    The script reads enabled versions from [ESET.VERSIONS.*] sections
    where mirror=1, and adds the specified keys for those versions.

    Args:
        --config (str, optional): Path to nod32-mirror.yaml.
                                  Defaults to 'nod32-mirror.yaml'.
        --keys_file (str, optional): Path to keys.json.
                                     Defaults to 'docker-data/data/keys.json'.
        --key (str): Key in format LOGIN:PASSWORD, can be used multiple times
                     Has check if key is valid by regex pattern
        --status (str): Target bucket in keys.json (valid/invalid)
        --pattern (str): Pattern for keys in format LOGIN:PASSWORD
    """

    parser = argparse.ArgumentParser(description="Add a key to ESET config.")
    parser.add_argument(
        "--config",
        default="nod32-mirror.yaml",
        help="Path to nod32-mirror.yaml",
    )
    parser.add_argument(
        "--keys_file",
        type=str,
        default="docker-data/data/keys.json",
        help="Path to keys.json (with valid/invalid buckets)",
    )
    parser.add_argument(
        "--key",
        "-k",
        type=str,
        help="Key in format LOGIN:PASSWORD, can be used multiple times",
        action="append",
    )
    parser.add_argument(
        "--status",
        choices=["valid", "invalid"],
        default="valid",
        help="Target bucket inside keys.json",
    )
    parser.add_argument(
        "--pattern",
        type=str,
        default=r"((EAV|TRIAL)-[0-9]{10}):+?([a-z0-9]{10})",
        help="Pattern for keys in format LOGIN:PASSWORD",
    )

    args = parser.parse_args()

    try:
        import yaml  # type: ignore
    except ImportError as exc:
        raise ImportError(
            "PyYAML is required to read nod32-mirror.yaml. Install via `pip install pyyaml`."
        ) from exc

    with open(args.config, "r", encoding="utf-8") as fh:
        raw_config: Dict[str, Any] = yaml.safe_load(fh) or {}

    versions_block = (
        raw_config.get("eset", {})
        .get("versions", {})
        .get("overrides", {})
    )

    versions = [
        version
        for version, settings in versions_block.items()
        if settings and str(settings.get("mirror", 0)) in {"1", "true", "True"}
    ]

    if not versions:
        print("Warning: No enabled versions found in configuration.")
        return

    print(f"Found enabled versions: {', '.join(versions)}")

    pattern = re.compile(args.pattern)

    if not args.key:
        parser.error("At least one --key value is required.")

    for key in args.key:
        if not pattern.match(key):
            raise ValueError(f"Key {key} does not match pattern.")

    add_keys_to_file(args.keys_file, args.key, versions, args.status)


if __name__ == "__main__":
    main()
