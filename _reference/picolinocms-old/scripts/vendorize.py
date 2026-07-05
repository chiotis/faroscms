#!/usr/bin/env python3
import json
import os
import re
import shutil
import sys
import tempfile
import time
import urllib.request
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
VENDOR_DIR = os.path.join(ROOT, "vendor")
PHP_VERSION = (8, 1, 0, 0)

ROOT_REQUIREMENTS = {
    "twig/twig": "^3.9",
    "league/commonmark": "^2.5",
    "symfony/yaml": "^6.4",
    "nette/utils": "^4.0",
}

SKIP_PREFIXES = ("ext-", "lib-")
SKIP_PACKAGES = {
    "php",
    "composer-plugin-api",
}


def fetch_json(url: str) -> dict:
    req = urllib.request.Request(url, headers={"User-Agent": "FlatCMS-Vendorize"})
    with urllib.request.urlopen(req) as resp:
        return json.loads(resp.read().decode("utf-8"))


def parse_semver(value: str):
    value = value.strip()
    value = value.lstrip("v")
    value = re.split(r"[-+]", value)[0]
    parts = [p for p in value.split(".") if p != ""]
    nums = []
    for part in parts:
        if part.isdigit():
            nums.append(int(part))
        else:
            nums.append(0)
    while len(nums) < 4:
        nums.append(0)
    return tuple(nums[:4])


def is_stable(version: str) -> bool:
    return not re.search(r"dev|alpha|beta|rc", version, re.IGNORECASE)


def cmp_tuple(a, b):
    return (a > b) - (a < b)


def satisfies_all(version_tuple, constraints):
    return all(satisfies_constraint(version_tuple, c) for c in constraints if c)


def satisfies_constraint(version_tuple, constraint: str) -> bool:
    constraint = constraint.strip()
    if constraint in ("", "*"):
        return True

    or_parts = re.split(r"\s*\|\|\s*", constraint)
    for part in or_parts:
        part = part.strip()
        if not part:
            continue
        if _satisfies_and(version_tuple, part):
            return True
    return False


def _satisfies_and(version_tuple, expr: str) -> bool:
    tokens = re.split(r"\s+|,", expr)
    tokens = [t for t in tokens if t]
    for token in tokens:
        if token.startswith("^"):
            if not _check_caret(version_tuple, token[1:]):
                return False
        elif token.startswith("~"):
            if not _check_tilde(version_tuple, token[1:]):
                return False
        elif token.startswith(">="):
            if cmp_tuple(version_tuple, parse_semver(token[2:])) < 0:
                return False
        elif token.startswith(">"):
            if cmp_tuple(version_tuple, parse_semver(token[1:])) <= 0:
                return False
        elif token.startswith("<="):
            if cmp_tuple(version_tuple, parse_semver(token[2:])) > 0:
                return False
        elif token.startswith("<"):
            if cmp_tuple(version_tuple, parse_semver(token[1:])) >= 0:
                return False
        elif token == "*":
            continue
        else:
            if cmp_tuple(version_tuple, parse_semver(token)) != 0:
                return False
    return True


def _check_caret(version_tuple, base: str) -> bool:
    base_tuple = parse_semver(base)
    major, minor, patch, build = base_tuple
    if major > 0:
        upper = (major + 1, 0, 0, 0)
    elif minor > 0:
        upper = (0, minor + 1, 0, 0)
    else:
        upper = (0, 0, patch + 1, 0)
    return cmp_tuple(version_tuple, base_tuple) >= 0 and cmp_tuple(version_tuple, upper) < 0


def _check_tilde(version_tuple, base: str) -> bool:
    base_tuple = parse_semver(base)
    parts = [p for p in re.split(r"[.-]", base) if p != ""]
    if len(parts) <= 1:
        upper = (base_tuple[0] + 1, 0, 0, 0)
    else:
        upper = (base_tuple[0], base_tuple[1] + 1, 0, 0)
    return cmp_tuple(version_tuple, base_tuple) >= 0 and cmp_tuple(version_tuple, upper) < 0


def pick_version(package: str, constraints: list) -> dict:
    data = fetch_json(f"https://repo.packagist.org/p2/{package}.json")
    versions = data.get("packages", {}).get(package, [])
    candidates = []
    for v in versions:
        version = v.get("version", "")
        if not is_stable(version):
            continue
        version_tuple = parse_semver(v.get("version_normalized", version))
        if not satisfies_all(version_tuple, constraints):
            continue
        php_constraint = v.get("require", {}).get("php")
        if php_constraint and not satisfies_constraint(PHP_VERSION, php_constraint):
            continue
        candidates.append((version_tuple, v))

    if not candidates:
        raise RuntimeError(f"No matching version for {package} with constraints {constraints}")

    candidates.sort(key=lambda x: x[0])
    return candidates[-1][1]


def should_skip(package: str) -> bool:
    if package in SKIP_PACKAGES:
        return True
    return any(package.startswith(prefix) for prefix in SKIP_PREFIXES)


def resolve_all():
    constraints = {}
    resolved = {}
    queue = list(ROOT_REQUIREMENTS.items())

    for pkg, con in queue:
        constraints.setdefault(pkg, []).append(con)

    while queue:
        package, constraint = queue.pop(0)
        if should_skip(package):
            continue
        constraints.setdefault(package, []).append(constraint)
        chosen = pick_version(package, constraints[package])
        version = chosen.get("version")
        if package in resolved and resolved[package]["version"] == version:
            continue
        resolved[package] = chosen

        requires = chosen.get("require", {})
        for dep, dep_constraint in requires.items():
            if should_skip(dep):
                continue
            if dep_constraint is None:
                dep_constraint = ""
            constraints.setdefault(dep, []).append(dep_constraint)
            queue.append((dep, dep_constraint))

    return resolved


def download_and_extract(package: str, info: dict, target_root: str):
    dist = info.get("dist", {})
    url = dist.get("url")
    if not url:
        raise RuntimeError(f"No dist URL for {package}")
    vendor, name = package.split("/")
    target_dir = os.path.join(target_root, vendor, name)

    os.makedirs(target_dir, exist_ok=True)

    with tempfile.TemporaryDirectory() as tmpdir:
        archive_path = os.path.join(tmpdir, f"{vendor}-{name}.zip")
        print(f"Downloading {package} {info.get('version')}...")
        urllib.request.urlretrieve(url, archive_path)

        with zipfile.ZipFile(archive_path) as zf:
            zf.extractall(tmpdir)

        entries = [e for e in os.listdir(tmpdir) if os.path.isdir(os.path.join(tmpdir, e))]
        src_dir = None
        for entry in entries:
            if entry.startswith(f"{vendor}-{name}") or entry.startswith(name.replace('/', '-')):
                src_dir = os.path.join(tmpdir, entry)
                break
        if src_dir is None and entries:
            src_dir = os.path.join(tmpdir, entries[0])

        if src_dir is None:
            raise RuntimeError(f"Could not locate extracted directory for {package}")

        if os.path.exists(target_dir):
            shutil.rmtree(target_dir)
        shutil.copytree(src_dir, target_dir)


def extract_class_name(path: str):
    try:
        with open(path, "r", encoding="utf-8", errors="ignore") as handle:
            data = handle.read(20000)
    except OSError:
        return None

    namespace_match = re.search(r"^\s*namespace\s+([^;]+);", data, re.MULTILINE)
    namespace = namespace_match.group(1).strip() if namespace_match else ""
    class_match = re.search(r"^\s*(?:abstract\s+|final\s+)?(class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)", data, re.MULTILINE)
    if not class_match:
        return None
    name = class_match.group(2)
    if namespace:
        return namespace + "\\" + name
    return name


def generate_autoload(packages: dict, target_root: str):
    psr4 = {}
    files = []
    classmap_files = []

    for package in packages:
        vendor, name = package.split("/")
        package_dir = os.path.join(target_root, vendor, name)
        composer_path = os.path.join(package_dir, "composer.json")
        if not os.path.exists(composer_path):
            continue
        with open(composer_path, "r", encoding="utf-8") as handle:
            data = json.load(handle)

        autoload = data.get("autoload", {})
        for prefix, paths in autoload.get("psr-4", {}).items():
            if isinstance(paths, str):
                paths = [paths]
            for path in paths:
                base = os.path.join(package_dir, path)
                if not base.endswith(os.sep):
                    base += os.sep
                psr4.setdefault(prefix, []).append(base)

        for file_path in autoload.get("files", []) or []:
            files.append(os.path.join(package_dir, file_path))

        for classmap_path in autoload.get("classmap", []) or []:
            full_path = os.path.join(package_dir, classmap_path)
            if os.path.isdir(full_path):
                for root, _, filenames in os.walk(full_path):
                    for filename in filenames:
                        if filename.endswith(".php"):
                            classmap_files.append(os.path.join(root, filename))
            elif os.path.isfile(full_path):
                classmap_files.append(full_path)

    psr4["FlatCMS\\"] = [os.path.join(ROOT, "src") + os.sep]

    autoload_path = os.path.join(target_root, "autoload.php")
    with open(autoload_path, "w", encoding="utf-8") as handle:
        handle.write("<?php\n")
        handle.write("$baseDir = __DIR__;\n")
        handle.write("$files = [\n")
        for file in files:
            rel = os.path.relpath(file, target_root).replace(os.sep, "/")
            handle.write("    $baseDir . '/" + rel + "',\n")
        handle.write("];\n")
        unique_classmap = sorted(set(classmap_files))
        classmap = {}
        for file in unique_classmap:
            class_name = extract_class_name(file)
            if class_name and class_name not in classmap:
                classmap[class_name] = file
        handle.write("foreach ($files as $file) { if (is_file($file)) { require_once $file; } }\n")
        handle.write("$classmap = [\n")
        for class_name, file in sorted(classmap.items()):
            rel = os.path.relpath(file, target_root).replace(os.sep, "/")
            handle.write("    " + repr(class_name) + " => $baseDir . '/" + rel + "',\n")
        handle.write("];\n")
        handle.write("$prefixes = [\n")
        for prefix, dirs in psr4.items():
            handle.write("    " + repr(prefix) + " => [\n")
            for d in dirs:
                rel = os.path.relpath(d, target_root).replace(os.sep, "/")
                if not rel.endswith("/"):
                    rel += "/"
                handle.write("        $baseDir . '/" + rel + "',\n")
            handle.write("    ],\n")
        handle.write("];\n")
        handle.write("spl_autoload_register(function ($class) use ($classmap, $prefixes) {\n")
        handle.write("    if (isset($classmap[$class])) { $file = $classmap[$class]; if (is_file($file)) { require $file; return true; } }\n")
        handle.write("    foreach ($prefixes as $prefix => $dirs) {\n")
        handle.write("        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {\n")
        handle.write("            continue;\n")
        handle.write("        }\n")
        handle.write("        $relative = substr($class, strlen($prefix));\n")
        handle.write("        $relativePath = str_replace('\\\\', '/', $relative) . '.php';\n")
        handle.write("        foreach ($dirs as $dir) {\n")
        handle.write("            $file = $dir . $relativePath;\n")
        handle.write("            if (is_file($file)) { require $file; return true; }\n")
        handle.write("        }\n")
        handle.write("    }\n")
        handle.write("    return false;\n")
        handle.write("});\n")


def backup_existing_vendor(target_root: str):
    if not os.path.isdir(target_root):
        return
    if not os.listdir(target_root):
        return
    stamp = time.strftime("%Y%m%d-%H%M%S")
    backup = target_root + ".backup-" + stamp
    print(f"Existing vendor directory found. Moving to {backup}")
    shutil.move(target_root, backup)


def main():
    print("Resolving dependencies...")
    packages = resolve_all()

    backup_existing_vendor(VENDOR_DIR)
    os.makedirs(VENDOR_DIR, exist_ok=True)

    for package, info in packages.items():
        download_and_extract(package, info, VENDOR_DIR)

    generate_autoload(packages, VENDOR_DIR)
    print("Vendor directory generated at:", VENDOR_DIR)


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("Error:", exc)
        sys.exit(1)
