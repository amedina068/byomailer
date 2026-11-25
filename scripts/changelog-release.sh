#!/usr/bin/env bash
#
# Moves the fragments in changelog.d/ into the [Unreleased] section of
# CHANGELOG.md, then removes the fragment files. See changelog.d/README.md.
set -euo pipefail

cd "$(dirname "$0")/.."

fragment_dir=changelog.d
changelog=CHANGELOG.md
sections="added changed deprecated removed fixed security"

shopt -s nullglob
fragments=()
for file in "$fragment_dir"/*.md; do
    [[ $(basename "$file") == README.md ]] && continue
    fragments+=("$file")
done

if (( ${#fragments[@]} == 0 )); then
    echo "No fragments in $fragment_dir/."
    exit 0
fi

grep -q '^## \[Unreleased\]' "$changelog" || {
    echo "$changelog has no '## [Unreleased]' heading." >&2
    exit 1
}

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

for file in "${fragments[@]}"; do
    name=$(basename "$file" .md)
    section=${name##*.}
    if [[ $name == "$section" || " $sections " != *" $section "* ]]; then
        echo "Bad fragment name: $file. Use <name>.<section>.md, with <section> one of: $sections." >&2
        exit 1
    fi
    # Remove the blank lines before and after the entry.
    awk 'NF { found = 1 } found' "$file" | tac | awk 'NF { found = 1 } found' | tac >> "$work/$section"
done

awk -v dir="$work" '
    BEGIN {
        count = split("Added Changed Deprecated Removed Fixed Security", order, " ")
        unreleased = 0
        current = ""
        blanks = 0
    }
    function pending(section) {
        return !(section in done) && system("test -s \"" dir "/" tolower(section) "\"") == 0
    }
    function emit(section,    path, line) {
        path = dir "/" tolower(section)
        while ((getline line < path) > 0) print line
        close(path)
        done[section] = 1
    }
    function close_section() {
        if (current != "" && pending(current)) {
            if (last ~ /^### /) print ""
            emit(current)
        }
        current = ""
    }
    function add_missing_sections(    i) {
        for (i = 1; i <= count; i++) {
            if (pending(order[i])) {
                print ""
                print "### " order[i]
                print ""
                emit(order[i])
            }
        }
    }
    /^$/ { blanks++; next }
    {
        if (unreleased && /^(## |### )/) close_section()
        if (unreleased && /^## /) { add_missing_sections(); unreleased = 0 }
        while (blanks > 0) { print ""; blanks-- }
        print
        last = $0
        if (/^## \[Unreleased\]/) unreleased = 1
        else if (unreleased && /^### /) current = substr($0, 5)
    }
    END {
        if (unreleased) { close_section(); add_missing_sections() }
        while (blanks > 0) { print ""; blanks-- }
    }
' "$changelog" > "$work/CHANGELOG.md"

cp "$work/CHANGELOG.md" "$changelog"
rm -- "${fragments[@]}"
echo "Moved ${#fragments[@]} fragments into $changelog."
