#!/bin/env python3
"""
Script for importing keys from an existing config file.
"""

import argparse
import configparser
import os
import re


def add_keys_to_file(keys_file_path: str, keys: list[str], versions: list[str]) -> None:
    """
    Add a key and its associated versions to the specified file.

    Args:
        keys_file_path (str): the path to the file where the key and versions will be added
        keys (str): the key to be added
        versions (list): the versions associated with the key

    Returns:
        None
    """

    if not os.path.isdir(os.path.dirname(keys_file_path)):
        os.makedirs(os.path.dirname(keys_file_path), exist_ok=True)

    with open(keys_file_path, "a", encoding="utf-8") as file:
        for key in keys:
            for version in versions:
                file.write(f"{key}:{version}\n")


def main() -> None:
    """
    Add a key to ESET config.

    The script reads enabled versions from [ESET.VERSIONS.*] sections
    where mirror=1, and adds the specified keys for those versions.

    Args:
        --config (str, optional): Path to nod32ms.conf.
                                  Defaults to 'nod32ms.conf'.
        --keys_file (str, optional): Path to keys file.
                                     Defaults to 'docker-data/log/nod_keys.valid'.
        --key (str): Key in format LOGIN:PASSWORD, can be used multiple times
                     Has check if key is valid by regex pattern
        --pattern (str): Pattern for keys in format LOGIN:PASSWORD
    """

    parser = argparse.ArgumentParser(description="Add a key to ESET config.")
    parser.add_argument("--config", default="nod32ms.conf", help="Path to nod32ms.conf")
    parser.add_argument(
        "--keys_file",
        type=str,
        default="docker-data/log/nod_keys.valid",
        help="Path to keys file",
    )
    parser.add_argument(
        "--key",
        "-k",
        type=str,
        help="Key in format LOGIN:PASSWORD, can be used multiple times",
        action="append",
    )
    parser.add_argument(
        "--pattern",
        type=str,
        default=r"((EAV|TRIAL)-[0-9]{10}):+?([a-z0-9]{10})",
        help="Pattern for keys in format LOGIN:PASSWORD",
    )

    args = parser.parse_args()
    config = configparser.ConfigParser(inline_comment_prefixes=";")
    config.read(args.config, encoding="utf-8")

    # Get versions from ESET.VERSIONS section
    versions = []
    for section_name in config.sections():
        if section_name.startswith("ESET.VERSIONS."):
            version = section_name.replace("ESET.VERSIONS.", "")
            if config[section_name].get("mirror") == "1":
                versions.append(version)

    if not versions:
        print("Warning: No enabled versions found in configuration.")
        return

    print(f"Found enabled versions: {', '.join(versions)}")

    pattern = re.compile(args.pattern)

    for key in args.key:
        if not pattern.match(key):
            raise ValueError(f"Key {key} does not match pattern.")

    add_keys_to_file(args.keys_file, args.key, versions)


if __name__ == "__main__":
    main()
