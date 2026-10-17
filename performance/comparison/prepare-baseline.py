"""Load a frozen PHP engine beside the candidate for paired, same-process timing."""
import argparse
import io
import pathlib
import subprocess
import tarfile

parser = argparse.ArgumentParser()
parser.add_argument("--revision", default="988a739c20e5e52adbf450f864ce5b4e17e4aa6e")
revision = parser.parse_args().revision
root = pathlib.Path(__file__).resolve().parents[2]
destination = root / "build" / "comparison-baseline"
archive = subprocess.check_output(["git", "archive", revision, "src"], cwd=root)
with tarfile.open(fileobj=io.BytesIO(archive)) as files:
    for member in files.getmembers():
        if not member.isfile() or not member.name.endswith(".php"):
            continue
        text = files.extractfile(member).read()
        # Replace both PHP namespace syntax and escaped namespace string literals.
        text = text.replace(b"Keepsuit\\\\Liquid", b"LiquidBaseline\\\\Liquid")
        text = text.replace(b"Keepsuit\\Liquid", b"LiquidBaseline\\Liquid")
        output = destination / pathlib.PurePosixPath(member.name).relative_to("src")
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_bytes(text)
(destination / "REVISION").write_text(revision + "\n")
print(destination)
