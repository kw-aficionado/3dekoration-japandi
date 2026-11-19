"""Build the installable WordPress theme from its runtime files."""

import argparse
from pathlib import Path
import re
import zipfile


ROOT = Path(__file__).resolve().parents[1]
SLUG = "3dekoration-japandi"
REQUIRED = ("style.css", "functions.php", "inc/updates.php", "inc/inherit-layout.php", "assets/japandi.css")
ROOT_FILES = ("style.css", "functions.php", "theme.json", "screenshot.png", "LICENSE", "LICENSE.txt")
THEME_DIRS = ("inc", "assets", "styles", "patterns", "templates", "parts", "languages")


def package(tag):
    css = (ROOT / "style.css").read_text(encoding="utf-8")
    version = re.search(r"^Version:[ \t]*([0-9]+\.[0-9]+\.[0-9]+)[ \t]*$", css, re.MULTILINE)
    if not version or (tag is not None and tag != "v" + version[1]):
        raise SystemExit("Version in style.css must match the vMAJOR.MINOR.PATCH release tag.")

    for name in REQUIRED:
        if not (ROOT / name).is_file():
            raise SystemExit(f"Missing required theme file: {name}")
    files = [ROOT / name for name in ROOT_FILES if (ROOT / name).is_file()]
    for folder in THEME_DIRS:
        for path in (ROOT / folder).rglob("*"):
            if path.is_symlink():
                raise SystemExit(f"Unexpected symlink in theme: {path}")
            if path.is_file() and not any(part.startswith(".") for part in path.relative_to(ROOT).parts):
                files.append(path)

    output = ROOT / "dist" / (SLUG + ".zip")
    output.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(output, "w") as archive:
        for path in sorted(files):
            member = zipfile.ZipInfo(f"{SLUG}/{path.relative_to(ROOT).as_posix()}")
            member.create_system = 3
            member.external_attr = 0o100644 << 16
            archive.writestr(member, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED)
    with zipfile.ZipFile(output) as archive:
        if archive.testzip():
            raise SystemExit("ZIP integrity check failed")
    print(output)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--tag", help="require style.css to match this Git tag")
    package(parser.parse_args().tag)
