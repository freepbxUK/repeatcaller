#!/usr/bin/env python3
"""Prepare and package Repeat Caller 1.2.0 without modifying source files.

Signing is deliberately external. A signed package can only be finalized from
an already-signed staging tree and with an authorized signature-verifier tool.
"""

from __future__ import annotations

import argparse
import gzip
import hashlib
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path, PurePosixPath
from typing import Callable, Dict, Iterable, Optional, Set, Tuple

MODULE_NAME = "repeatcaller"
MODULE_VERSION = "1.2.0"
ROOT_FILES = (
    "Job.php",
    "LICENSE",
    "README.md",
    "Repeatcaller.class.php",
    "USER_GUIDE.md",
    "install.php",
    "module.xml",
    "page.repeatcaller.php",
    "uninstall.php",
)
RUNTIME_DIRECTORIES = ("agi", "assets", "src", "views")
DEVELOPMENT_ROOT_ENTRIES = {
    ".agents",
    ".codex",
    ".git",
    ".github",
    ".gitignore",
    ".vscode",
    "TESTING.md",
    "__pycache__",
    "module.sig",
    "scripts",
    "tests",
}
EXCLUDED_DIRECTORY_NAMES = {"__pycache__", ".pytest_cache"}
EXCLUDED_FILE_SUFFIXES = (".bak", ".log", ".orig", ".pyc", ".pyo", ".swp", ".swo", ".tmp", "~")
REQUIRED_FILES = {
    "Job.php",
    "LICENSE",
    "README.md",
    "Repeatcaller.class.php",
    "USER_GUIDE.md",
    "install.php",
    "module.xml",
    "page.repeatcaller.php",
    "uninstall.php",
    "agi/repeatcaller_alert_response.php",
    "assets/css/repeatcaller.css",
    "assets/js/repeatcaller.js",
    "src/AlertCallAgiSession.php",
    "src/AlertCallLanguageSupport.php",
    "src/AlertCallPromptInventory.php",
    "src/AlertCallPromptResolver.php",
    "src/AlertCallSampleBuilder.php",
    "src/BackgroundProcessor.php",
    "src/CdrScanner.php",
    "src/DetectionEngine.php",
    "src/IncidentAlertProcessor.php",
    "src/RepeatCallerRepository.php",
    "src/Schema.php",
    "views/main.php",
}
EXECUTABLE_FILES = {"agi/repeatcaller_alert_response.php"}
INCLUDE_PATTERN = re.compile(
    r"(?:require|include)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*(['\"])([^'\"]+)\1"
)


class PackageError(RuntimeError):
    """Raised when release inputs or generated artifacts fail validation."""


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def ensure_module_version(module_root: Path) -> None:
    module_xml = module_root / "module.xml"
    if not module_xml.is_file() or module_xml.is_symlink():
        raise PackageError("module.xml is missing or is not a regular file")
    try:
        root = ET.parse(str(module_xml)).getroot()
    except (ET.ParseError, OSError) as exc:
        raise PackageError("module.xml is invalid: {}".format(exc)) from exc
    version = root.findtext("version", default="").strip()
    raw_name = root.findtext("rawname", default="").strip()
    if raw_name != MODULE_NAME:
        raise PackageError("module.xml rawname must be {!r}".format(MODULE_NAME))
    if version != MODULE_VERSION:
        raise PackageError(
            "module.xml version must be {}; found {!r}".format(MODULE_VERSION, version)
        )


def ensure_safe_root(path: Path, expected_name: str) -> Path:
    if path.is_symlink() or not path.is_dir():
        raise PackageError("{} must be a real directory".format(path))
    resolved = path.resolve()
    if resolved.name != expected_name:
        raise PackageError("module directory must be named {!r}".format(expected_name))
    return resolved


def _check_relative_name(name: str) -> PurePosixPath:
    if "\\" in name:
        raise PackageError("archive paths must use POSIX separators")
    raw_parts = name.split("/")
    if any(part in ("", ".", "..") for part in raw_parts):
        raise PackageError("unsafe relative path: {!r}".format(name))
    path = PurePosixPath(name)
    if path.is_absolute() or not path.parts:
        raise PackageError("unsafe relative path: {!r}".format(name))
    if any(part.startswith(".") for part in path.parts):
        raise PackageError("hidden paths are not allowed in the module package: {!r}".format(name))
    return path


def _selected_source_paths(source: Path) -> Tuple[Set[str], Set[str]]:
    files: Set[str] = set()
    directories: Set[str] = set()
    allowed_root_entries = set(ROOT_FILES) | set(RUNTIME_DIRECTORIES) | DEVELOPMENT_ROOT_ENTRIES
    unexpected_root_entries = sorted(child.name for child in source.iterdir() if child.name not in allowed_root_entries)
    if unexpected_root_entries:
        raise PackageError("unexpected top-level source entries require package review: {}".format(", ".join(unexpected_root_entries)))

    for name in ROOT_FILES:
        entry = source / name
        if entry.is_symlink() or not entry.is_file():
            raise PackageError("required production file is missing or unsafe: {}".format(name))
        files.add(name)

    for directory in RUNTIME_DIRECTORIES:
        root = source / directory
        if root.is_symlink() or not root.is_dir():
            raise PackageError("required runtime directory is missing or unsafe: {}".format(directory))
        directories.add(directory)
        for current, dirnames, filenames in os.walk(str(root), topdown=True, followlinks=False):
            current_path = Path(current)
            relative_dir = current_path.relative_to(source).as_posix()
            if relative_dir != directory:
                directories.add(relative_dir)
            for dirname in list(dirnames):
                if dirname in EXCLUDED_DIRECTORY_NAMES:
                    dirnames.remove(dirname)
                    continue
                child = current_path / dirname
                relative = child.relative_to(source).as_posix()
                _check_relative_name(relative)
                if child.is_symlink():
                    raise PackageError("symbolic links are not allowed: {}".format(relative))
                if not child.is_dir():
                    raise PackageError("non-directory entry in runtime tree: {}".format(relative))
                directories.add(relative)
            for filename in filenames:
                if filename.endswith(EXCLUDED_FILE_SUFFIXES):
                    continue
                child = current_path / filename
                relative = child.relative_to(source).as_posix()
                _check_relative_name(relative)
                if child.is_symlink():
                    raise PackageError("symbolic links are not allowed: {}".format(relative))
                if not child.is_file():
                    raise PackageError("only regular runtime files are allowed: {}".format(relative))
                files.add(relative)

    missing = REQUIRED_FILES - files
    if missing:
        raise PackageError("required runtime file(s) missing: {}".format(", ".join(sorted(missing))))

    _validate_literal_includes(source, files)
    return files, directories


def _validate_literal_includes(source: Path, files: Set[str]) -> None:
    for relative in sorted(path for path in files if path.endswith(".php")):
        text = (source / relative).read_text(encoding="utf-8", errors="replace")
        parent = PurePosixPath(relative).parent
        for match in INCLUDE_PATTERN.finditer(text):
            include_literal = match.group(2).lstrip("/")
            include_path = PurePosixPath(include_literal)
            if include_path.is_absolute() or ".." in include_path.parts:
                raise PackageError(
                    "local PHP include from {} uses an unsafe path: {}".format(relative, match.group(2))
                )
            included = PurePosixPath(os.path.normpath(str(parent / include_path)))
            if included.is_absolute() or ".." in included.parts or included.as_posix() not in files:
                raise PackageError(
                    "local PHP include from {} is not in the package: {}".format(relative, match.group(2))
                )


def _set_stage_permissions(stage: Path, files: Iterable[str], directories: Iterable[str]) -> None:
    for relative in sorted(directories, key=lambda value: (value.count("/"), value)):
        (stage / relative).chmod(0o755)
    stage.chmod(0o755)
    for relative in sorted(files):
        (stage / relative).chmod(0o755 if relative in EXECUTABLE_FILES else 0o644)


def _inventory_tree(module_root: Path, allow_signature: bool) -> Tuple[Dict[str, str], Set[str]]:
    if module_root.is_symlink() or not module_root.is_dir() or module_root.name != MODULE_NAME:
        raise PackageError("staged module root must be a real directory named 'repeatcaller'")
    files: Dict[str, str] = {}
    directories: Set[str] = set()
    if stat.S_IMODE(module_root.stat().st_mode) != 0o755:
        raise PackageError("staged module root permissions must be 0755")
    for current, dirnames, filenames in os.walk(str(module_root), topdown=True, followlinks=False):
        current_path = Path(current)
        for dirname in list(dirnames):
            child = current_path / dirname
            relative = child.relative_to(module_root).as_posix()
            _check_relative_name(relative)
            if child.is_symlink() or not child.is_dir():
                raise PackageError("symbolic links and special directories are not allowed: {}".format(relative))
            if stat.S_IMODE(child.stat().st_mode) != 0o755:
                raise PackageError("staged directory permissions must be 0755: {}".format(relative))
            directories.add(relative)
        for filename in filenames:
            child = current_path / filename
            relative = child.relative_to(module_root).as_posix()
            _check_relative_name(relative)
            if child.is_symlink() or not child.is_file():
                raise PackageError("only regular files are allowed in the stage: {}".format(relative))
            if relative == "module.sig":
                if not allow_signature:
                    raise PackageError("unsigned development packages must not contain module.sig")
                if child.stat().st_size == 0:
                    raise PackageError("module.sig must not be empty")
            expected_mode = 0o755 if relative in EXECUTABLE_FILES else 0o644
            if stat.S_IMODE(child.stat().st_mode) != expected_mode:
                raise PackageError("staged file permissions must be {:04o}: {}".format(expected_mode, relative))
            files[relative] = sha256_file(child)
    return files, directories


def _load_manifest(manifest_path: Path) -> Dict[str, str]:
    try:
        raw = json.loads(manifest_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise PackageError("release source manifest is missing or invalid: {}".format(exc)) from exc
    if raw.get("module") != MODULE_NAME or raw.get("version") != MODULE_VERSION:
        raise PackageError("release source manifest identifies the wrong module or version")
    files = raw.get("files")
    if not isinstance(files, dict) or not all(isinstance(k, str) and isinstance(v, str) for k, v in files.items()):
        raise PackageError("release source manifest has an invalid file map")
    if not REQUIRED_FILES.issubset(files):
        raise PackageError("source manifest is missing required production files")
    for name in files:
        _check_relative_name(name)
        if name == "module.sig":
            raise PackageError("source manifest must not contain signing material")
    return files


def prepare(source: Path, stage: Path, manifest_path: Optional[Path] = None) -> Path:
    source = ensure_safe_root(source, MODULE_NAME)
    if stage.name != MODULE_NAME:
        raise PackageError("staging directory must be named 'repeatcaller'")
    stage_parent = stage.parent.resolve()
    stage = stage_parent / MODULE_NAME
    if stage == source or source in stage.parents or stage in source.parents:
        raise PackageError("staging directory must be separate from the source tree")
    manifest_path = manifest_path or (stage_parent / (MODULE_NAME + "-" + MODULE_VERSION + "-source-manifest.json"))
    manifest_path = manifest_path.resolve()
    if stage in manifest_path.parents:
        raise PackageError("source manifest must be outside the module staging directory")
    if source == manifest_path or source in manifest_path.parents:
        raise PackageError("source manifest must be outside the production source tree")
    if manifest_path.exists() or manifest_path.is_symlink():
        raise PackageError("source manifest already exists; choose a fresh path")
    files, directories = _selected_source_paths(source)
    ensure_module_version(source)

    stage_parent.mkdir(parents=True, exist_ok=True)
    if stage.is_symlink():
        raise PackageError("refusing to replace a symlink staging directory")
    if stage.exists():
        raise PackageError("staging directory already exists; choose a fresh path")
    stage.mkdir(mode=0o755)
    for relative in sorted(directories, key=lambda value: (value.count("/"), value)):
        (stage / relative).mkdir(parents=True, exist_ok=True)
    for relative in sorted(files):
        destination = stage / relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source / relative, destination)
    _set_stage_permissions(stage, files, directories)

    manifest = {
        "module": MODULE_NAME,
        "version": MODULE_VERSION,
        "source_root": str(source),
        "files": {name: sha256_file(stage / name) for name in sorted(files)},
    }
    manifest_path.parent.mkdir(parents=True, exist_ok=True)
    manifest_path.write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    validate_stage(stage, manifest_path, signed=False)
    return manifest_path


def validate_stage(stage: Path, manifest_path: Path, signed: bool) -> Dict[str, str]:
    ensure_module_version(stage)
    baseline = _load_manifest(manifest_path)
    actual, _ = _inventory_tree(stage, allow_signature=signed)
    signature_names = {"module.sig"} if signed else set()
    if signed and "module.sig" not in actual:
        raise PackageError("signed staging tree must contain a newly authorized module.sig")
    if set(actual) != set(baseline) | signature_names:
        missing = sorted((set(baseline) | signature_names) - set(actual))
        extra = sorted(set(actual) - set(baseline) - signature_names)
        raise PackageError("staged file set differs from prepared manifest; missing={}, extra={}".format(missing, extra))
    changed = sorted(name for name, digest in baseline.items() if actual.get(name) != digest)
    if changed:
        raise PackageError("prepared module files changed after staging/signing: {}".format(", ".join(changed)))
    return actual


def expected_archive_entries(files: Iterable[str]) -> Set[str]:
    entries = {MODULE_NAME}
    for name in files:
        path = PurePosixPath(name)
        parents = list(path.parents)
        for parent in parents:
            if str(parent) != ".":
                entries.add(MODULE_NAME + "/" + parent.as_posix())
        entries.add(MODULE_NAME + "/" + path.as_posix())
    return entries


def inspect_archive(archive_path: Path, expected_files: Dict[str, str], signed: bool) -> Dict[str, str]:
    expected_paths = set(expected_files) | ({"module.sig"} if signed else set())
    actual_hashes: Dict[str, str] = {}
    seen: Set[str] = set()
    expected_entries = expected_archive_entries(expected_paths)
    try:
        archive = tarfile.open(str(archive_path), mode="r:gz")
    except (OSError, tarfile.TarError) as exc:
        raise PackageError("archive is not a valid gzip tarball: {}".format(exc)) from exc
    with archive:
        for member in archive.getmembers():
            if "\\" in member.name:
                raise PackageError("archive contains a path with unsafe separators")
            name = member.name.rstrip("/")
            raw_parts = name.split("/")
            if any(part in ("", ".", "..") for part in raw_parts):
                raise PackageError("archive contains an unsafe path: {!r}".format(member.name))
            path = PurePosixPath(name)
            if path.is_absolute() or not path.parts or path.parts[0] != MODULE_NAME or ".." in path.parts or "." in path.parts:
                raise PackageError("archive contains an unsafe path: {!r}".format(member.name))
            if any(part.startswith(".") for part in path.parts):
                raise PackageError("archive contains an unexpected hidden path: {!r}".format(member.name))
            normalized = path.as_posix()
            if normalized in seen:
                raise PackageError("archive contains duplicate path: {}".format(normalized))
            seen.add(normalized)
            if member.uid != 0 or member.gid != 0 or member.mtime != 0:
                raise PackageError("archive metadata is not reproducible for {}".format(normalized))
            if member.isdir():
                if stat.S_IMODE(member.mode) != 0o755:
                    raise PackageError("archive directory permissions are unsafe: {}".format(normalized))
                continue
            if not member.isfile():
                raise PackageError("archive contains a link or special file: {}".format(normalized))
            relative = "/".join(path.parts[1:])
            if not relative or relative not in expected_paths:
                raise PackageError("archive contains an unexpected file: {}".format(normalized))
            expected_mode = 0o755 if relative in EXECUTABLE_FILES else 0o644
            if stat.S_IMODE(member.mode) != expected_mode:
                raise PackageError("archive file permissions are unsafe: {}".format(normalized))
            stream = archive.extractfile(member)
            if stream is None:
                raise PackageError("archive file cannot be read: {}".format(normalized))
            actual_hashes[relative] = hashlib.sha256(stream.read()).hexdigest()
    if seen != expected_entries:
        raise PackageError(
            "archive paths differ from the staged module; missing={}, extra={}".format(
                sorted(expected_entries - seen), sorted(seen - expected_entries)
            )
        )
    for name, digest in expected_files.items():
        if actual_hashes.get(name) != digest:
            raise PackageError("archived file differs from stage: {}".format(name))
    if signed and "module.sig" not in actual_hashes:
        raise PackageError("signed package is missing module.sig")
    return actual_hashes


def _extract_validated(archive_path: Path, destination: Path) -> Path:
    module_root = destination / MODULE_NAME
    module_root.mkdir(parents=True)
    with tarfile.open(str(archive_path), mode="r:gz") as archive:
        for member in archive.getmembers():
            path = PurePosixPath(member.name.rstrip("/"))
            target = destination.joinpath(*path.parts)
            if member.isdir():
                target.mkdir(parents=True, exist_ok=True)
                target.chmod(0o755)
                continue
            target.parent.mkdir(parents=True, exist_ok=True)
            source = archive.extractfile(member)
            if source is None:
                raise PackageError("cannot extract validated archive member {}".format(member.name))
            with source, target.open("xb") as output:
                shutil.copyfileobj(source, output)
            relative = target.relative_to(module_root).as_posix()
            target.chmod(0o755 if relative in EXECUTABLE_FILES else 0o644)
    return module_root


def _verify_signature(verifier: Optional[Callable[[Path], bool]], module_root: Path) -> None:
    if verifier is None:
        raise PackageError("signed packaging requires an authorized signature verifier")
    try:
        valid = verifier(module_root)
    except Exception as exc:  # noqa: BLE001 - report verifier failures as packaging errors
        raise PackageError("signature verifier failed: {}".format(exc)) from exc
    if valid is not True:
        raise PackageError("signature verification failed for {}".format(module_root))


def _write_deterministic_archive(stage: Path, archive_path: Path, files: Dict[str, str]) -> None:
    directories = {""}
    for name in files:
        parent = PurePosixPath(name).parent
        while str(parent) != ".":
            directories.add(parent.as_posix())
            parent = parent.parent
    archive_path.parent.mkdir(parents=True, exist_ok=True)
    with archive_path.open("wb") as raw:
        with gzip.GzipFile(fileobj=raw, mode="wb", filename="", mtime=0, compresslevel=9) as compressed:
            with tarfile.open(fileobj=compressed, mode="w", format=tarfile.USTAR_FORMAT) as archive:
                for relative_dir in sorted(directories, key=lambda value: (value.count("/"), value)):
                    name = MODULE_NAME + ("/" + relative_dir if relative_dir else "")
                    info = tarfile.TarInfo(name + "/")
                    info.type = tarfile.DIRTYPE
                    info.mode = 0o755
                    info.uid = 0
                    info.gid = 0
                    info.uname = ""
                    info.gname = ""
                    info.mtime = 0
                    archive.addfile(info)
                for relative in sorted(files):
                    info = tarfile.TarInfo(MODULE_NAME + "/" + relative)
                    info.size = (stage / relative).stat().st_size
                    info.mode = 0o755 if relative in EXECUTABLE_FILES else 0o644
                    info.uid = 0
                    info.gid = 0
                    info.uname = ""
                    info.gname = ""
                    info.mtime = 0
                    with (stage / relative).open("rb") as stream:
                        archive.addfile(info, stream)


def verify_checksum(archive_path: Path, checksum_path: Path) -> str:
    expected = checksum_path.read_text(encoding="ascii").strip().split()
    actual = sha256_file(archive_path)
    if len(expected) != 2 or expected[0].lower() != actual or expected[1] != archive_path.name:
        raise PackageError("archive SHA-256 checksum file is invalid")
    return actual


def package_stage(
    stage: Path,
    output_dir: Path,
    kind: str,
    manifest_path: Optional[Path] = None,
    signature_verifier: Optional[Callable[[Path], bool]] = None,
) -> Tuple[Path, Path, str]:
    if kind not in ("unsigned-development", "signed"):
        raise PackageError("package kind must be unsigned-development or signed")
    if stage.is_symlink() or stage.name != MODULE_NAME or not stage.is_dir():
        raise PackageError("staging directory must be named 'repeatcaller'")
    stage = stage.resolve()
    output_dir = output_dir.resolve()
    if output_dir == stage or stage in output_dir.parents:
        raise PackageError("release output must be outside the module staging directory")
    manifest_path = manifest_path or (stage.parent / (MODULE_NAME + "-" + MODULE_VERSION + "-source-manifest.json"))
    try:
        manifest_data = json.loads(manifest_path.read_text(encoding="utf-8"))
        source_root = Path(manifest_data["source_root"]).resolve()
    except (OSError, KeyError, TypeError, json.JSONDecodeError) as exc:
        raise PackageError("release source manifest is missing its source root") from exc
    if output_dir == source_root or source_root in output_dir.parents:
        raise PackageError("release artifacts must be outside the production source tree")
    if stage == source_root or source_root in stage.parents:
        raise PackageError("module staging tree must be outside the production source tree")
    signed = kind == "signed"
    staged_hashes = validate_stage(stage, manifest_path, signed=signed)
    if signed:
        _verify_signature(signature_verifier, stage)
        staged_hashes = validate_stage(stage, manifest_path, signed=True)

    archive_name = (
        "RepeatCaller-{}.tgz".format(MODULE_VERSION)
        if signed
        else "RepeatCaller-{}-unsigned-development.tgz".format(MODULE_VERSION)
    )
    archive_path = output_dir / archive_name
    checksum_path = output_dir / (archive_name + ".sha256")
    output_dir.mkdir(parents=True, exist_ok=True)
    if archive_path.exists() or archive_path.is_symlink() or checksum_path.exists() or checksum_path.is_symlink():
        raise PackageError("release artifact already exists; choose a fresh output directory")
    if stage in archive_path.resolve().parents or stage in checksum_path.resolve().parents:
        raise PackageError("release artifacts must not be written inside the module staging tree")

    with tempfile.TemporaryDirectory(prefix="repeatcaller-package-", dir=str(output_dir)) as temp_dir:
        temporary_archive = Path(temp_dir) / archive_name
        _write_deterministic_archive(stage, temporary_archive, staged_hashes)
        archived_hashes = inspect_archive(temporary_archive, _load_manifest(manifest_path), signed)
        with tempfile.TemporaryDirectory(prefix="repeatcaller-extract-") as extract_dir:
            extracted_root = _extract_validated(temporary_archive, Path(extract_dir))
            ensure_module_version(extracted_root)
            extracted_hashes, _ = _inventory_tree(extracted_root, allow_signature=signed)
            if extracted_hashes != archived_hashes:
                raise PackageError("independently extracted contents differ from the archive members")
            validate_stage(extracted_root, manifest_path, signed=signed)
            if signed:
                _verify_signature(signature_verifier, extracted_root)
                validate_stage(extracted_root, manifest_path, signed=True)
        temporary_checksum = Path(temp_dir) / (archive_name + ".sha256")
        digest = sha256_file(temporary_archive)
        temporary_checksum.write_text("{}  {}\n".format(digest, archive_name), encoding="ascii")
        temporary_archive.chmod(0o644)
        temporary_checksum.chmod(0o644)
        verify_checksum(temporary_archive, temporary_checksum)
        os.replace(str(temporary_archive), str(archive_path))
        os.replace(str(temporary_checksum), str(checksum_path))

    verify_checksum(archive_path, checksum_path)
    inspect_archive(archive_path, _load_manifest(manifest_path), signed)
    return archive_path, checksum_path, digest


def _signature_verifier_from_path(path: Optional[Path]) -> Optional[Callable[[Path], bool]]:
    if path is None:
        return None
    verifier = path.resolve()
    if not verifier.is_file() or not os.access(str(verifier), os.X_OK):
        raise PackageError("signature verifier must be an executable authorized verifier")

    def run(module_root: Path) -> bool:
        completed = subprocess.run([str(verifier), str(module_root)], check=False)
        return completed.returncode == 0

    return run


def _default_repository_root() -> Path:
    return Path(__file__).resolve().parent.parent


def main(argv: Optional[list[str]] = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)

    prepare_parser = subparsers.add_parser("prepare", help="create a clean, unsigned staging tree")
    prepare_parser.add_argument("--source", type=Path, default=_default_repository_root())
    prepare_parser.add_argument("--stage", type=Path, required=True, help="must end in /repeatcaller and be outside the source tree")
    prepare_parser.add_argument("--manifest", type=Path, help="optional external source-manifest path")

    package_parser = subparsers.add_parser("package", help="package a prepared tree without changing it")
    package_parser.add_argument("--stage", type=Path, required=True, help="prepared /repeatcaller directory")
    package_parser.add_argument("--manifest", type=Path, help="manifest written by prepare; defaults beside stage")
    package_parser.add_argument("--output-dir", type=Path, required=True, help="must be outside the staged module tree")
    package_parser.add_argument("--kind", choices=("unsigned-development", "signed"), required=True)
    package_parser.add_argument("--signature-verifier", type=Path, help="authorized executable that validates module.sig for a module directory")

    args = parser.parse_args(argv)
    try:
        if args.command == "prepare":
            manifest = prepare(args.source, args.stage, args.manifest)
            print("Prepared unsigned source tree: {}".format(args.stage.resolve()))
            print("Pre-sign file manifest: {}".format(manifest))
            print("No signing material was copied or generated.")
            return 0
        verifier = _signature_verifier_from_path(args.signature_verifier)
        archive, checksum, digest = package_stage(
            args.stage,
            args.output_dir,
            args.kind,
            args.manifest,
            verifier,
        )
        print("Package: {}".format(archive))
        print("Checksum: {}".format(checksum))
        print("SHA-256: {}".format(digest))
        return 0
    except (PackageError, OSError, tarfile.TarError) as exc:
        print("package_release: {}".format(exc), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
