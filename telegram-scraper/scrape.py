#!/usr/bin/env python3
"""Entry point: python scrape.py --channel @name --drive-path "My Drive/Prompts"."""

import sys

from tgscraper.cli import main

if __name__ == "__main__":
    sys.exit(main())
