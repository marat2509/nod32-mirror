#!/bin/env python3
"""
Script for deleting a specific line in *.lng files
"""

import os
import argparse


def remove_line_in_files(path: str, line_number: int) -> None:
    """
    Removes a specific line from all files with the .lng extension in the specified directory.

    Parameters:
    path (str): The path to the directory containing the files.
    line_number (int): The line number to be removed from each file.

    Returns:
    None
    """
    for filename in os.listdir(path):
        if filename.endswith(".lng"):
            file_path = os.path.join(path, filename)
            with open(file_path, "r", encoding="utf-8") as file:
                lines = file.readlines()
            with open(file_path, "w", encoding="utf-8") as file:
                for index, line in enumerate(lines, start=1):
                    if index != line_number:
                        file.write(line)


def main():
    """
    Main function of the script.
    """
    parser = argparse.ArgumentParser(
        description="Remove a specific line in *.lng files"
    )
    parser.add_argument("line", type=int, help="Line number to remove")
    parser.add_argument(
        "--path",
        type=str,
        default="worker/core/langpacks",
        help="Path to the folder with *.lng files",
    )
    args = parser.parse_args()

    remove_line_in_files(args.path, args.line)


if __name__ == "__main__":
    main()
