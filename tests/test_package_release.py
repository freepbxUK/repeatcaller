import hashlib
import importlib.util
import json
import os
import stat
import sys
import tarfile
import tempfile
import unittest
from pathlib import Path


REPOSITORY_ROOT = Path(__file__).resolve().parents[1]
SCRIPT_PATH = REPOSITORY_ROOT / "scripts" / "package_release.py"
SPEC = importlib.util.spec_from_file_location("package_release", SCRIPT_PATH)
package_release = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = package_release
SPEC.loader.exec_module(package_release)


class PackageReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="repeatcaller-package-test-")
        self.root = Path(self.temporary.name)
        self.source = self.root / "repeatcaller"
        self.source.mkdir()
        for relative in package_release.REQUIRED_FILES:
            path = self.source / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            if relative == "module.xml":
                content = (
                    "<?xml version='1.0'?>\n"
                    "<module><rawname>repeatcaller</rawname>"
                    "<version>1.2.0</version></module>\n"
                )
            elif relative.endswith(".php"):
                content = "<?php\n// {}\n".format(relative)
            else:
                content = "release fixture: {}\n".format(relative)
            path.write_text(content, encoding="utf-8")
        (self.source / "tests").mkdir()
        (self.source / "tests" / "test_fixture.php").write_text("not a production file\n")
        (self.source / "TESTING.md").write_text("development testing notes\n")
        (self.source / ".git").mkdir()
        (self.source / ".git" / "config").write_text("development metadata\n")
        (self.source / "module.sig").write_text("stale signature must not ship\n")
        self.work = self.root / "work"
        self.stage = self.work / "stage" / "repeatcaller"
        self.output = self.work / "release"

    def tearDown(self):
        self.temporary.cleanup()

    def prepare(self):
        manifest = package_release.prepare(self.source, self.stage)
        return manifest

    def test_unsigned_archive_is_deterministic_and_exact(self):
        source_hash = hashlib.sha256((self.source / "module.xml").read_bytes()).hexdigest()
        manifest = self.prepare()
        self.assertFalse((self.stage / "module.sig").exists())
        self.assertFalse((self.stage / "tests").exists())
        self.assertFalse((self.stage / "TESTING.md").exists())
        self.assertFalse((self.stage / ".git").exists())
        self.assertEqual(0o755, stat.S_IMODE((self.stage / "agi/repeatcaller_alert_response.php").stat().st_mode))
        self.assertEqual(0o644, stat.S_IMODE((self.stage / "Repeatcaller.class.php").stat().st_mode))

        archive, checksum, digest = package_release.package_stage(
            self.stage, self.output, "unsigned-development", manifest
        )
        first_bytes = archive.read_bytes()
        self.assertEqual("RepeatCaller-1.2.0-unsigned-development.tgz", archive.name)
        self.assertEqual(archive.name + ".sha256", checksum.name)
        self.assertEqual(digest, package_release.verify_checksum(archive, checksum))
        package_release.inspect_archive(archive, package_release._load_manifest(manifest), signed=False)

        second_output = self.work / "release-again"
        second_archive, second_checksum, second_digest = package_release.package_stage(
            self.stage, second_output, "unsigned-development", manifest
        )
        self.assertEqual(first_bytes, second_archive.read_bytes())
        self.assertEqual(digest, second_digest)
        self.assertEqual(archive.name, second_archive.name)
        self.assertEqual(checksum.read_text(encoding="ascii"), second_checksum.read_text(encoding="ascii"))
        self.assertEqual(source_hash, hashlib.sha256((self.source / "module.xml").read_bytes()).hexdigest())
        with tarfile.open(archive, "r:gz") as packaged:
            top_levels = {Path(member.name.rstrip("/")).parts[0] for member in packaged.getmembers()}
            self.assertEqual({"repeatcaller"}, top_levels)
            agi_member = packaged.getmember("repeatcaller/agi/repeatcaller_alert_response.php")
            self.assertEqual(0o755, stat.S_IMODE(agi_member.mode))

    def test_module_version_and_directory_name_are_enforced(self):
        (self.source / "module.xml").write_text(
            "<module><rawname>repeatcaller</rawname><version>1.2.1</version></module>", encoding="utf-8"
        )
        with self.assertRaises(package_release.PackageError):
            package_release.prepare(self.source, self.stage)
        wrong_name = self.root / "wrong-name"
        wrong_name.mkdir()
        with self.assertRaises(package_release.PackageError):
            package_release.prepare(wrong_name, self.root / "other" / "repeatcaller")

    def test_stage_manifest_and_artifacts_must_be_outside_source_tree(self):
        nested_stage = self.source / "build" / "repeatcaller"
        with self.assertRaisesRegex(package_release.PackageError, "separate from the source tree"):
            package_release.prepare(self.source, nested_stage)
        with self.assertRaisesRegex(package_release.PackageError, "manifest must be outside the production source tree"):
            package_release.prepare(self.source, self.stage, self.source / "source-manifest.json")
        manifest = self.prepare()
        with self.assertRaisesRegex(package_release.PackageError, "artifacts must be outside the production source tree"):
            package_release.package_stage(self.stage, self.source / "release", "unsigned-development", manifest)

    def test_existing_stage_manifest_and_artifacts_are_never_overwritten(self):
        self.stage.parent.mkdir(parents=True)
        stage_sentinel = self.stage / "keep.txt"
        self.stage.mkdir()
        stage_sentinel.write_text("preserve staging data\n")
        with self.assertRaisesRegex(package_release.PackageError, "staging directory already exists"):
            package_release.prepare(self.source, self.stage)
        self.assertEqual("preserve staging data\n", stage_sentinel.read_text())

        self.stage.rename(self.work / "stale-stage")
        manifest_path = self.work / "existing-manifest.json"
        manifest_path.write_text("preserve manifest\n")
        with self.assertRaisesRegex(package_release.PackageError, "source manifest already exists"):
            package_release.prepare(self.source, self.stage, manifest_path)
        self.assertEqual("preserve manifest\n", manifest_path.read_text())
        self.assertFalse(self.stage.exists(), "manifest conflict must be detected before staging begins")

        manifest = self.prepare()
        archive, checksum, _ = package_release.package_stage(
            self.stage, self.output, "unsigned-development", manifest
        )
        archive.write_bytes(b"preserve existing package")
        with self.assertRaisesRegex(package_release.PackageError, "artifact already exists"):
            package_release.package_stage(self.stage, self.output, "unsigned-development", manifest)
        self.assertEqual(b"preserve existing package", archive.read_bytes())
        self.assertTrue(checksum.is_file())

    def test_missing_runtime_file_is_rejected(self):
        (self.source / "src" / "RepeatCallerRepository.php").unlink()
        with self.assertRaisesRegex(package_release.PackageError, "required .*file"):
            package_release.prepare(self.source, self.stage)

    def test_known_runtime_caches_and_logs_are_excluded(self):
        cache = self.source / "src" / "__pycache__"
        cache.mkdir()
        (cache / "module.pyc").write_bytes(b"bytecode")
        (self.source / "src" / "diagnostic.log").write_text("temporary log\n")
        (self.source / "assets" / "repeatcaller.css.swp").write_text("editor backup\n")
        self.prepare()
        self.assertFalse((self.stage / "src" / "__pycache__").exists())
        self.assertFalse((self.stage / "src" / "diagnostic.log").exists())
        self.assertFalse((self.stage / "assets" / "repeatcaller.css.swp").exists())

    def test_unrecognized_top_level_file_is_rejected(self):
        (self.source / "RepeatCaller-1.2.0.tgz").write_bytes(b"old archive")
        with self.assertRaisesRegex(package_release.PackageError, "unexpected top-level source entries"):
            package_release.prepare(self.source, self.stage)

    def test_symlinks_and_hidden_runtime_files_are_rejected(self):
        (self.source / "src" / "hidden.php").symlink_to(self.source / "src" / "Schema.php")
        with self.assertRaisesRegex(package_release.PackageError, "symbolic links"):
            package_release.prepare(self.source, self.stage)
        (self.source / "src" / "hidden.php").unlink()
        (self.source / "src" / ".local.php").write_text("not for release\n")
        with self.assertRaisesRegex(package_release.PackageError, "hidden paths"):
            package_release.prepare(self.source, self.stage)

    def test_local_php_dependencies_must_be_packaged(self):
        (self.source / "Repeatcaller.class.php").write_text(
            "<?php require_once __DIR__ . '/src/missing.php';\n", encoding="utf-8"
        )
        with self.assertRaisesRegex(package_release.PackageError, "local PHP include"):
            package_release.prepare(self.source, self.stage)

    def test_parent_traversal_in_php_include_is_rejected(self):
        (self.source / "src" / "Probe.php").write_text(
            "<?php require_once __DIR__ . '/../Job.php';\n", encoding="utf-8"
        )
        with self.assertRaisesRegex(package_release.PackageError, "unsafe path"):
            package_release.prepare(self.source, self.stage)

    def test_stage_changes_after_preparation_are_rejected(self):
        manifest = self.prepare()
        (self.stage / "assets" / "js" / "repeatcaller.js").write_text("changed after prepare\n")
        with self.assertRaisesRegex(package_release.PackageError, "changed after staging"):
            package_release.package_stage(self.stage, self.output, "unsigned-development", manifest)

    def test_unsafe_stage_permissions_and_symlink_stage_are_rejected(self):
        manifest = self.prepare()
        (self.stage / "Repeatcaller.class.php").chmod(0o777)
        with self.assertRaisesRegex(package_release.PackageError, "permissions must be 0644"):
            package_release.package_stage(self.stage, self.output, "unsigned-development", manifest)
        (self.stage / "Repeatcaller.class.php").chmod(0o644)
        linked_stage = self.work / "linked-stage" / "repeatcaller"
        linked_stage.parent.mkdir(parents=True)
        linked_stage.symlink_to(self.stage, target_is_directory=True)
        with self.assertRaisesRegex(package_release.PackageError, "staging directory"):
            package_release.package_stage(linked_stage, self.output, "unsigned-development", manifest)

    def test_unsigned_package_rejects_signature_material(self):
        manifest = self.prepare()
        (self.stage / "module.sig").write_text("must not be included\n")
        with self.assertRaisesRegex(package_release.PackageError, "must not contain module.sig"):
            package_release.package_stage(self.stage, self.output, "unsigned-development", manifest)

    def test_signed_mode_requires_existing_signature_and_verifier(self):
        manifest = self.prepare()
        with self.assertRaisesRegex(package_release.PackageError, "must contain a newly authorized module.sig"):
            package_release.package_stage(self.stage, self.output, "signed", manifest)
        (self.stage / "module.sig").write_bytes(b"fixture signature")
        (self.stage / "module.sig").chmod(0o644)
        with self.assertRaisesRegex(package_release.PackageError, "requires an authorized signature verifier"):
            package_release.package_stage(self.stage, self.output, "signed", manifest)

    def test_signed_mode_runs_verifier_before_and_after_extraction(self):
        manifest = self.prepare()
        (self.stage / "module.sig").write_bytes(b"fixture signature")
        (self.stage / "module.sig").chmod(0o644)
        verified = []

        def verifier(module_root):
            verified.append(Path(module_root))
            return (Path(module_root) / "module.sig").read_bytes() == b"fixture signature"

        archive, checksum, digest = package_release.package_stage(
            self.stage, self.output, "signed", manifest, verifier
        )
        self.assertEqual("RepeatCaller-1.2.0.tgz", archive.name)
        self.assertEqual(2, len(verified))
        self.assertEqual(digest, package_release.verify_checksum(archive, checksum))
        self.assertIn("module.sig", package_release.inspect_archive(archive, package_release._load_manifest(manifest), True))

    def test_failed_signature_verifier_rejects_package(self):
        manifest = self.prepare()
        (self.stage / "module.sig").write_bytes(b"invalid signature")
        (self.stage / "module.sig").chmod(0o644)
        with self.assertRaisesRegex(package_release.PackageError, "signature verification failed"):
            package_release.package_stage(self.stage, self.output, "signed", manifest, lambda _: False)

    def test_checksum_tampering_is_detected(self):
        manifest = self.prepare()
        archive, checksum, _ = package_release.package_stage(
            self.stage, self.output, "unsigned-development", manifest
        )
        checksum.write_text("0" * 64 + "  " + archive.name + "\n", encoding="ascii")
        with self.assertRaisesRegex(package_release.PackageError, "checksum file is invalid"):
            package_release.verify_checksum(archive, checksum)

    def test_unsafe_archive_paths_links_and_modes_are_rejected(self):
        manifest = self.prepare()
        expected = package_release._load_manifest(manifest)
        archive = self.root / "unsafe.tgz"
        with tarfile.open(archive, "w:gz") as packaged:
            traversal = tarfile.TarInfo("repeatcaller/../escape")
            traversal.size = 1
            import io
            packaged.addfile(traversal, io.BytesIO(b"x"))
        with self.assertRaisesRegex(package_release.PackageError, "unsafe path"):
            package_release.inspect_archive(archive, expected, signed=False)

        with tarfile.open(archive, "w:gz") as packaged:
            link = tarfile.TarInfo("repeatcaller/unsafe-link")
            link.type = tarfile.SYMTYPE
            link.linkname = "../../outside"
            packaged.addfile(link)
        with self.assertRaisesRegex(package_release.PackageError, "link or special file"):
            package_release.inspect_archive(archive, expected, signed=False)

        with tarfile.open(archive, "w:gz") as packaged:
            unsafe_mode = tarfile.TarInfo("repeatcaller/Job.php")
            unsafe_mode.size = 1
            unsafe_mode.mode = 0o777
            import io
            packaged.addfile(unsafe_mode, io.BytesIO(b"x"))
        with self.assertRaisesRegex(package_release.PackageError, "permissions are unsafe"):
            package_release.inspect_archive(archive, expected, signed=False)

    def test_corrupt_archive_and_wrong_top_level_directory_are_rejected(self):
        manifest = self.prepare()
        expected = package_release._load_manifest(manifest)
        archive = self.root / "invalid.tgz"
        archive.write_bytes(b"not a gzip tar archive")
        with self.assertRaisesRegex(package_release.PackageError, "valid gzip tarball"):
            package_release.inspect_archive(archive, expected, signed=False)

        with tarfile.open(archive, "w:gz") as packaged:
            member = tarfile.TarInfo("other-module/Job.php")
            member.size = 1
            import io
            packaged.addfile(member, io.BytesIO(b"x"))
        with self.assertRaisesRegex(package_release.PackageError, "unsafe path"):
            package_release.inspect_archive(archive, expected, signed=False)


if __name__ == "__main__":
    unittest.main()
